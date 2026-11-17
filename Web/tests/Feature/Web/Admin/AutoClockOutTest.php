<?php

use App\Models\AttendancePolicy;
use App\Models\EmploymentAssignment;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

it('configura la salida automática en puesto y política con auditoría', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/organizacion/puestos', ['name' => 'Cajero', 'code' => 'CAJ', 'auto_clock_out_at' => '25:00'])
        ->assertSessionHasErrors('auto_clock_out_at');
    $this->actingAs($admin)->post('/organizacion/puestos', ['name' => 'Cajero', 'code' => 'CAJ', 'auto_clock_out_at' => '20:30'])
        ->assertSessionHasNoErrors();
    expect(substr((string) Position::query()->where('code', 'CAJ')->value('auto_clock_out_at'), 0, 5))->toBe('20:30');

    $policy = AttendancePolicy::query()->where('is_default', true)->firstOrFail();
    $this->actingAs($admin)->put("/configuracion/asistencia/{$policy->id}", [
        'name' => $policy->name, 'location_mode' => 'record', 'allowed_auth_methods' => ['device_biometric'], 'require_strong_biometric' => false,
        'allow_offline' => true, 'offline_max_hours' => 72, 'face_verification_required' => false, 'web_punch_allowed' => false,
        'max_clock_skew_seconds' => 300, 'min_location_accuracy_meters' => 150, 'is_default' => true, 'auto_clock_out_at' => '21:00',
    ])->assertSessionHasNoErrors();
    expect(substr((string) $policy->fresh()->auto_clock_out_at, 0, 5))->toBe('21:00');
    $this->assertDatabaseHas('audit_logs', ['action' => 'attendance_policy.updated', 'subject_id' => $policy->id]);
});

it('registra la salida automática personal como movimiento con vigencia y muestra su origen', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    $employee = $this->employee($org, schedule: $this->schedule('09:00', '18:00'), hiredOn: '2024-01-15');
    $original = $employee->assignmentOn('2025-01-01');
    AttendancePolicy::query()->where('is_default', true)->update(['auto_clock_out_at' => '21:00:00']);

    $this->actingAs($admin)->get("/colaboradores/{$employee->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auto_clock_out.time', '21:00')->where('auto_clock_out.source', 'policy'));

    $today = CarbonImmutable::today()->toDateString();
    // Antes del fin del turno (18:00): se registra con advertencia.
    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/movimientos", [
        'effective_from' => $today, 'reason' => 'Cierre personal', 'fields' => ['auto_clock_out_at'], 'auto_clock_out_at' => '17:00',
    ])->assertSessionHasNoErrors();

    $original->refresh();
    expect($original->effective_from->toDateString())->toBe('2024-01-15')
        ->and($original->auto_clock_out_at)->toBeNull();

    $current = EmploymentAssignment::query()->where('employment_id', $original->employment_id)->whereKeyNot($original->id)->firstOrFail();
    expect(substr((string) $current->auto_clock_out_at, 0, 5))->toBe('17:00')
        ->and($current->schedule_id)->toBe($original->schedule_id);

    $this->actingAs($admin)->get("/colaboradores/{$employee->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auto_clock_out.time', '17:00')
            ->where('auto_clock_out.source', 'person')
            ->where('auto_clock_out.warning', fn ($w) => is_string($w) && str_contains($w, '18:00')));
});
