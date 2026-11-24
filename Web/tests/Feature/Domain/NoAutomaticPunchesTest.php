<?php

use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\HashChain;
use App\Domain\Attendance\PolicyResolver;
use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchOrigin;
use App\Domain\Attendance\PunchRecorder;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Enums\WorkdayStatus;
use App\Jobs\RecomputeWorkday;
use App\Models\ComplianceAlert;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWorkforce;

/*
| Revisión RH (pantalla 01 y regla general 2): prohibido generar entradas o
| salidas por horario, cierre de jornada o cualquier otro proceso. Un marcaje
| faltante queda como omisión de checada.
*/

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->employee = $this->employee($this->org, schedule: $this->schedule('09:00', '18:00', 60));
});

/**
 * Inserta un marcaje histórico con origen `system` (como los que generaba la
 * extinta salida automática), encadenado igual que los reales.
 */
function historicSystemClockOut(Punch $in, string $deviceTimeUtc): Punch
{
    $chain = app(HashChain::class);

    return $chain->transaction(function () use ($chain, $in, $deviceTimeUtc) {
        $link = $chain->next($in->organization_id, Punch::class);
        $at = CarbonImmutable::parse($deviceTimeUtc, 'UTC');
        $punch = new Punch([
            'uuid' => (string) Str::uuid7(),
            'organization_id' => $in->organization_id,
            'employee_id' => $in->employee_id,
            'employment_id' => $in->employment_id,
            'client_uuid' => (string) Str::uuid7(),
            'type' => PunchType::ClockOut,
            'source' => PunchSource::System,
            'server_time' => $at->addMinutes(3),
            'device_time' => $at,
            'timezone' => 'America/Mexico_City',
            'local_date' => $at->setTimezone('America/Mexico_City')->toDateString(),
            'auth_method' => AuthMethod::System,
            'signature_valid' => false,
            'captured_offline' => false,
            'status' => PunchStatus::Accepted,
            'anomalies' => ['auto_clock_out'],
            'sequence' => $link['sequence'],
            'previous_hash' => $link['previous_hash'],
            'created_at' => $at->addMinutes(3),
        ]);
        $punch->hash = $chain->hash($link['previous_hash'], HashChain::punchPayload($punch));
        $punch->save();

        return $punch;
    });
}

it('ya no registra el comando de salida automática', function () {
    expect(array_keys(Artisan::all()))->not->toContain('asistencias:cierre-automatico');

    $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($scheduled)->not->toContain('cierre-automatico')
        ->and($scheduled)->toContain('asistencias:cerrar-dia');
});

it('ninguna tarea programada crea marcajes, aunque haya jornadas abiertas', function () {
    // Entrada el martes 17 a las 09:00 local y nunca marca salida.
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $before = Punch::query()->withoutGlobalScopes()->count();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));

    $events = collect(app(Schedule::class)->events());
    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        // Ejecuta cada comando programado exactamente como lo define el scheduler.
        $command = trim(Str::after((string) $event->command, 'artisan'), " '\"");
        Artisan::call($command);
    }

    expect(Punch::query()->withoutGlobalScopes()->count())->toBe($before);
});

it('el cierre de día deja la salida faltante como omisión y alerta, sin completarla', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));

    Artisan::call('asistencias:cerrar-dia', ['--fecha' => '2026-11-17']);
    RecomputeWorkday::dispatchSync($this->org->id, $this->employee->id, '2026-11-17');

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect(Punch::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($day->status)->toBe(WorkdayStatus::Incomplete)
        ->and($day->anomalies)->toContain('missing_clock_out')
        ->and($day->last_out)->toBeNull()
        ->and(ComplianceAlert::query()->where('employee_id', $this->employee->id)->where('rule_code', 'missing_clock_out')->where('status', 'open')->exists())->toBeTrue();
});

it('cuenta todo el tiempo registrado: sin hora de corte, lo posterior al horario es tiempo extra pendiente', function () {
    // 09:00 → 23:00 local: 14 h de presencia (antes se cortaba en la «hora máxima»).
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 05:00:00');

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->worked_minutes)->toBe(840)
        ->and($day->excess_minutes)->toBe(300)
        ->and(array_key_exists('after_cutoff_minutes', $day->getAttributes()))->toBeFalse()
        ->and($day->status)->toBe(WorkdayStatus::Present);

    $record = OvertimeRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($record->recorded_excess_minutes)->toBe(300)
        ->and($record->status->value)->toBe('pending');
});

it('conserva intactos los marcajes históricos del sistema, pero no los reconoce como del colaborador', function () {
    $in = $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $system = historicSystemClockOut($in, '2026-11-18 01:00:00');
    RecomputeWorkday::dispatchSync($this->org->id, $this->employee->id, '2026-11-17');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    RecomputeWorkday::dispatchSync($this->org->id, $this->employee->id, '2026-11-17');

    $stored = Punch::query()->findOrFail($system->id);
    expect($stored->source)->toBe(PunchSource::System)
        ->and($stored->hash)->toBe($system->hash)
        ->and(PunchOrigin::LABELS[PunchOrigin::forPunch($stored)])->toBe('Generado por sistema (histórico)');

    $events = app(EffectiveTimeline::class)->between($this->employee->id, CarbonImmutable::parse('2026-11-17 00:00:00'), CarbonImmutable::parse('2026-11-19 00:00:00'));
    expect(collect($events)->pluck('punch.uuid')->all())->not->toContain($system->uuid);

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->status)->toBe(WorkdayStatus::Incomplete)
        ->and($day->anomalies)->toContain('missing_clock_out');
});

it('muestra la falta injustificada cuando no hay marcaje ni justificación aprobada', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    RecomputeWorkday::dispatchSync($this->org->id, $this->employee->id, '2026-11-17');

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->status)->toBe(WorkdayStatus::Absent)
        ->and(Punch::query()->where('employee_id', $this->employee->id)->count())->toBe(0);
});

it('una salida sin entrada queda como omisión de checada de entrada', function () {
    // Salida capturada sin conexión a las 18:05 local del martes 17 sin entrada previa.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 00:30:00', 'UTC'));
    $policy = app(PolicyResolver::class)->for($this->employee);
    $policy->update(['web_punch_allowed' => true, 'location_mode' => 'free']);
    app(PunchRecorder::class)->record($this->employee->user, $this->employee, new PunchInput(
        clientUuid: (string) Str::uuid(), type: PunchType::ClockOut, source: PunchSource::Web, authMethod: AuthMethod::WebSession,
        deviceTime: CarbonImmutable::parse('2026-11-18 00:05:00', 'UTC'), deviceTimeRaw: '2026-11-18T00:05:00Z', timezone: 'America/Mexico_City',
        deviceUuid: null, nonce: null, signature: null, capturedOffline: true,
        latitude: null, longitude: null, accuracyMeters: null, locationCapturedAt: null, locationMocked: null,
        faceVerificationId: null, ipAddress: '127.0.0.1', userAgent: 'tests',
    ), null);
    RecomputeWorkday::dispatchSync($this->org->id, $this->employee->id, '2026-11-17');

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->anomalies)->toContain('missing_clock_in')
        ->and($day->status)->toBe(WorkdayStatus::Incomplete)
        ->and($day->first_in)->toBeNull()
        ->and(Punch::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and(PunchOrigin::forWorkday($day, collect()))->toBe(PunchOrigin::OMISSION);
});
