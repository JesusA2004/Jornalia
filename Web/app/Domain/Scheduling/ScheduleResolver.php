<?php

namespace App\Domain\Scheduling;

use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\ShiftClassifier;
use App\Models\Employee;
use App\Models\EmploymentAssignment;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\ScheduleSegment;
use Carbon\CarbonImmutable;

/**
 * Resuelve el turno programado de un colaborador en una fecha civil,
 * considerando asignación vigente, ciclos rotativos, excepciones y festivos.
 */
final class ScheduleResolver
{
    /** @var array<int, Schedule> */
    private array $schedules = [];

    public function __construct(
        private readonly LegalFramework $law,
        private readonly ShiftClassifier $classifier,
    ) {}

    public function timezoneFor(Employee $employee, ?EmploymentAssignment $assignment = null): string
    {
        $assignment ??= $employee->assignmentOn(CarbonImmutable::now());

        return $assignment?->workCenter->timezone
            ?? $assignment?->branch?->workCenter->timezone
            ?? $employee->organization->timezone
            ?? config('app.timezone', 'UTC');
    }

    /**
     * Turno del día (o null si es descanso / sin horario).
     */
    public function shiftFor(Employee $employee, string $date, ?EmploymentAssignment $assignment = null): ?ScheduledShift
    {
        $assignment ??= $employee->assignmentOn($date);
        if ($assignment === null) {
            return null;
        }

        $tz = $this->timezoneFor($employee, $assignment);

        // Sólo los cambios de horario autorizados por RH surten efecto.
        $exception = ScheduleException::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('status', ScheduleException::STATUS_APPROVED)
            ->latest('id')
            ->first();

        if ($exception !== null) {
            if ($exception->is_rest_day || $exception->starts_at === null || $exception->ends_at === null) {
                return null;
            }

            return $this->build($date, (string) $exception->starts_at, (string) $exception->ends_at, (int) $exception->break_minutes, $tz, $assignment->schedule, true);
        }

        $schedule = $this->schedule($assignment->schedule_id);
        if ($schedule === null) {
            return null;
        }

        $segments = $this->segmentsFor($schedule, $date);
        if ($segments === []) {
            return null;
        }

        // Para horarios partidos: el turno abarca del primer inicio al último fin,
        // y el tiempo entre segmentos se suma como descanso programado.
        $first = $segments[0];
        $last = $segments[count($segments) - 1];
        $break = array_sum(array_map(fn (ScheduleSegment $s) => (int) $s->break_minutes, $segments));

        $shift = $this->build($date, (string) $first->starts_at, (string) $last->ends_at, $break, $tz, $schedule);
        if (count($segments) > 1) {
            $worked = 0;
            foreach ($segments as $s) {
                $a = CarbonImmutable::parse("{$date} {$s->starts_at}", $tz);
                $b = CarbonImmutable::parse("{$date} {$s->ends_at}", $tz);
                if ($b->lte($a)) {
                    $b = $b->addDay();
                }
                $worked += (int) $a->diffInMinutes($b) - (int) $s->break_minutes;
            }
            $shift = new ScheduledShift($shift->date, $shift->startsAt, $shift->endsAt, $shift->kind, max(0, $shift->spanMinutes() - $worked), $shift->toleranceMinutes, $shift->scheduleId, $shift->scheduleName);
        }

        return $shift;
    }

    public function isRestDay(Employee $employee, string $date, ?EmploymentAssignment $assignment = null): bool
    {
        $assignment ??= $employee->assignmentOn($date);

        return $assignment !== null && $this->shiftFor($employee, $date, $assignment) === null && $assignment->schedule_id !== null;
    }

    public function holidayOn(Employee $employee, string $date, ?EmploymentAssignment $assignment = null): ?Holiday
    {
        $assignment ??= $employee->assignmentOn($date);
        $workCenterId = $assignment->work_center_id ?? $assignment?->branch?->work_center_id;

        return Holiday::query()
            ->whereDate('date', $date)
            ->where('is_mandatory_rest', true)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $employee->organization_id))
            ->where(fn ($q) => $q->whereNull('work_center_id')->when($workCenterId, fn ($q2) => $q2->orWhere('work_center_id', $workCenterId)))
            ->orderByRaw('organization_id is null')
            ->first();
    }

    /**
     * Minutos semanales programados de un horario (para validar contra el máximo legal).
     */
    public function weeklyScheduledMinutes(Schedule $schedule): int
    {
        $total = 0;
        foreach ($schedule->segments as $segment) {
            $a = CarbonImmutable::parse('2026-01-05 '.$segment->starts_at);
            $b = CarbonImmutable::parse('2026-01-05 '.$segment->ends_at);
            if ($b->lte($a)) {
                $b = $b->addDay();
            }
            $total += (int) $a->diffInMinutes($b) - (int) $segment->break_minutes;
        }
        $cycle = max(1, (int) $schedule->cycle_length_days);

        return intdiv($total * 7, $cycle);
    }

    /**
     * @return list<ScheduleSegment>
     */
    private function segmentsFor(Schedule $schedule, string $date): array
    {
        $day = CarbonImmutable::parse($date);
        $cycle = max(1, (int) $schedule->cycle_length_days);

        if ($cycle === 7 && $schedule->cycle_anchor_date === null) {
            $index = $day->dayOfWeekIso - 1;
        } else {
            $anchor = CarbonImmutable::parse($schedule->cycle_anchor_date ?? '2026-01-05');
            $diff = (int) $anchor->startOfDay()->diffInDays($day->startOfDay(), false);
            $index = (($diff % $cycle) + $cycle) % $cycle;
        }

        return array_values($schedule->segments
            ->where('day_index', $index)
            ->sortBy('ordinal')
            ->all());
    }

    private function schedule(?int $id): ?Schedule
    {
        if ($id === null) {
            return null;
        }

        return $this->schedules[$id] ??= Schedule::query()->with('segments')->find($id);
    }

    private function build(string $date, string $start, string $end, int $break, string $tz, ?Schedule $schedule, bool $fromException = false): ScheduledShift
    {
        $localStart = CarbonImmutable::parse("{$date} {$start}", $tz);
        $localEnd = CarbonImmutable::parse("{$date} {$end}", $tz);
        if ($localEnd->lte($localStart)) {
            $localEnd = $localEnd->addDay();
        }

        $kind = $schedule->shift_kind ?? $this->classifier->classify($localStart, $localEnd, $this->law->for($date));

        return new ScheduledShift(
            date: $date,
            startsAt: $localStart->utc(),
            endsAt: $localEnd->utc(),
            kind: $kind,
            breakMinutes: $break,
            toleranceMinutes: (int) ($schedule->tolerance_minutes ?? 0),
            scheduleId: $schedule?->id,
            scheduleName: $fromException ? 'Excepción de horario' : ($schedule->name ?? 'Horario'),
            fromException: $fromException,
        );
    }
}
