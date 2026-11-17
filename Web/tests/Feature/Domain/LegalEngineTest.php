<?php

use App\Domain\Legal\Exceptions\LegalVersionNotFound;
use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\OvertimeCalculator;
use App\Domain\Legal\ShiftClassifier;
use App\Enums\ShiftKind;
use App\Models\LegalVersion;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(fn () => $this->seedLegal());

it('aplica la reducción gradual de la jornada ordinaria semanal', function (string $date, int $hours, int $overtimeHours, string $code) {
    $law = app(LegalFramework::class)->for($date);

    expect($law->weeklyOrdinaryMaxMinutes)->toBe($hours * 60)
        ->and($law->overtimeWeeklyMax)->toBe($overtimeHours * 60)
        ->and($law->versionCode)->toBe($code);
})->with([
    '2026 (antes de la reforma LFT)' => ['2026-04-30', 48, 9, 'LFT-PRE-2026'],
    '2026 (reforma vigente)' => ['2026-05-01', 48, 9, 'LFT-2026'],
    '2027' => ['2027-01-01', 46, 9, 'LFT-2027'],
    '2028' => ['2028-07-15', 44, 10, 'LFT-2028'],
    '2029' => ['2029-12-31', 42, 11, 'LFT-2029'],
    '2030' => ['2030-01-01', 40, 12, 'LFT-2030'],
    '2035' => ['2035-06-01', 40, 12, 'LFT-2030'],
]);

it('clasifica jornadas diurna, nocturna y mixta (LFT art. 60)', function (string $start, string $end, ShiftKind $kind) {
    $law = app(LegalFramework::class)->for('2026-11-17');
    $s = CarbonImmutable::parse("2026-11-17 {$start}");
    $e = CarbonImmutable::parse("2026-11-17 {$end}");
    if ($e->lte($s)) {
        $e = $e->addDay();
    }

    expect(app(ShiftClassifier::class)->classify($s, $e, $law))->toBe($kind);
})->with([
    'diurna 09-18' => ['09:00', '18:00', ShiftKind::Day],
    'nocturna 22-05' => ['22:00', '05:00', ShiftKind::Night],
    'mixta 14-22 (2 h nocturnas)' => ['14:00', '22:00', ShiftKind::Mixed],
    'mixta 15:00-23:29 (3 h 29 min nocturnas)' => ['15:00', '23:29', ShiftKind::Mixed],
    'nocturna por 3 h 30 min' => ['16:30', '23:30', ShiftKind::Night],
]);

it('clasifica horas extraordinarias con los topes de 2026 previos a la reforma', function () {
    $law = app(LegalFramework::class)->for('2026-03-02');
    // lunes a jueves con 3 h diarias: el cuarto día rebasa "tres veces por semana"
    $r = app(OvertimeCalculator::class)->classifyWeek([
        '2026-03-02' => 180, '2026-03-03' => 180, '2026-03-04' => 180, '2026-03-05' => 60,
    ], $law);

    expect($r['2026-03-04'])->toMatchArray(['double' => 180, 'triple' => 0, 'over_limit' => 0])
        ->and($r['2026-03-05'])->toMatchArray(['double' => 0, 'triple' => 60, 'over_limit' => 60]);
});

it('clasifica horas extraordinarias con la reforma de 2028 (10 h semanales, 4 h diarias, 4 días)', function () {
    $law = app(LegalFramework::class)->for('2028-03-06');
    $r = app(OvertimeCalculator::class)->classifyWeek([
        '2028-03-06' => 240, '2028-03-07' => 240, '2028-03-08' => 240, // 720 min
        '2028-03-09' => 300,                                             // excede 4 h diarias
    ], $law);

    $double = array_sum(array_column($r, 'double'));
    expect($double)->toBe(600)                                   // tope semanal 10 h al doble
        ->and($r['2028-03-08'])->toMatchArray(['double' => 120, 'triple' => 120, 'over_limit' => 0])
        ->and($r['2028-03-09']['triple'])->toBe(300)
        ->and($r['2028-03-09']['over_limit'])->toBe(180);          // rebasa 4 h de excedente semanal y 4 h diarias
});

it('calcula vacaciones mínimas por antigüedad (LFT art. 76)', function (int $year, int $days) {
    expect(app(LegalFramework::class)->legalVacationDays($year, '2026-11-17'))->toBe($days);
})->with([[1, 12], [2, 14], [3, 16], [4, 18], [5, 20], [6, 22], [10, 22], [11, 24], [16, 26], [21, 28], [26, 30], [31, 32]]);

it('rechaza parámetros legales alterados fuera del procedimiento', function () {
    $v = LegalVersion::query()->where('code', 'LFT-2027')->firstOrFail();
    $params = $v->parameters;
    $params['weekly_ordinary_max_minutes'] = 3000;
    LegalVersion::query()->whereKey($v->id)->update(['parameters' => json_encode($params)]);

    app(LegalFramework::class)->flush();
    app(LegalFramework::class)->for('2027-06-01');
})->throws(LegalVersionNotFound::class, 'checksum');
