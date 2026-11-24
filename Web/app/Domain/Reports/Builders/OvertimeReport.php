<?php

namespace App\Domain\Reports\Builders;

use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Domain\Legal\WeeklyOvertimeDay;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\Support\ReportFormat;
use App\Enums\ApprovalStatus;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Tiempo extraordinario día por día con el cálculo semanal del dominio
 * (WeeklyOvertimeCalculator, lunes a domingo): registrado, dobles, triples,
 * sobre límite legal, autorizado, justificado y pendiente. No se recalcula
 * ninguna regla aquí.
 */
final class OvertimeReport
{
    public function __construct(
        private readonly ScopedEmployees $employees,
        private readonly WeeklyOvertimeCalculator $weekly,
    ) {}

    public function estimate(ReportParameters $p, User $user): int
    {
        return $this->employees->count($p, $user) * $p->days();
    }

    /**
     * @param  'all'|'double'|'triple'  $only
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(ReportParameters $p, User $user, string $only = 'all'): Generator
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        foreach ($this->employees->chunks($p, $user) as $chunk) {
            /** @var array<int, list<WeeklyOvertimeDay>> $byEmployee */
            $byEmployee = [];
            for ($week = WeeklyOvertimeCalculator::weekStart($p->from); $week->lte($p->to); $week = $week->addWeek()) {
                foreach ($this->weekly->forEmployees($chunk, $week) as $employeeId => $result) {
                    foreach ($result->days as $day) {
                        if ($day->date >= $from && $day->date <= $to && $day->recordedMinutes > 0) {
                            $byEmployee[$employeeId][] = $day;
                        }
                    }
                }
            }
            $recordIds = [];
            foreach ($byEmployee as $days) {
                foreach ($days as $day) {
                    if ($day->recordId !== null) {
                        $recordIds[] = $day->recordId;
                    }
                }
            }
            $records = OvertimeRecord::query()->with('authorizedBy:id,name')->whereKey($recordIds)->get()->keyBy('id');

            foreach ($chunk as $employee) {
                foreach ($byEmployee[$employee->id] ?? [] as $day) {
                    if (($only === 'double' && $day->doubleMinutes === 0) || ($only === 'triple' && $day->tripleMinutes === 0)) {
                        continue;
                    }
                    $status = $day->status ?? ApprovalStatus::Pending->value;
                    if ($p->status && $status !== $p->status) {
                        continue;
                    }
                    $record = $day->recordId ? $records->get($day->recordId) : null;
                    yield $this->row($employee, $day, $status, $record);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Employee $employee, WeeklyOvertimeDay $day, string $status, ?OvertimeRecord $record): array
    {
        $assignment = $employee->assignmentOn($day->date);
        $name = $employee->fullName() ?: '—';
        $date = CarbonImmutable::parse($day->date);

        return [
            'employee_number' => $employee->employee_number,
            'employee_name' => $name,
            'branch' => $assignment?->branch?->name,
            'department' => $assignment?->department?->name,
            'position' => $assignment?->position?->name,
            'date' => $day->date,
            'weekday' => ReportFormat::weekday($date),
            'week_start' => WeeklyOvertimeCalculator::weekStart($date)->toDateString(),
            'worked_minutes' => $day->workedMinutes,
            'recorded_excess_minutes' => $day->recordedMinutes,
            'double_minutes' => $day->doubleMinutes,
            'triple_minutes' => $day->tripleMinutes,
            'over_limit_minutes' => $day->overLimitMinutes,
            'authorized_minutes' => $day->authorizedMinutes,
            'justified_minutes' => $day->justifiedMinutes,
            'pending_minutes' => $day->pendingMinutes,
            'status' => $status,
            'status_label' => (ApprovalStatus::tryFrom($status)?->label() ?? $status).($day->minor ? ' · menor de edad' : ''),
            'authorized_by' => $record?->authorizedBy?->name,
            'justification' => $record?->justification,
            '_employee_key' => $employee->id,
            '_employee_label' => $name.($employee->employee_number ? ' · Núm. '.$employee->employee_number : ''),
        ];
    }
}
