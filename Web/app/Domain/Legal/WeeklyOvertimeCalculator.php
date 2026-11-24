<?php

namespace App\Domain\Legal;

use App\Enums\ApprovalStatus;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Cálculo semanal de tiempo extraordinario por colaborador (lunes a domingo).
 *
 * Fuente única para Panel, Horas extra, Cumplimiento y la exportación a nómina
 * (NOI). Nunca descarta tiempo registrado: todo minuto posterior al horario es
 * «tiempo extra registrado» y queda pendiente hasta que se autoriza o justifica.
 *
 * Reglas (parámetros de la versión legal vigente al inicio de la semana; ver
 * `LegalFramework`):
 *  - Dobles: hasta `overtimeDailyMax` por día, en máximo `overtimeMaxDaysPerWeek`
 *    días y hasta `overtimeWeeklyMax` por semana (LFT art. 66 y transitorio Cuarto).
 *  - Triples: lo que rebasa el tope semanal; tolerado hasta
 *    `overtimeExcessWeeklyMax` (LFT art. 68).
 *  - Sobre límite legal: lo que rebasa cualquier tope (diario, días por semana,
 *    triples semanales o jornada total diaria `dailyTotalMax`, art. 68).
 *  - Menores de 18 años (fecha de nacimiento del expediente): el tiempo extra está
 *    prohibido (CPEUM art. 123-A fr. XI; LFT art. 178). Todo minuto extra es «sobre
 *    límite» y, si se laboró, se paga con 200 % adicional (triples, art. 178). La
 *    salida real se registra siempre; sólo se bloquea su autorización.
 */
final class WeeklyOvertimeCalculator
{
    public function __construct(
        private readonly LegalFramework $law,
        private readonly OvertimeCalculator $overtime,
    ) {}

    /**
     * Lunes de la semana que contiene `$day`.
     */
    public static function weekStart(CarbonInterface|string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * ¿Es menor de 18 años en la fecha indicada? null si no hay fecha de nacimiento.
     */
    public static function isMinorOn(Employee $employee, CarbonInterface|string $date): ?bool
    {
        if ($employee->birth_date === null) {
            return null;
        }

        return $employee->birth_date->diffInYears(CarbonImmutable::parse($date)) < 18;
    }

    /**
     * Clasifica una semana a partir de minutos por día.
     *
     * @param  array<string, array{worked:int, excess:int, minor?:bool}>  $days  fecha (Y-m-d) => minutos
     * @return array<string, array{excess:int, double:int, triple:int, over_limit:int, minor:bool}>
     */
    public function classify(array $days, LegalParameters $law): array
    {
        ksort($days);
        $adults = [];
        foreach ($days as $date => $d) {
            if (! ($d['minor'] ?? false)) {
                $adults[$date] = max(0, (int) $d['excess']);
            }
        }
        $classified = $this->overtime->classifyWeek($adults, $law);

        $result = [];
        foreach ($days as $date => $d) {
            $excess = max(0, (int) $d['excess']);
            if ($d['minor'] ?? false) {
                // Prohibido para menores: todo es sobre límite y se paga al triple (art. 178).
                $result[$date] = ['excess' => $excess, 'double' => 0, 'triple' => $excess, 'over_limit' => $excess, 'minor' => true];

                continue;
            }
            $c = $classified[$date];
            // Jornada total (ordinaria + extra) por encima del máximo diario (art. 68).
            $totalOver = $law->dailyTotalMax !== null ? max(0, (int) $d['worked'] - $law->dailyTotalMax) : 0;
            $result[$date] = [
                'excess' => $c['excess'],
                'double' => $c['double'],
                'triple' => $c['triple'],
                'over_limit' => min($excess, max($c['over_limit'], $totalOver)),
                'minor' => false,
            ];
        }

        return $result;
    }

    /**
     * Semana completa de un colaborador con su estado de autorización.
     */
    public function forEmployee(Employee $employee, CarbonInterface|string $anyDay): WeeklyOvertime
    {
        return $this->forEmployees([$employee], $anyDay)[$employee->id];
    }

    /**
     * Cálculo semanal para varios colaboradores (exportación a nómina, panel).
     *
     * @param  iterable<Employee>  $employees
     * @return array<int, WeeklyOvertime> indexado por employee_id
     */
    public function forEmployees(iterable $employees, CarbonInterface|string $anyDay): array
    {
        $start = self::weekStart($anyDay);
        $end = $start->addDays(6);
        $law = $this->law->for($start);
        $verification = $this->law->verificationFor($start);

        $list = [];
        foreach ($employees as $employee) {
            $list[$employee->id] = $employee;
        }
        if ($list === []) {
            return [];
        }

        $summaries = WorkdaySummary::query()->whereIn('employee_id', array_keys($list))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['employee_id', 'date', 'worked_minutes', 'excess_minutes', 'scheduled_minutes'])
            ->groupBy('employee_id');
        $records = OvertimeRecord::query()->whereIn('employee_id', array_keys($list))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()->groupBy('employee_id');

        $result = [];
        foreach ($list as $id => $employee) {
            $byDate = ($summaries[$id] ?? collect())->keyBy(fn (WorkdaySummary $s) => $s->date->toDateString());
            $recByDate = ($records[$id] ?? collect())->keyBy(fn (OvertimeRecord $r) => $r->date->toDateString());

            $input = [];
            foreach (CarbonPeriod::create($start, $end) as $day) {
                $date = $day->toDateString();
                $s = $byDate[$date] ?? null;
                // La proyección diaria es la fuente; si no existe (registro previo), se usa
                // el tiempo extra registrado en el propio registro para no ocultarlo.
                $r = $recByDate[$date] ?? null;
                $input[$date] = [
                    'worked' => (int) ($s->worked_minutes ?? 0),
                    'excess' => $s !== null ? (int) $s->excess_minutes : (int) ($r->recorded_excess_minutes ?? 0),
                    'minor' => self::isMinorOn($employee, $date) === true,
                ];
            }
            $classified = $this->classify($input, $law);

            $days = [];
            foreach ($classified as $date => $c) {
                $worked = $input[$date]['worked'];
                $record = $recByDate[$date] ?? null;
                [$authorized, $justified, $pending] = self::authorization($record, $c['excess']);
                $days[] = new WeeklyOvertimeDay(
                    date: $date,
                    workedMinutes: $worked,
                    ordinaryMinutes: max(0, $worked - $c['excess']),
                    recordedMinutes: $c['excess'],
                    doubleMinutes: $c['double'],
                    tripleMinutes: $c['triple'],
                    overLimitMinutes: $c['over_limit'],
                    authorizedMinutes: $authorized,
                    justifiedMinutes: $justified,
                    pendingMinutes: $pending,
                    minor: $c['minor'],
                    status: $record?->status->value,
                    recordId: $record?->id,
                );
            }

            $result[$id] = new WeeklyOvertime(
                employeeId: $id,
                weekStart: $start->toDateString(),
                weekEnd: $end->toDateString(),
                legalVersionCode: $law->versionCode,
                legalVerified: (bool) $verification['verified'],
                legalLabel: (string) $verification['label'],
                minor: self::isMinorOn($employee, $end),
                days: $days,
            );
        }

        return $result;
    }

    /**
     * Desglose de un registro: [autorizado, justificado, pendiente].
     *  - Pendiente: aún sin decisión final.
     *  - Autorizado: minutos aprobados como tiempo extra.
     *  - Justificado: revisado y no autorizado como extra (el tiempo se conserva).
     *
     * @return array{0:int, 1:int, 2:int}
     */
    public static function authorization(?OvertimeRecord $record, int $recorded): array
    {
        if ($recorded <= 0) {
            return [0, 0, 0];
        }
        if ($record === null || $record->status === ApprovalStatus::Pending) {
            return [0, 0, $recorded];
        }
        $authorized = $record->status === ApprovalStatus::Approved ? min($recorded, (int) $record->authorized_minutes) : 0;

        return [$authorized, $recorded - $authorized, 0];
    }
}
