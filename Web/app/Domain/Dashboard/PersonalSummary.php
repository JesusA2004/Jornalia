<?php

namespace App\Domain\Dashboard;

use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\PolicyResolver;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Vacations\VacationService;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\RectificationRequest;
use App\Models\VacationRequest;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Resumen del propio colaborador (vista del panel sin permisos de gestión).
 */
final class PersonalSummary
{
    public function __construct(
        private readonly EffectiveTimeline $timeline,
        private readonly ScheduleResolver $schedules,
        private readonly PolicyResolver $policies,
        private readonly VacationService $vacations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Employee $employee): array
    {
        $tz = $this->schedules->timezoneFor($employee);
        $now = CarbonImmutable::now($tz);
        $date = $now->toDateString();
        $dayStart = $now->startOfDay();

        $shift = $this->schedules->shiftFor($employee, $date);
        $holiday = $this->schedules->holidayOn($employee, $date);
        $events = $this->timeline->between($employee->id, $dayStart->utc(), $dayStart->addDay()->utc());
        $policy = $this->policies->for($employee);
        $next = $this->timeline->nextExpected($employee->id, CarbonImmutable::now('UTC'));

        $from = $dayStart->subDays(13);
        $summaries = WorkdaySummary::query()->where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), $date])->get()->keyBy(fn ($s) => $s->date->toDateString());

        $series = [];
        foreach (CarbonPeriod::create($from, $dayStart) as $d) {
            $s = $summaries[$d->toDateString()] ?? null;
            $series[] = [
                'date' => $d->toDateString(),
                'worked_hours' => $s ? round($s->worked_minutes / 60, 1) : 0,
                'late_minutes' => (int) ($s->late_minutes ?? 0),
                'status' => $s?->status->value,
                'status_label' => $s?->status->label(),
            ];
        }

        $todaySummary = $summaries[$date] ?? null;

        try {
            $balance = $this->vacations->balance($employee);
        } catch (ValidationException) {
            $balance = null;
        }

        $pending = fn (string $model) => $model::query()->where('employee_id', $employee->id)->where('status', ApprovalStatus::Pending)->count();

        $assignment = $employee->assignmentOn($date);

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'number' => $employee->employee_number,
                'branch' => $assignment?->branch?->name,
                'position' => $assignment?->position?->name,
            ],
            'today' => [
                'date' => $date,
                'timezone' => $tz,
                'shift' => $shift ? [
                    'starts_at' => $shift->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
                    'ends_at' => $shift->endsAt->utc()->format('Y-m-d\TH:i:s\Z'),
                    'schedule_name' => $shift->scheduleName,
                    'break_minutes' => $shift->breakMinutes,
                    'tolerance_minutes' => $shift->toleranceMinutes,
                ] : null,
                'holiday' => $holiday?->name,
                'events' => array_map(fn ($e) => [
                    'type' => $e->type->value,
                    'type_label' => $e->type->label(),
                    'time' => $e->time->utc()->format('Y-m-d\TH:i:s\Z'),
                    'origin' => $e->origin,
                ], $events),
                'worked_minutes' => (int) ($todaySummary->worked_minutes ?? 0),
                'late_minutes' => (int) ($todaySummary->late_minutes ?? 0),
                'status' => $todaySummary?->status->value,
                'status_label' => $todaySummary?->status->label(),
            ],
            'web_punch' => [
                'allowed' => (bool) $policy->web_punch_allowed && $employee->employmentOn($date) !== null,
                'next_type' => $next->value,
                'next_label' => $next->label(),
                'can_break' => $next === PunchType::ClockOut,
                'policy' => (string) $policy->name,
            ],
            'series' => $series,
            'period_totals' => [
                'worked_minutes' => (int) $summaries->sum('worked_minutes'),
                'late_minutes' => (int) $summaries->sum('late_minutes'),
                'late_days' => $summaries->where('late_minutes', '>', 0)->count(),
                'excess_minutes' => (int) $summaries->sum('excess_minutes'),
            ],
            'vacation' => $balance,
            'pending' => [
                'rectifications' => $pending(RectificationRequest::class),
                'incidences' => $pending(Incidence::class),
                'vacations' => $pending(VacationRequest::class),
            ],
        ];
    }
}
