<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\IntegrationRun;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

it('no permite quitarse a sí mismo el último rol administrador', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->put("/usuarios/{$admin->id}", ['status' => 'active', 'roles' => ['Recursos Humanos'], 'access_all_branches' => true])
        ->assertSessionHasErrors('roles');

    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    expect($admin->fresh()->hasRole('Administrador de empresa'))->toBeTrue();

    // Tampoco quitando el permiso al propio rol.
    $role = Role::query()->where('organization_id', $org->id)->where('name', 'Administrador de empresa')->firstOrFail();
    $this->actingAs($admin)->put("/usuarios/roles/{$role->id}", ['name' => $role->name, 'permissions' => ['dashboard.view']])
        ->assertSessionHasErrors('roles');
});

it('invita usuarios con roles y alcance por sucursal', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $branch = Branch::query()->create(['code' => 'SUR', 'name' => 'Sur']);

    $this->actingAs($admin)->post('/usuarios', [
        'name' => 'Gerente Sur', 'email' => 'gerente.sur@example.com', 'mode' => 'password',
        'roles' => ['Gerente de sucursal'], 'access_all_branches' => false, 'branch_ids' => [$branch->id],
    ])->assertSessionHasNoErrors()->assertSessionHas('temporary_password');

    $user = User::query()->where('email', 'gerente.sur@example.com')->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    expect($user->hasRole('Gerente de sucursal'))->toBeTrue()
        ->and($user->branches()->pluck('branches.id')->all())->toBe([$branch->id]);

    $this->actingAs($admin)->get('/usuarios')->assertOk();
    $this->actingAs($admin)->post("/usuarios/{$user->id}/desactivar")->assertSessionHasNoErrors();
    $this->assertDatabaseHas('organization_user', ['user_id' => $user->id, 'organization_id' => $org->id, 'status' => 'inactive']);
});

it('crea acceso de sólo lectura para la autoridad laboral', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/usuarios/autoridad', ['name' => 'Inspección STPS', 'email' => 'inspector@example.com', 'mode' => 'reset', 'expires_on' => now()->addDays(10)->toDateString()])
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'inspector@example.com')->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    expect($user->hasRole('Autoridad laboral'))->toBeTrue()
        ->and($user->can('employees.update'))->toBeFalse()
        ->and($user->can('punches.view'))->toBeTrue();
});

it('impide que un usuario sin users.roles otorgue roles superiores', function () {
    $org = $this->organization();
    $rh = $this->userIn($org, 'Recursos Humanos', allBranches: true);

    $this->actingAs($rh)->post('/usuarios', [
        'name' => 'Escalada', 'email' => 'escalada@example.com', 'mode' => 'reset',
        'roles' => ['Administrador de empresa'], 'access_all_branches' => true,
    ])->assertSessionHasErrors('roles');

    $this->actingAs($rh)->post('/usuarios/roles', ['name' => 'Nuevo', 'permissions' => []])->assertForbidden();
});

it('administra roles con matriz de permisos y los desactiva sin borrarlos', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/usuarios/roles', ['name' => 'Capturista', 'permissions' => ['employees.view', 'employees.create', 'no.existe']])
        ->assertSessionHasErrors('permissions.2');
    $this->actingAs($admin)->post('/usuarios/roles', ['name' => 'Capturista', 'permissions' => ['employees.view', 'employees.create']])
        ->assertSessionHasNoErrors();

    $role = Role::query()->where('organization_id', $org->id)->where('name', 'Capturista')->firstOrFail();
    expect($role->permissions()->pluck('name')->sort()->values()->all())->toBe(['employees.create', 'employees.view']);

    $this->actingAs($admin)->get('/usuarios/roles')->assertOk();
    $this->actingAs($admin)->post("/usuarios/roles/{$role->id}/desactivar")->assertSessionHasNoErrors();
    expect(Role::withTrashed()->find($role->id)->trashed())->toBeTrue();
});

it('importa colaboradores desde CSV detectando duplicados y registra la ejecución', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    Branch::query()->create(['code' => 'MTY', 'name' => 'Monterrey']);
    $existing = $this->employee($org);

    $csv = implode("\n", [
        'numero_empleado,nombre,apellido_paterno,apellido_materno,correo,fecha_ingreso,sucursal,id_externo',
        '1001,Mario,López,Díaz,mario@example.com,2026-10-01,MTY,P-1',
        '1002,Sofía,Ramos,,sofia@example.com,01/10/2026,MTY,P-2',
        '1001,Mario,Duplicado,,,2026-10-01,MTY,P-3',
        "{$existing->employee_number},Ana,Prueba,,,2026-10-01,MTY,",
        '1003,Pedro,Sin Sucursal,,,2026-10-01,NOEXISTE,P-4',
        '1004,Fecha,Mala,,,31/31/2026,MTY,P-5',
    ]);

    // Simulación: nada se escribe.
    $this->actingAs($admin)->post('/colaboradores/importar', ['file' => UploadedFile::fake()->createWithContent('personal.csv', $csv), 'dry_run' => true])->assertRedirect();
    expect(Employee::query()->whereIn('employee_number', ['1001', '1002'])->count())->toBe(0)
        ->and(IntegrationRun::query()->latest('id')->first()->status)->toBe('simulated');

    $this->actingAs($admin)->post('/colaboradores/importar', ['file' => UploadedFile::fake()->createWithContent('personal.csv', $csv)])->assertRedirect();

    $run = IntegrationRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('partial')
        ->and($run->stats['created'])->toBe(2)
        ->and($run->stats['conflicts'])->toBe(4)
        ->and(collect($run->conflicts)->pluck('type')->sort()->values()->all())->toBe(['duplicate', 'duplicate_in_file', 'invalid', 'unknown_reference']);

    $mario = Employee::query()->where('employee_number', '1001')->firstOrFail();
    expect($mario->external_source)->toBe('people')->and($mario->external_id)->toBe('P-1')
        ->and($mario->employments()->count())->toBe(1);

    // Re-importar vincula por id externo y actualiza en lugar de duplicar.
    $update = "numero_empleado,nombre,apellido_paterno,correo,fecha_ingreso,id_externo\n1001,Mario,López,mario.nuevo@example.com,2026-10-01,P-1";
    $this->actingAs($admin)->post('/colaboradores/importar', ['file' => UploadedFile::fake()->createWithContent('personal.csv', $update)]);
    $run = IntegrationRun::query()->latest('id')->firstOrFail();
    expect($run->stats['updated'])->toBe(1)
        ->and(Employee::query()->where('employee_number', '1001')->count())->toBe(1)
        ->and($mario->fresh()->email)->toBe('mario.nuevo@example.com');
});

it('niega la importación sin permiso', function () {
    $org = $this->organization();
    $user = $this->userIn($org, 'Gerente de sucursal');

    $this->actingAs($user)->get('/colaboradores/importar')->assertForbidden();
});
