<?php

use App\Domain\Security\PermissionCatalog;
use App\Domain\Security\RoleStructure;
use App\Domain\Security\UserAccessService;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ComplianceAlert;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessReview;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();

    // Usuario con un único rol exacto (sin los alias de pruebas), con doble factor configurado.
    $this->only = function ($org, string $role, bool $allBranches = false): User {
        $user = $this->userIn($org, PermissionCatalog::ROLE_EMPLOYEE, $allBranches);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->syncRoles([$role]);
        $user->forceFill(['two_factor_secret' => encrypt('TESTSECRET'), 'two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    };
});

it('crea los roles mínimos y el administrador de plataforma no tiene permisos operativos', function () {
    $org = $this->organization();
    $names = Role::query()->where('organization_id', $org->id)->pluck('name')->all();

    expect($names)->toContain(
        PermissionCatalog::ROLE_PLATFORM_ADMIN, PermissionCatalog::ROLE_HR_ADMIN, PermissionCatalog::ROLE_DIRECTION,
        PermissionCatalog::ROLE_REGIONAL, PermissionCatalog::ROLE_BRANCH_MANAGER, PermissionCatalog::ROLE_COMPTROLLER,
        PermissionCatalog::ROLE_AUTHORITY, PermissionCatalog::ROLE_EMPLOYEE,
    );

    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    foreach (PermissionCatalog::OPERATIONAL as $permission) {
        expect($platform->can($permission))->toBeFalse();
    }
    expect($platform->can('users.manage'))->toBeTrue()->and($platform->can('integrations.sync'))->toBeTrue();

    $hr = ($this->only)($org, PermissionCatalog::ROLE_HR_ADMIN, true);
    expect($hr->can('rectifications.validate'))->toBeTrue()
        ->and($hr->can('settings.legal_manage'))->toBeTrue()
        ->and($hr->can('users.manage'))->toBeFalse();
});

it('migra los roles anteriores sin perder asignaciones y retira lo operativo a Sistemas', function () {
    $org = $this->organization();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    // Simula una instalación anterior: los roles mínimos aún no existen y el rol comodín sí.
    Role::query()->where('organization_id', $org->id)->whereIn('name', [PermissionCatalog::ROLE_PLATFORM_ADMIN, PermissionCatalog::ROLE_HR_ADMIN])->get()
        ->each(fn (Role $r) => $r->forceFill(['name' => $r->name === PermissionCatalog::ROLE_PLATFORM_ADMIN ? 'Administrador de empresa' : 'Recursos Humanos'])->save());
    $legacy = Role::query()->where('organization_id', $org->id)->where('name', 'Administrador de empresa')->firstOrFail();
    $legacy->syncPermissions(PermissionCatalog::all());
    $user = $this->userIn($org, PermissionCatalog::ROLE_EMPLOYEE);
    $user->syncRoles(['Administrador de empresa']);

    $changes = app(RoleStructure::class)->apply($org);
    expect($changes)->not->toBeEmpty();

    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = $user->fresh();
    expect($user->hasRole(PermissionCatalog::ROLE_PLATFORM_ADMIN))->toBeTrue()
        ->and($user->can('users.roles'))->toBeTrue()
        ->and($user->can('rectifications.validate'))->toBeFalse()
        ->and($user->can('overtime.approve'))->toBeFalse()
        ->and(Role::query()->where('organization_id', $org->id)->where('name', PermissionCatalog::ROLE_HR_ADMIN)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'role.renamed')->exists())->toBeTrue();

    // Idempotente.
    expect(app(RoleStructure::class)->apply($org))->toBe([]);
});

it('el superadministrador no obtiene autorización operativa', function () {
    $org = $this->organization();
    $root = $this->userIn($org);
    $root->forceFill(['is_super_admin' => true])->save();

    expect($root->fresh()->can('users.manage'))->toBeTrue()
        ->and($root->fresh()->can('rectifications.approve'))->toBeFalse()
        ->and($root->fresh()->can('rectifications.validate'))->toBeFalse()
        ->and($root->fresh()->can('overtime.approve'))->toBeFalse();
});

it('aplica separación de funciones al asignar roles y al editar permisos', function () {
    $org = $this->organization();
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    $other = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);

    // Sistemas no puede quedar también como RH (ni a sí mismo ni a otro).
    $this->actingAs($platform)->put("/usuarios/{$other->id}", [
        'status' => 'active', 'roles' => [PermissionCatalog::ROLE_PLATFORM_ADMIN, PermissionCatalog::ROLE_HR_ADMIN], 'access_all_branches' => true,
    ])->assertSessionHasErrors('roles');

    // Un rol no puede combinar administración de usuarios con autorizaciones.
    $this->actingAs($platform)->post('/usuarios/roles', ['name' => 'Mixto', 'permissions' => ['users.manage', 'rectifications.approve']])
        ->assertSessionHasErrors('permissions');

    $role = Role::query()->where('organization_id', $org->id)->where('name', PermissionCatalog::ROLE_PLATFORM_ADMIN)->firstOrFail();
    $this->actingAs($platform)->put("/usuarios/roles/{$role->id}", ['name' => $role->name, 'permissions' => [...$role->permissions->pluck('name')->all(), 'overtime.approve']])
        ->assertSessionHasErrors('permissions');

    // Roles de consulta: sólo lectura.
    $authority = Role::query()->where('organization_id', $org->id)->where('name', PermissionCatalog::ROLE_AUTHORITY)->firstOrFail();
    $this->actingAs($platform)->put("/usuarios/roles/{$authority->id}", ['name' => $authority->name, 'permissions' => ['punches.view', 'employees.update']])
        ->assertSessionHasErrors('permissions');
});

it('exige alcance por sucursal para gerentes y regionales', function () {
    $org = $this->organization();
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    $branch = Branch::query()->create(['code' => 'SUR', 'name' => 'Sur']);

    foreach ([PermissionCatalog::ROLE_BRANCH_MANAGER, PermissionCatalog::ROLE_REGIONAL] as $i => $role) {
        $payload = ['name' => "Usuario {$i}", 'email' => "alcance{$i}@example.com", 'mode' => 'reset', 'roles' => [$role]];
        $this->actingAs($platform)->post('/usuarios', [...$payload, 'access_all_branches' => true])->assertSessionHasErrors('branch_ids');
        $this->actingAs($platform)->post('/usuarios', [...$payload, 'access_all_branches' => false, 'branch_ids' => []])->assertSessionHasErrors('branch_ids');
        $this->actingAs($platform)->post('/usuarios', [...$payload, 'access_all_branches' => false, 'branch_ids' => [$branch->id]])->assertSessionHasNoErrors();
    }
});

it('impide escalar privilegios a quien administra usuarios sin administrar roles', function () {
    $org = $this->organization();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $limited = Role::query()->create(['name' => 'Mesa de ayuda', 'guard_name' => 'web', 'organization_id' => $org->id]);
    $limited->syncPermissions(['users.view', 'users.manage', 'employees.view']);
    $helpdesk = ($this->only)($org, 'Mesa de ayuda', true);
    $admin = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);

    $this->actingAs($helpdesk)->post('/usuarios', [
        'name' => 'Escala', 'email' => 'escala@example.com', 'mode' => 'reset',
        'roles' => [PermissionCatalog::ROLE_HR_ADMIN], 'access_all_branches' => true,
    ])->assertSessionHasErrors('roles');

    // Tampoco puede modificar, desactivar ni reactivar a alguien con más permisos.
    $this->actingAs($helpdesk)->put("/usuarios/{$admin->id}", ['status' => 'active', 'roles' => [], 'access_all_branches' => true])->assertSessionHasErrors('roles');
    $this->actingAs($helpdesk)->post("/usuarios/{$admin->id}/desactivar")->assertSessionHasErrors();
    $this->actingAs($helpdesk)->post('/usuarios/roles', ['name' => 'Nuevo', 'permissions' => []])->assertForbidden();
});

it('bloquea URLs directas sin permiso y aísla usuarios entre organizaciones (IDOR)', function () {
    $org = $this->organization();
    $collaborator = $this->userIn($org, PermissionCatalog::ROLE_EMPLOYEE);
    $comptroller = ($this->only)($org, PermissionCatalog::ROLE_COMPTROLLER, true);
    $manager = ($this->only)($org, PermissionCatalog::ROLE_BRANCH_MANAGER);

    $this->actingAs($collaborator)->get('/usuarios')->assertForbidden();
    $this->actingAs($collaborator)->get('/usuarios/revision')->assertForbidden();
    $this->actingAs($comptroller)->post('/usuarios', ['name' => 'X', 'email' => 'x@example.com', 'mode' => 'reset', 'roles' => [], 'access_all_branches' => true])->assertForbidden();
    $this->actingAs($comptroller)->post('/usuarios/revision/abrir')->assertForbidden();
    $this->actingAs($manager)->get('/usuarios/roles')->assertForbidden();
    $this->actingAs($manager)->get('/integraciones')->assertForbidden();

    $orgB = $this->organization('Empresa B');
    $foreign = $this->userIn($orgB, PermissionCatalog::ROLE_EMPLOYEE);
    $reviewB = app(UserAccessService::class)->openQuarterlyReview($orgB);

    $this->actingInOrganization($org);
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    $this->actingAs($platform)->put("/usuarios/{$foreign->id}", ['status' => 'inactive', 'roles' => [], 'access_all_branches' => true])->assertNotFound();
    $this->actingAs($platform)->post("/usuarios/{$foreign->id}/desactivar")->assertNotFound();
    $this->actingAs($platform)->post("/usuarios/{$foreign->id}/autoridad/revocar", ['reason' => 'Prueba IDOR'])->assertNotFound();
    $this->actingAs($platform)->post("/usuarios/revision/{$reviewB->id}/cerrar")->assertNotFound();
    $this->assertDatabaseHas('organization_user', ['user_id' => $foreign->id, 'organization_id' => $orgB->id, 'status' => 'active']);
});

it('impide al gerente ver o actuar sobre personal de otra sucursal', function () {
    $org = $this->organization();
    $north = Branch::query()->create(['code' => 'NTE', 'name' => 'Norte']);
    $south = Branch::query()->create(['code' => 'SUR', 'name' => 'Sur']);
    $mine = $this->employee($org, branch: $north);
    $theirs = $this->employee($org, branch: $south);
    $manager = ($this->only)($org, PermissionCatalog::ROLE_BRANCH_MANAGER);
    $manager->branches()->attach($north->id, ['organization_id' => $org->id]);

    $this->actingAs($manager)->get("/colaboradores/{$mine->id}")->assertOk();
    $this->actingAs($manager)->get("/colaboradores/{$theirs->id}")->assertForbidden();
    $this->actingAs($manager)->post("/colaboradores/{$theirs->id}/baja", ['ends_on' => now()->toDateString(), 'reason' => 'Prueba'])->assertForbidden();
});

it('otorga a la autoridad laboral acceso de sólo lectura con vigencia obligatoria y revocación', function () {
    $org = $this->organization();
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);

    // Sin vigencia, o por el flujo general de usuarios: rechazado.
    $this->actingAs($platform)->post('/usuarios/autoridad', ['name' => 'STPS', 'email' => 'stps@example.com', 'mode' => 'reset'])
        ->assertSessionHasErrors('expires_on');
    $this->actingAs($platform)->post('/usuarios', [
        'name' => 'STPS', 'email' => 'stps@example.com', 'mode' => 'reset', 'roles' => [PermissionCatalog::ROLE_AUTHORITY], 'access_all_branches' => true,
    ])->assertSessionHasErrors('roles');

    $this->actingAs($platform)->post('/usuarios/autoridad', ['name' => 'STPS', 'email' => 'stps@example.com', 'mode' => 'reset', 'expires_on' => now()->addDays(5)->toDateString()])
        ->assertSessionHasNoErrors();
    $inspector = User::query()->where('email', 'stps@example.com')->firstOrFail();
    $inspector->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($inspector)->get('/usuarios')->assertForbidden();
    $this->actingAs($inspector)->post('/usuarios/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();

    // Revocación inmediata.
    $this->actingAs($platform)->post("/usuarios/{$inspector->id}/autoridad/revocar", ['reason' => 'Fin de inspección'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('organization_user', ['user_id' => $inspector->id, 'organization_id' => $org->id, 'status' => 'inactive']);
    expect(AuditLog::query()->where('action', 'user.authority_access_revoked')->exists())->toBeTrue();

    // Reactivar sin nueva vigencia: rechazado; con nueva vigencia: renovado.
    $this->actingAs($platform)->post("/usuarios/{$inspector->id}/reactivar")->assertSessionHasErrors('user');
    $this->actingAs($platform)->post('/usuarios/autoridad', ['name' => 'STPS', 'email' => 'stps@example.com', 'mode' => 'reset', 'expires_on' => now()->addDays(3)->toDateString()])
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('organization_user', ['user_id' => $inspector->id, 'organization_id' => $org->id, 'status' => 'active']);
});

it('desactiva accesos de autoridad vencidos y abre la revisión trimestral con alerta', function () {
    $org = $this->organization();
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    $this->actingAs($platform)->post('/usuarios/autoridad', ['name' => 'STPS', 'email' => 'vence@example.com', 'mode' => 'reset', 'expires_on' => now()->addDay()->toDateString()])
        ->assertSessionHasNoErrors();
    $inspector = User::query()->where('email', 'vence@example.com')->firstOrFail();

    $this->travel(3)->days();
    $this->artisan('asistencias:revision-usuarios')->assertSuccessful();
    $this->artisan('asistencias:revision-usuarios')->assertSuccessful();

    $this->assertDatabaseHas('organization_user', ['user_id' => $inspector->id, 'organization_id' => $org->id, 'status' => 'inactive']);
    $this->actingInOrganization($org);
    expect(UserAccessReview::query()->count())->toBe(1)
        ->and(ComplianceAlert::query()->where('rule_code', 'user_access_review')->count())->toBe(1);
});

it('registra decisiones de la revisión trimestral y la cierra', function () {
    $org = $this->organization();
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    $idle = $this->userIn($org, PermissionCatalog::ROLE_EMPLOYEE);
    $review = app(UserAccessService::class)->openQuarterlyReview($org);

    $page = $this->actingAs($platform)->get('/usuarios/revision')->assertOk()
        ->assertInertia(fn ($p) => $p->component('usuarios/Revision')->where('review.status', 'open'));
    $row = collect($page->viewData('page')['props']['users'])->firstWhere('id', $idle->id);
    expect($row['flags'])->toContain('inactive');

    // No puede desactivarse a sí mismo.
    $this->actingAs($platform)->post("/usuarios/revision/{$review->id}/usuarios/{$platform->id}", ['decision' => 'deactivate'])->assertSessionHasErrors('decision');
    $this->actingAs($platform)->post("/usuarios/revision/{$review->id}/usuarios/{$idle->id}", ['decision' => 'deactivate', 'note' => 'Sin uso'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('organization_user', ['user_id' => $idle->id, 'organization_id' => $org->id, 'status' => 'inactive']);

    $this->actingAs($platform)->post("/usuarios/revision/{$review->id}/cerrar")->assertSessionHasNoErrors();
    $review->refresh();
    expect($review->status)->toBe('completed')
        ->and($review->summary['deactivated'])->toBe(1)
        ->and(ComplianceAlert::query()->where('rule_code', 'user_access_review')->value('status'))->toBe('resolved');
});

it('audita cambios de permisos y accesos con valor anterior y nuevo', function () {
    $org = $this->organization();
    $platform = ($this->only)($org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);
    $target = $this->userIn($org, PermissionCatalog::ROLE_EMPLOYEE);

    $this->actingAs($platform)->put("/usuarios/{$target->id}", [
        'status' => 'active', 'roles' => [PermissionCatalog::ROLE_COMPTROLLER], 'access_all_branches' => true,
    ])->assertSessionHasNoErrors();
    $log = AuditLog::query()->where('action', 'user.access_updated')->latest('id')->firstOrFail();
    expect($log->context['before']['roles'])->toBe([PermissionCatalog::ROLE_EMPLOYEE])
        ->and($log->context['after']['roles'])->toBe([PermissionCatalog::ROLE_COMPTROLLER]);

    $this->actingAs($platform)->post('/usuarios/roles', ['name' => 'Capturista', 'permissions' => ['employees.view']])->assertSessionHasNoErrors();
    $role = Role::query()->where('organization_id', $org->id)->where('name', 'Capturista')->firstOrFail();
    $this->actingAs($platform)->put("/usuarios/roles/{$role->id}", ['name' => 'Capturista', 'permissions' => ['employees.view', 'employees.create']])->assertSessionHasNoErrors();
    $log = AuditLog::query()->where('action', 'role.updated')->latest('id')->firstOrFail();
    expect($log->context['before']['permissions'])->toBe(['employees.view'])
        ->and($log->context['after']['permissions'])->toBe(['employees.view', 'employees.create']);
});
