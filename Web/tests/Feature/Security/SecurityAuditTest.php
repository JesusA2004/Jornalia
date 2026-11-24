<?php

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Attendance\AttendanceException;
use App\Domain\Scheduling\ScheduleChangeService;
use App\Domain\Security\DeviceService;
use App\Domain\Security\PermissionCatalog;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Signatures\SignaturePinService;
use App\Domain\Vacations\VacationService;
use App\Enums\ApprovalStatus;
use App\Enums\DeviceStatus;
use App\Models\AttendancePolicy;
use App\Models\Device;
use App\Models\ElectronicSignature;
use App\Models\FaceVerification;
use App\Models\PolicyDocument;
use App\Models\Punch;
use App\Models\Role;
use App\Models\ScheduleException;
use App\Models\VacationRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Api\Concerns\MobileApi;

/*
| Auditoría de seguridad P2 (docs/seguridad/AUDITORIA_SEGURIDAD.md): una prueba por
| hallazgo corregido.
*/

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

it('H1 · el superadministrador no autoriza dispositivos a través de la habilidad de policy', function () {
    $pending = Device::query()->create([
        'user_id' => $this->user->id, 'employee_id' => $this->emp->id, 'device_uuid' => (string) Str::uuid(),
        'name' => 'Teléfono nuevo', 'platform' => 'android', 'app_version' => '1.0.0', 'secret' => str_repeat('a', 64),
        'status' => DeviceStatus::Pending, 'pending_reason' => 'device_change',
    ]);
    $root = $this->userIn($this->org, null, allBranches: true);
    $root->forceFill(['is_super_admin' => true, 'two_factor_secret' => encrypt('TESTSECRET'), 'two_factor_confirmed_at' => now()])->save();
    $root = $root->fresh();

    // `approve` no es un permiso del catálogo: antes Gate::before lo concedía sin pasar por la policy.
    expect($root->can('approve', $pending))->toBeFalse()
        ->and($root->can('revoke', $pending))->toBeTrue()      // devices.revoke no es operativo
        ->and($root->can('devices.view'))->toBeTrue()
        ->and($root->can('devices.approve'))->toBeFalse();

    $this->actingAs($root)->post("/dispositivos/{$pending->id}/autorizar")->assertForbidden();
    expect($pending->fresh()->status)->toBe(DeviceStatus::Pending);
});

it('H2 · quien captura una solicitud de vacaciones a nombre de otro no puede aprobarla', function () {
    $hr = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $otherHr = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $vacation = VacationRequest::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->emp->id,
        'employment_id' => $this->emp->employments()->value('id'), 'service_year' => 3,
        'starts_on' => '2026-12-07', 'ends_on' => '2026-12-08', 'days' => 2,
        'status' => ApprovalStatus::Pending, 'requested_by' => $hr->id,
    ]);
    $workflow = app(ApprovalWorkflow::class);

    expect($workflow->canDecide($hr, $vacation, 'vacation'))->toBeFalse()
        ->and($workflow->canDecide($otherHr, $vacation, 'vacation'))->toBeTrue();
    expect(fn () => app(VacationService::class)->decide($hr, $vacation, true, null))->toThrow(AuthorizationException::class);
    expect($vacation->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('H3 · una verificación facial ajena no se consume ni queda como evidencia del marcaje', function () {
    $other = $this->employee($this->org);
    $foreign = FaceVerification::query()->create([
        'uuid' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'employee_id' => $other->id,
        'provider' => 'test', 'result' => 'match', 'expires_at' => now()->addMinutes(2),
    ]);

    $payload = $this->signedPunch('clock_in', $this->challenge(), ['face_verification_id' => $foreign->uuid]);
    $uuid = $this->mobile('POST', '/attendance/punches', $payload)->assertCreated()->json('data.punch.uuid');

    expect(Punch::query()->where('uuid', $uuid)->value('face_verification_id'))->toBeNull()
        ->and($foreign->fresh()->consumed_at)->toBeNull();
});

it('H3 · con verificación facial obligatoria, una verificación sólo autoriza un marcaje', function () {
    AttendancePolicy::query()->where('is_default', true)->update(['face_verification_required' => true]);
    $own = FaceVerification::query()->create([
        'uuid' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'employee_id' => $this->emp->id,
        'provider' => 'test', 'result' => 'match', 'expires_at' => now()->addMinutes(2),
    ]);

    $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge(), ['face_verification_id' => $own->uuid]))->assertCreated();
    expect($own->fresh()->consumed_at)->not->toBeNull();

    $this->assertApiError(
        $this->mobile('POST', '/attendance/punches', $this->signedPunch('break_start', $this->challenge(), ['face_verification_id' => $own->uuid])),
        422, 'policy_violation',
    );
    expect(Punch::query()->where('employee_id', $this->emp->id)->count())->toBe(1);
});

it('H4 · firmar el convenio dos veces deja una sola firma y los NIP fallidos sí cuentan', function () {
    $body = "Convenio de registro electrónico\n\nTexto.";
    $doc = PolicyDocument::query()->create([
        'code' => RegistrationDocuments::AGREEMENT, 'title' => 'Convenio de registro electrónico', 'version' => '1.0',
        'body_markdown' => $body, 'sha256' => hash('sha256', $body), 'requires_acknowledgement' => true, 'published_at' => now()->subMinute(),
    ]);
    app(SignaturePinService::class)->set($this->user, '4826');
    $documents = app(RegistrationDocuments::class);

    for ($i = 0; $i < SignaturePinService::MAX_ATTEMPTS; $i++) {
        try {
            $documents->sign($this->user, $this->emp, $doc, ElectronicSignature::METHOD_PIN, '9137', null);
        } catch (ValidationException) {
            // NIP incorrecto o bloqueado.
        }
    }
    // El intento fallido se registra fuera de la transacción de la firma: el NIP queda bloqueado.
    expect(app(SignaturePinService::class)->isLocked($this->user->fresh()))->toBeTrue()
        ->and(ElectronicSignature::query()->count())->toBe(0);

    $this->user->forceFill(['signature_pin_locked_until' => null, 'signature_pin_failed_attempts' => 0])->save();
    $first = $documents->sign($this->user->fresh(), $this->emp, $doc, ElectronicSignature::METHOD_PIN, '4826', null);
    $again = $documents->sign($this->user->fresh(), $this->emp, $doc, ElectronicSignature::METHOD_PIN, '4826', null);

    expect($again->id)->toBe($first->id)
        ->and(ElectronicSignature::query()->where('signable_id', $doc->id)->count())->toBe(1);
});

it('M1 · el superadministrador no obtiene permisos operativos ni con un rol asignado', function () {
    $root = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $root->forceFill(['is_super_admin' => true])->save();
    $root = $root->fresh();

    expect($root->can('rectifications.validate'))->toBeFalse()
        ->and($root->can('rectifications.approve'))->toBeFalse()
        ->and($root->can('overtime.approve'))->toBeFalse()
        ->and($root->can('employees.update'))->toBeTrue()
        ->and($root->can('users.manage'))->toBeTrue();
});

it('M2 · desde el expediente no se otorgan roles superiores sin administrar roles', function () {
    $role = Role::query()->create(['name' => 'Altas de personal', 'guard_name' => 'web', 'organization_id' => $this->org->id]);
    $role->givePermissionTo(['employees.view', 'employees.update', 'users.manage']);
    $actor = $this->userIn($this->org, null, allBranches: true);
    $actor->assignRole($role);
    $actor->forceFill(['two_factor_secret' => encrypt('TESTSECRET'), 'two_factor_confirmed_at' => now()])->save();

    $employee = $this->employee($this->org);
    $employee->forceFill(['user_id' => null])->save();

    $this->actingAs($actor->fresh())->post("/colaboradores/{$employee->id}/cuenta", [
        'email' => 'nuevo.rh@example.test', 'mode' => 'reset', 'role' => PermissionCatalog::ROLE_HR_ADMIN,
    ])->assertSessionHasErrors('role');
    expect($employee->fresh()->user_id)->toBeNull();

    $this->actingAs($actor->fresh())->post("/colaboradores/{$employee->id}/cuenta", [
        'email' => 'nuevo.colaborador@example.test', 'mode' => 'reset', 'role' => PermissionCatalog::ROLE_EMPLOYEE,
    ])->assertSessionHasNoErrors();
    expect($employee->fresh()->user_id)->not->toBeNull();
});

it('M3 · en una autorización multinivel la misma persona no resuelve dos niveles', function () {
    $hr = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $otherHr = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $levels = [
        ['level' => 1, 'permission' => 'vacations.approve', 'label' => 'Jefe inmediato'],
        ['level' => 2, 'permission' => 'vacations.approve', 'label' => 'RH'],
    ];
    $vacation = VacationRequest::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->emp->id,
        'employment_id' => $this->emp->employments()->value('id'), 'service_year' => 3,
        'starts_on' => '2026-12-07', 'ends_on' => '2026-12-08', 'days' => 2,
        'status' => ApprovalStatus::Pending, 'requested_by' => $this->user->id,
    ]);
    $workflow = app(ApprovalWorkflow::class);

    expect($workflow->decide($hr, $vacation, 'vacation', true, null, null, $levels))->toBeFalse(); // pasa al nivel 2
    $vacation->refresh();
    expect($workflow->canDecide($hr, $vacation, 'vacation', $levels))->toBeFalse()
        ->and($workflow->canDecide($otherHr, $vacation, 'vacation', $levels))->toBeTrue();
});

it('M4 · cambiar o restablecer la contraseña cierra tokens de la app y sesiones en otros equipos', function () {
    DB::table('sessions')->insert(['id' => 'otro-equipo', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'otro', 'payload' => '', 'last_activity' => now()->timestamp]);
    expect($this->user->tokens()->count())->toBe(1);

    $this->actingAs($this->user)->from('/settings/security')->put('/settings/password', [
        'current_password' => 'password', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
    ])->assertSessionHasNoErrors();

    expect($this->user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'otro-equipo')->exists())->toBeFalse();
    $this->assertApiError($this->mobile('GET', '/profile'), 401, 'unauthenticated');

    // Restablecimiento por enlace (Fortify): tampoco sobrevive ningún token.
    $this->tokenFor($this->user, $this->device);
    $resetToken = Password::broker()->createToken($this->user->fresh());
    auth()->logout();
    $this->post('/reset-password', [
        'token' => $resetToken, 'email' => $this->user->email, 'password' => 'Otra-Clave-2026', 'password_confirmation' => 'Otra-Clave-2026',
    ])->assertSessionHasNoErrors();
    expect($this->user->tokens()->count())->toBe(0);
});

it('B6 · exportar marcajes (con coordenadas) exige punches.export, no sólo consultarlos', function () {
    $direction = $this->userIn($this->org, PermissionCatalog::ROLE_DIRECTION, allBranches: true);
    $comptroller = $this->userIn($this->org, PermissionCatalog::ROLE_COMPTROLLER, allBranches: true);

    $this->actingAs($direction)->get('/asistencias/marcajes/exportar')->assertForbidden();
    $this->actingAs($direction)->get('/asistencias')->assertOk()->assertInertia(fn ($page) => $page->where('can_export', false));
    $this->actingAs($comptroller)->get('/asistencias/marcajes/exportar')->assertOk();
});

it('B8 · nadie autoriza su propio teléfono ni un cambio de su propio horario', function () {
    $this->device->forceFill(['status' => DeviceStatus::Pending])->save();
    expect(fn () => app(DeviceService::class)->approve($this->user, $this->device->fresh()))
        ->toThrow(AttendanceException::class, 'propio dispositivo');

    $hr = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $change = ScheduleException::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->emp->id, 'date' => '2026-11-25',
        'starts_at' => '10:00', 'ends_at' => '19:00', 'break_minutes' => 60, 'reason' => 'Cambio', 'status' => ScheduleException::STATUS_PENDING,
        'created_by' => $hr->id,
    ]);
    expect(fn () => app(ScheduleChangeService::class)->approve($this->user, $change))
        ->toThrow(ValidationException::class, 'propio horario');
});
