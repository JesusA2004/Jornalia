<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Builders\AttendanceAggregates;
use App\Domain\Reports\Builders\AttendanceDays;
use App\Domain\Reports\Builders\EvidenceReport;
use App\Domain\Reports\Builders\OvertimeReport;
use App\Domain\Reports\Definition\ReportDefinition;
use App\Domain\Reports\Noi\NoiExport;
use App\Models\User;
use Closure;
use Generator;
use InvalidArgumentException;

/**
 * Conecta cada definición con su constructor de datos. Devuelve una fuente de
 * filas en streaming (generador) y una estimación de volumen para decidir si
 * la exportación va a la cola.
 */
final class ReportSources
{
    public function __construct(
        private readonly AttendanceDays $days,
        private readonly AttendanceAggregates $aggregates,
        private readonly ReportQuery $queries,
        private readonly EvidenceReport $evidence,
        private readonly NoiExport $noi,
        private readonly OvertimeReport $overtime,
    ) {}

    /**
     * @return Closure(): iterable<array<string, mixed>>
     */
    public function source(ReportDefinition $d, ReportParameters $p, User $user): Closure
    {
        $o = $d->options;

        return match ($d->builder) {
            'days' => fn () => $this->filteredDays($d, $p, $user),
            'aggregate' => fn () => match ($o['mode'] ?? 'employee') {
                'employee_period' => $this->aggregates->byEmployee($p, $user, byPeriod: true),
                'employee_branch' => $this->aggregates->byEmployeeWithinBranch($p, $user),
                'dimension' => $this->aggregates->byDimension($p, $user, (string) $o['dimension']),
                default => $this->aggregates->byEmployee($p, $user, byPeriod: false),
            },
            'records' => function () use ($o, $p, $user) {
                ['query' => $query, 'map' => $map] = $this->queries->build((string) $o['query'], $p, $user);
                foreach ($query->lazy(500) as $raw) {
                    yield $map($raw);
                }
            },
            'overtime' => fn () => $this->overtime->rows($p, $user, (string) ($o['only'] ?? 'all')),
            'evidence' => fn () => $this->evidence->rows($p, $user),
            'noi' => fn () => $this->noi->rows($p, $user),
            default => throw new InvalidArgumentException("Constructor de reporte desconocido: {$d->builder}"),
        };
    }

    public function estimate(ReportDefinition $d, ReportParameters $p, User $user): int
    {
        if ($d->builder === 'records') {
            return $this->queries->build((string) $d->options['query'], $p, $user)['query']->getCountForPagination();
        }

        return $this->days->estimate($p, $user);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function filteredDays(ReportDefinition $d, ReportParameters $p, User $user): Generator
    {
        $o = $d->options;
        $events = (bool) ($o['events'] ?? false);
        $rows = ($o['order'] ?? 'employee') === 'date'
            ? $this->days->byDate($p, $user, $events)
            : $this->days->byEmployee($p, $user, $events);
        $filter = $o['filter'] ?? null;

        foreach ($rows as $row) {
            $keep = match ($filter) {
                'late' => $row['late_minutes'] > 0,
                'absent' => $row['_absent'] === 1,
                'omission' => $row['omission'] !== '',
                'early' => $row['early_leave_minutes'] > 0,
                'breaks' => $row['break_start'] !== null || $row['meal_start'] !== null || $row['break_minutes'] > 0,
                'worked' => $row['worked_minutes'] > 0 || $row['first_in'] !== null,
                default => true,
            };
            if ($keep) {
                yield $row;
            }
        }
    }
}
