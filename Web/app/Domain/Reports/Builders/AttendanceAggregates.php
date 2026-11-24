<?php

namespace App\Domain\Reports\Builders;

use App\Domain\Reports\ReportParameters;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Concentrados de asistencia a partir de las MISMAS filas día por día del
 * reporte detallado (AttendanceDays), de modo que un concentrado siempre
 * cuadra con su detalle. Dimensiones: colaborador (opcionalmente por
 * semana/quincena/mes), sucursal, región, departamento y puesto.
 */
final class AttendanceAggregates
{
    private const COUNTERS = [
        'scheduled_days' => '_scheduled', 'attended_days' => '_attended', 'punctual_days' => '_punctual', 'absent_days' => '_absent',
        'late_days' => '_late_day', 'early_days' => '_early_day', 'omission_days' => '_omission_day', 'rest_days' => '_rest',
        'vacation_days' => '_vacation', 'leave_days' => '_leave', 'holiday_days' => '_holiday',
    ];

    private const MINUTES = ['late_minutes', 'early_leave_minutes', 'worked_minutes', 'ordinary_minutes', 'extra_minutes', 'night_minutes', 'scheduled_minutes', 'break_minutes'];

    public function __construct(private readonly AttendanceDays $days) {}

    public function estimate(ReportParameters $p, User $user): int
    {
        return $this->days->estimate($p, $user);
    }

    /**
     * Una fila por colaborador (y periodo, si `$byPeriod`), en orden alfabético.
     * Se emite al cambiar de colaborador: memoria acotada a un colaborador.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function byEmployee(ReportParameters $p, User $user, bool $byPeriod): Generator
    {
        $current = null;
        $bucket = [];
        foreach ($this->days->byEmployee($p, $user, withEvents: false) as $day) {
            if ($current !== null && $current !== $day['employee_id']) {
                yield from $this->flush($bucket);
                $bucket = [];
            }
            $current = $day['employee_id'];
            [$periodKey, $periodLabel] = $byPeriod ? $this->period($day['date'], $p->periodUnit) : ['all', $p->from->format('d/m/Y').' al '.$p->to->format('d/m/Y')];
            $bucket[$periodKey] ??= $this->blank($day) + ['period' => $periodLabel, '_period_key' => $periodKey];
            $this->add($bucket[$periodKey], $day);
        }
        yield from $this->flush($bucket);
    }

    /**
     * Una fila por colaborador agrupada por sucursal (consolidada).
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function byEmployeeWithinBranch(ReportParameters $p, User $user): Generator
    {
        $acc = [];
        foreach ($this->days->byEmployee($p, $user, withEvents: false) as $day) {
            $key = ($day['branch'] ?? '~').'|'.$day['employee_id'];
            $acc[$key] ??= $this->blank($day);
            $this->add($acc[$key], $day);
        }
        uksort($acc, fn ($a, $b) => strcmp((string) $a, (string) $b));
        foreach ($acc as $row) {
            yield $this->finish($row);
        }
    }

    /**
     * Una fila por sucursal, región, departamento o puesto.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function byDimension(ReportParameters $p, User $user, string $dimension): Generator
    {
        $acc = [];
        foreach ($this->days->byEmployee($p, $user, withEvents: false) as $day) {
            $name = (string) ($day[$dimension] ?? '');
            $name = $name !== '' ? $name : 'Sin asignar';
            $acc[$name] ??= ['group_name' => $name, '_employees' => []] + array_fill_keys([...array_keys(self::COUNTERS), ...self::MINUTES], 0);
            $acc[$name]['_employees'][$day['employee_id']] = true;
            $this->add($acc[$name], $day);
        }
        ksort($acc, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($acc as $row) {
            $row['employees'] = count($row['_employees']);
            unset($row['_employees']);
            yield $this->finish($row);
        }
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function blank(array $day): array
    {
        return [
            'employee_id' => $day['employee_id'],
            'employee_number' => $day['employee_number'],
            'employee_name' => $day['employee_name'],
            'branch' => $day['branch'],
            'region' => $day['region'],
            'department' => $day['department'],
            'position' => $day['position'],
            '_employee_key' => $day['_employee_key'],
            '_employee_label' => $day['_employee_label'],
            '_branch_key' => $day['branch'] ?? '~',
            '_branch_label' => $day['_branch_label'],
        ] + array_fill_keys([...array_keys(self::COUNTERS), ...self::MINUTES], 0);
    }

    /**
     * @param  array<string, mixed>  $acc
     * @param  array<string, mixed>  $day
     */
    private function add(array &$acc, array $day): void
    {
        foreach (self::COUNTERS as $out => $flag) {
            $acc[$out] += (int) $day[$flag];
        }
        foreach (self::MINUTES as $key) {
            $acc[$key] += (int) $day[$key];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $bucket
     * @return Generator<int, array<string, mixed>>
     */
    private function flush(array $bucket): Generator
    {
        ksort($bucket);
        foreach ($bucket as $row) {
            yield $this->finish($row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finish(array $row): array
    {
        $attended = (int) $row['attended_days'];
        $row['punctuality_rate'] = $attended > 0 ? round(((int) $row['punctual_days']) * 100 / $attended, 1) : null;

        return $row;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function period(string $date, string $unit): array
    {
        $d = CarbonImmutable::parse($date)->settings(['locale' => 'es']);

        return match ($unit) {
            'month' => [$d->format('Y-m'), ucfirst($d->isoFormat('MMMM YYYY'))],
            'fortnight' => $d->day <= 15
                ? [$d->format('Y-m').'-1', '1.ª quincena '.$d->isoFormat('MMMM YYYY')]
                : [$d->format('Y-m').'-2', '2.ª quincena '.$d->isoFormat('MMMM YYYY')],
            default => (function () use ($d) {
                $start = $d->startOfWeek(CarbonImmutable::MONDAY);

                return [$start->toDateString(), 'Semana '.$start->format('d/m').' – '.$start->addDays(6)->format('d/m/Y')];
            })(),
        };
    }
}
