<?php

use App\Domain\Compliance\OmissionMonitor;
use App\Enums\PunchType;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\ComplianceAlert;
use App\Models\WorkdaySummary;
use App\Notifications\IntegrityAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/*
| Cumplimiento (PDF pantallas 14–16): pestañas, verificación diaria automática,
| alerta crítica a RH y Sistemas, bitácora inalterable y omisiones sin rectificar.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->employee = $this->employee($this->org, schedule: $this->schedule());
    $this->hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $this->platform = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
});

it('agrupa alertas, integridad y bitácora en pestañas de Cumplimiento y redirige las rutas anteriores', function () {
    $this->actingAs($this->hr)->get('/cumplimiento')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('cumplimiento/Index')->has('integrity.punches')->has('legal.verified_by'));
    $this->actingAs($this->hr)->get('/cumplimiento/integridad')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('cumplimiento/Integridad')->has('automation.anchor_storage'));
    $this->actingAs($this->hr)->get('/cumplimiento/bitacora')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('cumplimiento/Bitacora'));

    $this->actingAs($this->hr)->get('/integridad?page=2')->assertRedirect('/cumplimiento/integridad?page=2');
    $this->actingAs($this->hr)->get('/auditoria')->assertRedirect('/cumplimiento/bitacora');
});

it('la Contraloría y la autoridad laboral consultan integridad y bitácora sin poder verificar', function () {
    foreach (['Contraloría', 'Autoridad laboral'] as $role) {
        $user = $this->userIn($this->org, $role, allBranches: true);
        $this->actingAs($user)->get('/cumplimiento/integridad')->assertOk();
        $this->actingAs($user)->get('/cumplimiento/bitacora')->assertOk();
        $this->actingAs($user)->post('/cumplimiento/integridad/verificar')->assertForbidden();
    }
});

it('verifica y ancla automáticamente cada día y deja constancia en la bitácora', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC'));

    $this->artisan('asistencias:integridad', ['accion' => 'diaria', '--organizacion' => $this->org->id])->assertSuccessful();

    $verified = AuditLog::query()->where('organization_id', $this->org->id)->where('action', 'integrity.verified')->first();
    expect($verified)->not->toBeNull()
        ->and($verified->context['automatic'])->toBeTrue()
        ->and($verified->context['valid'])->toBeTrue()
        ->and(AuditLog::query()->where('organization_id', $this->org->id)->where('action', 'integrity.anchored')->exists())->toBeTrue()
        ->and(ComplianceAlert::query()->where('rule_code', 'integrity_failed')->exists())->toBeFalse();
});

it('si la verificación detecta alteraciones genera alerta crítica y avisa a RH y Sistemas', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC'));

    $last = DB::table('attendance_punches')->where('organization_id', $this->org->id)->orderByDesc('sequence')->first();
    $forged = array_merge((array) $last, [
        'uuid' => (string) Str::uuid(), 'client_uuid' => (string) Str::uuid(),
        'sequence' => $last->sequence + 1, 'previous_hash' => $last->hash,
        'server_time' => '2026-11-17 23:00:00', 'type' => 'clock_out', 'hash' => hash('sha256', 'falsificado'),
    ]);
    unset($forged['id']);
    DB::table('attendance_punches')->insert($forged);

    $this->artisan('asistencias:integridad', ['accion' => 'verificar', '--organizacion' => $this->org->id])->assertFailed();
    // Segunda ejecución el mismo día: no duplica la alerta ni el aviso.
    $this->artisan('asistencias:integridad', ['accion' => 'verificar', '--organizacion' => $this->org->id])->assertFailed();

    $alerts = ComplianceAlert::query()->where('rule_code', 'integrity_failed')->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->severity->value)->toBe('critical')
        ->and($alerts->first()->employee_id)->toBeNull();

    foreach ([$this->hr, $this->platform] as $user) {
        expect($user->notifications()->where('data->kind', IntegrityAlert::VERIFICATION_FAILED)->count())->toBe(1);
    }
    expect($this->employee->user->notifications()->where('data->kind', IntegrityAlert::VERIFICATION_FAILED)->count())->toBe(0);
});

it('si falla el anclaje externo genera alerta crítica', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    config(['integrity.anchor_key' => '']);

    $this->artisan('asistencias:integridad', ['accion' => 'anclar', '--organizacion' => $this->org->id])->assertFailed();

    expect(ComplianceAlert::query()->where('rule_code', 'integrity_anchor_failed')->where('severity', 'critical')->exists())->toBeTrue()
        ->and($this->hr->notifications()->where('data->kind', IntegrityAlert::ANCHOR_FAILED)->exists())->toBeTrue();
});

it('la bitácora es inalterable y guarda valor anterior, nuevo, usuario e IP', function () {
    $this->actingAs($this->hr)->put('/configuracion/ubicacion', ['retention_days' => 120, 'contingency_minutes' => 30, 'retention_confirmed' => false])
        ->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('organization_id', $this->org->id)->where('action', 'location.settings_updated')->firstOrFail();
    expect($log->user_id)->toBe($this->hr->id)
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and($log->old_values['retention_days'])->toBe(90)
        ->and($log->new_values['retention_days'])->toBe(120)
        ->and($log->created_at)->not->toBeNull();

    expect(fn () => $log->update(['description' => 'alterada']))->toThrow(ImmutableRecordException::class);
    expect(fn () => $log->delete())->toThrow(ImmutableRecordException::class);
    // Trigger de BD: ni siquiera con acceso directo.
    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['description' => 'x']))->toThrow(QueryException::class);
    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())->toThrow(QueryException::class);

    $this->actingAs($this->hr)->get('/cumplimiento/bitacora?module=location')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.old_values.retention_days', 90)
            ->where('logs.data.0.new_values.retention_days', 120));
});

it('alerta omisiones sin rectificar por más de 2 días y la cierra al rectificar', function () {
    $summary = WorkdaySummary::query()->create([
        'organization_id' => $this->org->id,
        'employee_id' => $this->employee->id,
        'date' => '2026-11-13',
        'status' => 'incomplete',
        'worked_minutes' => 0,
        'anomalies' => ['missing_clock_out'],
        'computed_at' => now(),
    ]);
    // Omisión reciente (1 día): todavía no alerta.
    WorkdaySummary::query()->create([
        'organization_id' => $this->org->id,
        'employee_id' => $this->employee->id,
        'date' => '2026-11-17',
        'status' => 'incomplete',
        'worked_minutes' => 0,
        'anomalies' => ['missing_clock_in'],
        'computed_at' => now(),
    ]);

    $result = app(OmissionMonitor::class)->evaluate($this->org);
    expect($result['opened'])->toBe(1);
    $alert = ComplianceAlert::query()->where('rule_code', OmissionMonitor::RULE)->firstOrFail();
    expect($alert->date->toDateString())->toBe('2026-11-13')->and($alert->status)->toBe('open');

    // Se solicita la rectificación: la alerta automática se cierra sola.
    DB::table('rectification_requests')->insert([
        'organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'date' => $summary->date->toDateString(),
        'kind' => 'missing_punch', 'reason' => 'Olvidé marcar.', 'status' => 'pending', 'stage' => 'por_autorizar',
        'requested_by' => $this->employee->user_id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(OmissionMonitor::class)->evaluate($this->org);
    expect($alert->fresh()->status)->toBe('resolved');
});
