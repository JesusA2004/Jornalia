<?php

use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Enums\PunchType;
use App\Enums\ShiftKind;
use App\Http\Controllers\Web\Operacion\Support\OvertimeAuthorizer;
use App\Models\ComplianceAlert;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsWorkforce;

/*
| Horas extra (Revisión RH, pantalla 07): cálculo por colaborador y por semana con
| los topes de la tabla normativa del año, menores de 18 y cruce de medianoche/año.
*/

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    $this->calc = app(WeeklyOvertimeCalculator::class);
    $this->law = app(LegalFramework::class);
});

/**
 * @param  list<int>  $excess  minutos extra por día desde el lunes
 * @return array<string, array{worked:int, excess:int, minor?:bool}>
 */
function overtimeWeek(string $monday, array $excess, int $scheduled = 480, bool $minor = false): array
{
    $days = [];
    foreach ($excess as $i => $m) {
        $date = CarbonImmutable::parse($monday)->addDays($i)->toDateString();
        $days[$date] = ['worked' => $scheduled + $m, 'excess' => $m, 'minor' => $minor];
    }

    return $days;
}

it('aplica topes 2027: 9 h dobles, hasta 4 días, triples hasta 4 h y sobre límite el quinto día', function () {
    $r = $this->calc->classify(overtimeWeek('2027-03-01', [180, 180, 180, 180, 180]), $this->law->for('2027-03-01'));
    $sum = fn (string $k) => array_sum(array_column($r, $k));

    expect($sum('double'))->toBe(540)          // 9 h semanales al doble
        ->and($sum('triple'))->toBe(360)       // excedente semanal al triple
        ->and($r['2027-03-04'])->toMatchArray(['double' => 0, 'triple' => 180, 'over_limit' => 0])
        ->and($r['2027-03-05']['over_limit'])->toBe(180); // quinto día con extra: rebasa «máx. 4 días»
});

it('toma los topes semanales de la tabla normativa de cada año', function (string $monday, int $double, int $triple) {
    $r = $this->calc->classify(overtimeWeek($monday, [150, 150, 150, 150]), $this->law->for($monday));

    expect(array_sum(array_column($r, 'double')))->toBe($double)
        ->and(array_sum(array_column($r, 'triple')))->toBe($triple);
})->with([
    '2027 (9 h)' => ['2027-06-07', 540, 60],
    '2028 (10 h)' => ['2028-06-05', 600, 0],
    '2029 (11 h)' => ['2029-06-04', 600, 0],
]);

it('marca sobre límite el tiempo que rebasa 4 h diarias y la jornada total de 12 h', function () {
    $r = $this->calc->classify(overtimeWeek('2027-03-01', [300]), $this->law->for('2027-03-01'));

    // 8 h ordinarias + 5 h extra = 13 h: 60 min sobre 4 h/día y sobre 12 h totales.
    expect($r['2027-03-01']['over_limit'])->toBe(60)
        ->and($r['2027-03-01']['double'])->toBe(300);
});

it('para menores de 18 todo el tiempo extra es sobre límite y se paga al triple (art. 178)', function () {
    $r = $this->calc->classify(overtimeWeek('2027-03-01', [60, 30], minor: true), $this->law->for('2027-03-01'));

    expect($r['2027-03-01'])->toMatchArray(['double' => 0, 'triple' => 60, 'over_limit' => 60, 'minor' => true])
        ->and($r['2027-03-02']['over_limit'])->toBe(30);
});

it('usa la tabla del año en que inicia la semana en el cambio de año normativo', function () {
    $dec = $this->calc->classify(overtimeWeek('2027-12-27', [150, 150, 150, 150]), $this->law->for(WeeklyOvertimeCalculator::weekStart('2027-12-31')));
    $jan = $this->calc->classify(overtimeWeek('2028-01-03', [150, 150, 150, 150]), $this->law->for(WeeklyOvertimeCalculator::weekStart('2028-01-03')));

    expect(array_sum(array_column($dec, 'double')))->toBe(540)
        ->and(array_sum(array_column($jan, 'double')))->toBe(600);
});

it('atribuye un turno nocturno que cruza medianoche y año al día de inicio', function () {
    $org = $this->organization();
    // Viernes 31-dic-2027 22:00 → sábado 1-ene-2028 07:00 (CDMX, UTC-6).
    $employee = $this->employee($org, schedule: $this->schedule('22:00', '06:00', 0, [4], ShiftKind::Night), hiredOn: '2027-01-01');
    $this->punchAt($employee, PunchType::ClockIn, '2028-01-01 04:00:00');
    $this->punchAt($employee, PunchType::ClockOut, '2028-01-01 13:00:00');

    $day = WorkdaySummary::query()->where('employee_id', $employee->id)->whereDate('date', '2027-12-31')->firstOrFail();
    expect($day->worked_minutes)->toBe(540)
        ->and($day->night_minutes)->toBe(480)
        ->and($day->excess_minutes)->toBe(60)
        ->and(WorkdaySummary::query()->where('employee_id', $employee->id)->whereDate('date', '2028-01-01')->where('worked_minutes', '>', 0)->exists())->toBeFalse();

    $record = OvertimeRecord::query()->where('employee_id', $employee->id)->whereDate('date', '2027-12-31')->firstOrFail();
    expect($record->week_start->toDateString())->toBe('2027-12-27')
        ->and($record->legal_version_code)->toBe('LFT-2027')
        ->and($record->status->value)->toBe('pending');

    $week = $this->calc->forEmployee($employee, '2027-12-31');
    expect($week->totals()['recorded_minutes'])->toBe(60)
        ->and($week->totals()['pending_minutes'])->toBe(60)
        ->and($week->legalVersionCode)->toBe('LFT-2027');
});

it('bloquea autorizar tiempo extra de un menor sin impedir registrar su salida real', function () {
    $org = $this->organization();
    $boss = $this->userIn($org, 'Administrador RH', allBranches: true);
    $minor = $this->employee($org, schedule: $this->schedule('09:00', '15:00', 0), birthDate: '2010-05-01');

    // Martes 17-nov-2026: 09:00 → 17:00 local (2 h después del horario).
    $this->punchAt($minor, PunchType::ClockIn, '2026-11-17 15:00:00');
    $out = $this->punchAt($minor, PunchType::ClockOut, '2026-11-17 23:00:00');
    expect(Punch::query()->whereKey($out->id)->exists())->toBeTrue();

    $record = OvertimeRecord::query()->where('employee_id', $minor->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($record->recorded_excess_minutes)->toBe(120)
        ->and($record->over_limit_minutes)->toBe(120)
        ->and($record->double_minutes)->toBe(0)
        ->and(ComplianceAlert::query()->where('employee_id', $minor->id)->where('rule_code', 'minor_overtime')->exists())->toBeTrue();

    expect(fn () => app(OvertimeAuthorizer::class)->decide($boss, $record, true, 60, 'Cierre de inventario'))
        ->toThrow(ValidationException::class);
    expect($record->fresh()->status->value)->toBe('pending');

    $week = $this->calc->forEmployee($minor, '2026-11-17');
    expect($week->minor)->toBeTrue()
        ->and($week->totals()['over_limit_minutes'])->toBe(120);
});

it('separa pendiente, autorizado y justificado por colaborador y semana', function () {
    $org = $this->organization();
    $a = $this->employee($org, schedule: $this->schedule('09:00', '18:00', 60));
    $b = $this->employee($org, schedule: $this->schedule('09:00', '18:00', 60));
    // A: lunes 16 y martes 17 con 1 h extra; B: martes 17 con 2 h extra.
    $this->punchAt($a, PunchType::ClockIn, '2026-11-16 15:00:00');
    $this->punchAt($a, PunchType::ClockOut, '2026-11-17 01:00:00');
    $this->punchAt($a, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($a, PunchType::ClockOut, '2026-11-18 01:00:00');
    $this->punchAt($b, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($b, PunchType::ClockOut, '2026-11-18 02:00:00');

    OvertimeRecord::query()->where('employee_id', $a->id)->whereDate('date', '2026-11-16')->firstOrFail()
        ->forceFill(['status' => 'approved', 'authorized_minutes' => 40])->save();

    $weeks = $this->calc->forEmployees([$a, $b], '2026-11-18');
    expect($weeks[$a->id]->totals())->toMatchArray(['recorded_minutes' => 120, 'authorized_minutes' => 40, 'justified_minutes' => 20, 'pending_minutes' => 60, 'double_minutes' => 120])
        ->and($weeks[$b->id]->totals())->toMatchArray(['recorded_minutes' => 120, 'pending_minutes' => 120, 'double_minutes' => 120]);
});
