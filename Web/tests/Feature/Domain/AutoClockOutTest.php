<?php

use App\Domain\Attendance\AutoClockOut;
use App\Domain\Attendance\IntegrityVerifier;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\OvertimeRecord;
use App\Models\Position;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->employee = $this->employee($this->org, schedule: $this->schedule('09:00', '18:00', 60));
    // Hora máxima de salida 19:00 configurada en el puesto.
    $position = Position::query()->create(['name' => 'Asesor', 'code' => 'ASESOR', 'auto_clock_out_at' => '19:00:00']);
    $this->employee->employmentOn('2026-11-17')->assignmentOn('2026-11-17')->update(['position_id' => $position->id]);
});

it('registra una salida automática identificada como del sistema', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00'); // 09:00 CDMX
    $this->travelTo(CarbonImmutable::parse('2026-11-18 01:07:00', 'UTC'));    // 19:07 CDMX

    $punch = app(AutoClockOut::class)->closeIfDue($this->employee->fresh());

    expect($punch)->not->toBeNull()
        ->and($punch->source)->toBe(PunchSource::System)
        ->and($punch->type)->toBe(PunchType::ClockOut)
        ->and($punch->device_time->utc()->format('H:i'))->toBe('01:00')   // 19:00 CDMX reconocida
        ->and($punch->anomalies)->toContain('auto_clock_out')
        ->and($punch->recorded_by)->toBeNull();

    // Idempotente: no se repite.
    expect(app(AutoClockOut::class)->closeIfDue($this->employee->fresh()))->toBeNull();

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->worked_minutes)->toBe(600)    // 09:00–19:00
        ->and($day->excess_minutes)->toBe(60)  // sobre el lapso programado de 540
        ->and($day->after_cutoff_minutes)->toBe(0);

    expect(app(IntegrityVerifier::class)->verify($this->org->id)['valid'])->toBeTrue();
});

it('no cuenta como extra el tiempo después de la hora máxima, pero lo conserva', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');   // 09:00
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 02:30:00');  // 20:30 (salida real tardía)

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->worked_minutes)->toBe(600)
        ->and($day->after_cutoff_minutes)->toBe(90)
        ->and($day->excess_minutes)->toBe(60);

    $ot = OvertimeRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($ot->recorded_excess_minutes)->toBe(60);
    // El marcaje real de las 20:30 sigue intacto.
    expect(Punch::query()->where('type', 'clock_out')->firstOrFail()->server_time->utc()->format('H:i'))->toBe('02:30');
});

it('respeta la salida anterior a la hora máxima y prioriza la configuración personal', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 00:15:00');  // 18:15
    $this->travelTo(CarbonImmutable::parse('2026-11-18 02:00:00', 'UTC'));
    expect(app(AutoClockOut::class)->closeIfDue($this->employee->fresh()))->toBeNull();

    // Persona con hora propia 18:30 por encima del puesto (19:00).
    $assignment = $this->employee->employmentOn('2026-11-17')->assignmentOn('2026-11-17');
    $assignment->update(['auto_clock_out_at' => '18:30:00']);
    $cutoff = app(AutoClockOut::class)->cutoffFor($this->employee->fresh(), CarbonImmutable::parse('2026-11-18 15:00:00', 'UTC'));
    expect($cutoff->setTimezone('America/Mexico_City')->format('H:i'))->toBe('18:30');
});
