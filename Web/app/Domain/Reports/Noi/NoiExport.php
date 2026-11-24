<?php

namespace App\Domain\Reports\Noi;

use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Domain\Reports\Builders\AttendanceDays;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\Support\ReportFormat;
use App\Models\Employee;
use App\Models\User;
use Generator;

/**
 * Movimientos de incidencias y tiempo extraordinario para capturar en NOI.
 *
 * Fuentes (sin recalcular reglas laborales):
 *  - Faltas, vacaciones e incidencias: filas día por día de AttendanceDays
 *    (proyección WorkdayCalculator + incidencias/vacaciones APROBADAS).
 *  - Horas extra dobles/triples: minutos AUTORIZADOS del cálculo semanal del
 *    dominio (WeeklyOvertimeCalculator, semana lunes–domingo).
 *    Si se autorizó menos de lo registrado, lo autorizado cubre primero las
 *    horas dobles y después las triples (orden en que la LFT las genera en la semana).
 *
 * Cantidades: días como entero; horas como decimal con dos posiciones calculado
 * con aritmética entera desde minutos.
 */
final class NoiExport
{
    public function __construct(
        private readonly AttendanceDays $days,
        private readonly WeeklyOvertimeCalculator $weekly,
    ) {}

    public function estimate(ReportParameters $p, User $user): int
    {
        return $this->days->estimate($p, $user);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(ReportParameters $p, User $user): Generator
    {
        $config = NoiConfig::current();
        $current = null;
        $buffer = [];
        foreach ($this->days->byEmployee($p, $user, withEvents: false) as $day) {
            if ($current !== null && $current['employee_id'] !== $day['employee_id']) {
                yield from $this->flush($current, $buffer, $p, $config);
                $buffer = [];
            }
            $current = $day;
            foreach ($this->dayMovements($day, $config) as $movement) {
                $buffer[] = $movement;
            }
        }
        if ($current !== null) {
            yield from $this->flush($current, $buffer, $p, $config);
        }
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private function dayMovements(array $day, array $config): array
    {
        $out = [];
        $concepts = $config['concepts'];
        if ($day['_absent'] && ($concepts['absence']['enabled'] ?? false)) {
            $out[] = $this->movement($day, $concepts['absence']['code'], $concepts['absence']['label'], 'Días', '1');
        }
        if ($day['_vacation'] && $day['scheduled_minutes'] > 0 && ($concepts['vacation']['enabled'] ?? false)) {
            $out[] = $this->movement($day, $concepts['vacation']['code'], $concepts['vacation']['label'], 'Días', '1');
        }
        if ($day['_late_day'] && ($concepts['late']['enabled'] ?? false)) {
            $out[] = $this->movement($day, $concepts['late']['code'], $concepts['late']['label'], 'Minutos', (string) $day['late_minutes']);
        }
        if ($day['_leave']) {
            foreach ($day['_incidences'] as $inc) {
                if ($config['only_payroll_incidences'] && ! $inc['affects_payroll']) {
                    continue;
                }
                $code = $config['incidence_types'][$inc['code']] ?? $inc['code'];
                $out[] = $inc['minutes'] !== null
                    ? $this->movement($day, $code, $inc['name'], 'Horas', ReportFormat::hoursDecimal((int) $inc['minutes'], $config['decimal_separator']))
                    : $this->movement($day, $code, $inc['name'], 'Días', '1');
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  list<array<string, mixed>>  $buffer
     * @param  array<string, mixed>  $config
     * @return Generator<int, array<string, mixed>>
     */
    private function flush(array $employee, array $buffer, ReportParameters $p, array $config): Generator
    {
        $concepts = $config['concepts'];
        $model = Employee::query()->withTrashed()->find($employee['employee_id']);
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        for ($week = WeeklyOvertimeCalculator::weekStart($p->from); $model !== null && $week->lte($p->to); $week = $week->addWeek()) {
            foreach ($this->weekly->forEmployee($model, $week)->days as $d) {
                if ($d->date < $from || $d->date > $to || $d->authorizedMinutes <= 0) {
                    continue;
                }
                // Lo autorizado cubre primero las dobles y después las triples.
                $double = min($d->authorizedMinutes, $d->doubleMinutes);
                $triple = min($d->authorizedMinutes - $double, $d->tripleMinutes);
                $day = ['date' => $d->date] + $employee;
                if ($double > 0 && ($concepts['overtime_double']['enabled'] ?? false)) {
                    $buffer[] = $this->movement($day, $concepts['overtime_double']['code'], $concepts['overtime_double']['label'], 'Horas', ReportFormat::hoursDecimal($double, $config['decimal_separator']), $double);
                }
                if ($triple > 0 && ($concepts['overtime_triple']['enabled'] ?? false)) {
                    $buffer[] = $this->movement($day, $concepts['overtime_triple']['code'], $concepts['overtime_triple']['label'], 'Horas', ReportFormat::hoursDecimal($triple, $config['decimal_separator']), $triple);
                }
            }
        }

        usort($buffer, fn ($a, $b) => [$a['date'], $a['concept_code']] <=> [$b['date'], $b['concept_code']]);
        foreach ($buffer as $row) {
            yield $row;
        }
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function movement(array $day, string $code, string $name, string $unit, string $quantity, int $minutes = 0): array
    {
        return [
            'employee_number' => $day['employee_number'],
            'employee_name' => $day['employee_name'],
            'branch' => $day['branch'],
            'date' => $day['date'],
            'concept_code' => $code,
            'concept_name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'overtime_minutes' => $minutes,
            '_employee_key' => $day['_employee_key'],
            '_employee_label' => $day['_employee_label'],
        ];
    }
}
