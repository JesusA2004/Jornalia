<?php

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchRecorder;
use App\Domain\Attendance\WorkdayCalculator;
use App\Domain\Security\KioskService;
use App\Domain\Signatures\SignaturePinService;
use App\Domain\Workforce\WorkforceService;
use App\Enums\AuthMethod;
use App\Enums\EmployeeStatus;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Enums\WorkdayStatus;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Punch;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\MobileApi;

/*
| Motor de asistencia (auditoría P2): casos límite de una sola fuente de cálculo
| (PunchRecorder → EffectiveTimeline → WorkdayCalculator). Organización en
| America/Mexico_City (UTC-6); horario 09:00–18:00 L-V con 60 min de comida.
*/

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
    $this->calc = app(WorkdayCalculator::class);
});

/**
 * Marcaje web en línea por el dominio, a la hora UTC indicada (sin tocar la política).
 */
function engineWebPunch(Employee $employee, PunchType $type, string $utc, ?string $deviceTimeUtc = null): Punch
{
    test()->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    $deviceTime = $deviceTimeUtc !== null ? CarbonImmutable::parse($deviceTimeUtc, 'UTC') : null;

    return app(PunchRecorder::class)->record($employee->user, $employee, new PunchInput(
        clientUuid: (string) Str::uuid(),
        type: $type,
        source: PunchSource::Web,
        authMethod: AuthMethod::WebSession,
        deviceTime: $deviceTime, deviceTimeRaw: $deviceTime?->format('Y-m-d\TH:i:s.v\Z'), timezone: 'America/Mexico_City',
        deviceUuid: null, nonce: null, signature: null, capturedOffline: false,
        latitude: null, longitude: null, accuracyMeters: null, locationCapturedAt: null, locationMocked: null,
        faceVerificationId: null, ipAddress: '127.0.0.1', userAgent: 'tests',
    ), null)['punch'];
}

function allowWebPunch(): void
{
    AttendancePolicy::query()->where('is_default', true)->update(['web_punch_allowed' => true, 'location_mode' => 'free']);
}

it('baja durante la jornada: el último día puede checar su salida por la tarde (fecha civil, no UTC)', function () {
    allowWebPunch();
    engineWebPunch($this->emp, PunchType::ClockIn, '2026-11-17 15:00:00'); // 09:00 local

    // Baja con fecha de hoy (17/11): la relación laboral y la asignación terminan ese día civil.
    $employment = $this->emp->employments()->firstOrFail();
    $employment->forceFill(['ends_on' => '2026-11-17', 'status' => 'terminated'])->save();
    $employment->assignments()->update(['effective_to' => '2026-11-17']);

    // 19:30 en Ciudad de México = 01:30 UTC del 18/11. Antes se rechazaba con `no_employment`.
    $out = engineWebPunch($this->emp->fresh(), PunchType::ClockOut, '2026-11-18 01:30:00');

    expect($out->local_date->toDateString())->toBe('2026-11-17');
    $summary = $this->calc->compute($this->emp->fresh(), '2026-11-17');
    expect($summary->worked_minutes)->toBe(630)
        ->and($summary->anomalies)->not->toContain('missing_clock_out');

    // Al día civil siguiente ya no hay relación laboral.
    expect(fn () => engineWebPunch($this->emp->fresh(), PunchType::ClockIn, '2026-11-18 15:00:00'))
        ->toThrow(AttendanceException::class);
});

it('alta futura: no acepta marcajes la víspera aunque en UTC ya sea el día de ingreso', function () {
    allowWebPunch();
    $newHire = $this->employee($this->org, schedule: $this->schedule('09:00', '18:00', 60), hiredOn: '2026-11-18');

    // 19:00 del 17/11 local = 01:00 UTC del 18/11 (día de ingreso). Antes se aceptaba.
    expect(fn () => engineWebPunch($newHire, PunchType::ClockIn, '2026-11-18 01:00:00'))
        ->toThrow(AttendanceException::class, 'relación laboral');

    $punch = engineWebPunch($newHire, PunchType::ClockIn, '2026-11-18 15:00:00');
    expect($punch->local_date->toDateString())->toBe('2026-11-18');
});

it('en línea la hora del dispositivo no elige la asignación ni la política aplicable', function () {
    $lax = AttendancePolicy::query()->where('is_default', true)->firstOrFail();
    $lax->forceFill(['web_punch_allowed' => true, 'location_mode' => 'free'])->save();
    $strict = $lax->replicate();
    $strict->forceFill(['name' => 'Sin marcaje web', 'is_default' => false, 'web_punch_allowed' => false])->save();

    // Movimiento: hasta el 16/11 política laxa; desde el 17/11 política sin marcaje web.
    $employment = $this->emp->employments()->firstOrFail();
    $old = $employment->assignments()->firstOrFail();
    $old->forceFill(['effective_to' => '2026-11-16', 'attendance_policy_id' => $lax->id])->save();
    $employment->assignments()->create([
        'organization_id' => $this->org->id,
        'effective_from' => '2026-11-17',
        'branch_id' => $old->branch_id,
        'schedule_id' => $old->schedule_id,
        'attendance_policy_id' => $strict->id,
    ]);

    // Reloj del teléfono atrasado una semana: antes resolvía la asignación anterior y lo aceptaba.
    expect(fn () => engineWebPunch($this->emp->fresh(), PunchType::ClockIn, '2026-11-17 16:00:00', '2026-11-10 16:00:00'))
        ->toThrow(AttendanceException::class, 'sitio web');
});

it('sincronización tardía (más de 4 días): la captura sin conexión cuenta en su día y queda en revisión', function () {
    $in = $this->signedPunch('clock_in', null, ['device_time' => '2026-11-10T15:00:00.000Z']);   // mar 10/11 09:00
    $out = $this->signedPunch('clock_out', null, ['device_time' => '2026-11-11T00:00:00.000Z']);  // 18:00

    $results = $this->mobile('POST', '/sync/punches', ['punches' => [$in, $out]])->assertOk()->json('data.results');
    expect(array_column($results, 'result'))->toBe(['created', 'created']);

    $summary = $this->calc->compute($this->emp->fresh(), '2026-11-10');
    expect($summary->worked_minutes)->toBe(540) // descanso no registrado: no se descuenta (art. 64)
        ->and($summary->status)->toBe(WorkdayStatus::Present)
        ->and($summary->first_in?->utc()->format('Y-m-d H:i'))->toBe('2026-11-10 15:00');

    $flags = Punch::query()->where('employee_id', $this->emp->id)->pluck('anomalies')->flatten()->all();
    expect($flags)->toContain('late_sync');
});

it('sincronización fuera de orden: la salida enviada antes que la entrada no altera el cálculo', function () {
    $in = $this->signedPunch('clock_in', null, ['device_time' => '2026-11-16T15:00:00.000Z']);
    $out = $this->signedPunch('clock_out', null, ['device_time' => '2026-11-17T00:00:00.000Z']);

    $results = $this->mobile('POST', '/sync/punches', ['punches' => [$out, $in]])->assertOk()->json('data.results');
    expect(array_column($results, 'result'))->toBe(['created', 'created']);

    $outPunch = Punch::query()->where('client_uuid', $out['client_uuid'])->firstOrFail();
    expect($outPunch->anomalies)->toContain('out_of_sequence'); // a revisión, nunca descartado

    $summary = $this->calc->compute($this->emp->fresh(), '2026-11-16');
    expect($summary->worked_minutes)->toBe(540)->and($summary->anomalies)->toBe([]);
});

it('descansos: el registrado se descuenta; el no cerrado no se descuenta y queda como anomalía', function () {
    allowWebPunch();
    engineWebPunch($this->emp, PunchType::ClockIn, '2026-11-17 15:00:00');
    engineWebPunch($this->emp, PunchType::BreakStart, '2026-11-17 19:00:00');
    engineWebPunch($this->emp, PunchType::BreakEnd, '2026-11-17 19:45:00');
    engineWebPunch($this->emp, PunchType::ClockOut, '2026-11-18 00:00:00');

    $day = $this->calc->compute($this->emp->fresh(), '2026-11-17');
    expect($day->worked_minutes)->toBe(495)->and($day->break_minutes)->toBe(45);

    // En línea no se puede salir con el descanso abierto (se exige «Fin de descanso»)…
    engineWebPunch($this->emp, PunchType::ClockIn, '2026-11-18 15:00:00');
    engineWebPunch($this->emp, PunchType::BreakStart, '2026-11-18 19:00:00');
    expect(fn () => engineWebPunch($this->emp, PunchType::ClockOut, '2026-11-19 00:00:00'))
        ->toThrow(AttendanceException::class, 'Fin de descanso');

    // …pero sin conexión sí ocurre: se acepta a revisión y el descanso abierto no se descuenta.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 00:05:00', 'UTC'));
    $offlineOut = $this->signedPunch('clock_out', null, ['device_time' => '2026-11-19T00:00:00.000Z']);
    $this->mobile('POST', '/sync/punches', ['punches' => [$offlineOut]], $this->tokenFor($this->user, $this->device))
        ->assertOk()->assertJsonPath('data.results.0.result', 'created');

    $open = $this->calc->compute($this->emp->fresh(), '2026-11-18');
    expect($open->worked_minutes)->toBe(540)
        ->and($open->break_minutes)->toBe(0)
        ->and($open->anomalies)->toContain('break_not_closed');
});

it('festivo laborado: el día queda como festivo laborado y el tiempo excedente a revisión', function () {
    allowWebPunch();
    Holiday::query()->withoutGlobalScopes()->create([
        'organization_id' => $this->org->id, 'date' => '2026-11-12', 'code' => 'EMPRESA', 'name' => 'Aniversario', 'kind' => 'company', 'is_mandatory_rest' => true,
    ]);
    $in = engineWebPunch($this->emp, PunchType::ClockIn, '2026-11-12 15:00:00');
    engineWebPunch($this->emp, PunchType::ClockOut, '2026-11-13 00:00:00');

    expect($in->anomalies)->toContain('holiday');
    $day = $this->calc->compute($this->emp->fresh(), '2026-11-12');
    expect($day->status)->toBe(WorkdayStatus::HolidayWorked)->and($day->worked_minutes)->toBe(540);
});

it('cambio de horario programado: sólo el autorizado cambia el turno contra el que se calcula', function () {
    allowWebPunch();
    $pending = ScheduleException::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->emp->id, 'date' => '2026-11-17',
        'starts_at' => '11:00', 'ends_at' => '20:00', 'break_minutes' => 60, 'reason' => 'Inventario', 'status' => ScheduleException::STATUS_PENDING,
    ]);
    engineWebPunch($this->emp, PunchType::ClockIn, '2026-11-17 17:00:00'); // 11:00 local
    engineWebPunch($this->emp, PunchType::ClockOut, '2026-11-18 02:00:00'); // 20:00 local

    $before = $this->calc->compute($this->emp->fresh(), '2026-11-17');
    expect($before->late_minutes)->toBe(120); // sin autorizar: rige el horario 09:00

    $pending->forceFill(['status' => ScheduleException::STATUS_APPROVED])->save();
    $after = $this->calc->compute($this->emp->fresh(), '2026-11-17');
    expect($after->late_minutes)->toBe(0)
        ->and($after->scheduled_start?->utc()->format('H:i'))->toBe('17:00')
        ->and($after->excess_minutes)->toBe(0);
});

it('turno nocturno que cruza fin de mes: se atribuye al día de inicio y no deja omisión al día siguiente', function () {
    allowWebPunch();
    $night = $this->employee($this->org, schedule: $this->schedule('22:00', '06:00', 0, [0, 1, 2, 3, 4, 5, 6]));
    engineWebPunch($night, PunchType::ClockIn, '2026-12-01 04:00:00');  // 30/11 22:00 local
    engineWebPunch($night, PunchType::ClockOut, '2026-12-01 12:00:00'); // 01/12 06:00 local

    $start = $this->calc->compute($night->fresh(), '2026-11-30');
    $next = $this->calc->compute($night->fresh(), '2026-12-01');
    expect($start->worked_minutes)->toBe(480)
        ->and($start->night_minutes)->toBe(480)
        ->and($next->worked_minutes)->toBe(0)
        ->and($next->anomalies)->not->toContain('missing_clock_in');
});

it('kiosco: una baja con fecha futura no bloquea los días que aún trabaja y se usa la fecha civil', function () {
    $hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $branch = Branch::query()->where('code', 'MATRIZ')->firstOrFail();
    $kiosk = app(KioskService::class)->create($hr, $branch, 'Tableta Matriz')['kiosk'];
    app(SignaturePinService::class)->set($this->user, '4826');
    $kioskPunch = fn (string $type) => app(KioskService::class)->punch($kiosk->fresh(), [
        'employee_number' => $this->emp->employee_number, 'pin' => '4826', 'type' => $type,
        'client_uuid' => (string) Str::uuid(), 'device_time' => now()->utc()->toIso8601String(), 'timezone' => 'America/Mexico_City',
    ], '127.0.0.1', 'tests');

    // Baja registrada hoy (17/11) con fecha 18/11: el estatus pasa a «Baja», pero aún trabaja.
    app(WorkforceService::class)->terminate($hr, $this->emp, '2026-11-18', 'Renuncia voluntaria');
    expect($this->emp->fresh()->status)->toBe(EmployeeStatus::Terminated);
    expect($kioskPunch('clock_in')['duplicate'])->toBeFalse(); // antes: kiosk_employee_inactive

    // Último día, 19:30 local (01:30 UTC del 19/11): antes se rechazaba por la fecha UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 15:00:00', 'UTC'));
    $kioskPunch('clock_in');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 01:30:00', 'UTC'));
    expect($kioskPunch('clock_out')['punch']->local_date->toDateString())->toBe('2026-11-18');

    // Día siguiente a la baja: ya no.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 15:00:00', 'UTC'));
    expect(fn () => $kioskPunch('clock_in'))->toThrow(AttendanceException::class, 'no está activo');
});
