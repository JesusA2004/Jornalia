<?php

namespace App\Domain\Attendance;

use App\Domain\Compliance\ComplianceEvaluator;
use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\ShiftClassifier;
use App\Domain\Scheduling\ScheduledShift;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\WorkdayStatus;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\VacationRequest;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;

/**
 * Proyección diaria recalculable a partir de fuentes inmutables (marcajes +
 * ajustes aprobados), horario vigente, festivos e incidencias aprobadas.
 *
 * Criterios documentados (docs/legal/MATRIZ_CUMPLIMIENTO.md):
 *  - Tiempo trabajado = presencia entre entrada y salida menos descansos
 *    REGISTRADOS fuera de jornada. Si el descanso no se registra, no se descuenta
 *    (LFT art. 64: si no puede salir del lugar, el descanso es tiempo efectivo).
 *  - Retardo: entrada posterior al inicio + tolerancia; se informa el total de
 *    minutos desde el inicio programado. La tolerancia nunca altera el marcaje.
 *  - Tiempo extra registrado: trabajo más allá de lo programado. Nunca se
 *    descarta: alimenta `overtime_records` como «pendiente» para que el jefe lo
 *    autorice o justifique.
 *  - Omisiones de checada: entrada sin salida (`missing_clock_out`) o salida sin
 *    entrada (`missing_clock_in`). El sistema NO completa marcajes; sólo una
 *    rectificación firmada por el colaborador corrige el día.
 *  - Falta injustificada: la determina el sistema cuando no hay marcaje ni
 *    vacaciones/incidencia aprobada en un día programado ya concluido.
 *  - Atribución de turnos nocturnos: una entrada pertenece a la fecha del turno
 *    programado cuya ventana la contiene; si no hay turno, a su fecha civil.
 */
final class WorkdayCalculator
{
    public function __construct(
        private readonly EffectiveTimeline $timeline,
        private readonly ScheduleResolver $schedules,
        private readonly LegalFramework $law,
        private readonly ShiftClassifier $classifier,
        private readonly ComplianceEvaluator $compliance,
    ) {}

    public function compute(Employee $employee, string $date, bool $persist = true): WorkdaySummary
    {
        $employment = $employee->employmentOn($date);
        $assignment = $employment?->assignmentOn($date);
        $tz = $this->schedules->timezoneFor($employee, $assignment);
        $law = $this->law->for($date);

        $shift = $assignment ? $this->schedules->shiftFor($employee, $date, $assignment) : null;
        $holiday = $this->schedules->holidayOn($employee, $date, $assignment);

        $sessions = $this->sessionsFor($employee, $date, $tz, $shift);

        $worked = 0;
        $breaks = 0;
        $night = 0;
        $open = false;
        $firstIn = null;
        $lastOut = null;
        $rectified = false;
        $anomalies = [];

        // Todo el tiempo registrado cuenta: no existe hora de corte ni salida
        // automática. Lo que rebase el horario se envía a revisión como tiempo extra.
        foreach ($sessions as $s) {
            $firstIn ??= $s['in'];
            $rectified = $rectified || $s['rectified'];
            if ($s['out'] === null) {
                $open = true;

                continue;
            }
            $lastOut = $s['out'];

            $presence = (int) $s['in']->diffInMinutes($s['out']);
            $break = min($s['break'], $presence);
            $worked += max(0, $presence - $break);
            $breaks += $break;
            $night += $this->classifier->nightMinutes($s['in']->setTimezone($tz), $s['out']->setTimezone($tz), $law);
            if ($s['break_open']) {
                $anomalies[] = 'break_not_closed';
            }
        }

        // Salida sin entrada reconocida (omisión de checada de entrada).
        $orphanOut = $this->orphanClockOut($employee, $date, $tz, $shift);
        if ($orphanOut !== null) {
            $anomalies[] = 'missing_clock_in';
            $rectified = $rectified || $orphanOut->isRectified();
            $lastOut ??= $orphanOut->time;
        }

        $scheduled = $shift?->scheduledMinutes() ?? 0;
        $late = 0;
        $early = 0;
        $excess = 0;
        if ($shift !== null && $firstIn !== null) {
            $graceEnd = $shift->startsAt->addMinutes($shift->toleranceMinutes);
            if ($firstIn->gt($graceEnd)) {
                $late = (int) $shift->startsAt->diffInMinutes($firstIn);
            }
            if ($lastOut !== null && ! $open && $lastOut->lt($shift->endsAt)) {
                $early = (int) $lastOut->diffInMinutes($shift->endsAt);
            }
            $comparable = $breaks > 0 ? $scheduled : $shift->spanMinutes();
            $excess = max(0, $worked - $comparable);
        } elseif ($shift === null && $worked > 0) {
            // Trabajo en descanso, festivo o sin horario: todo es excedente a revisar.
            $excess = $worked;
        }

        $status = $this->status($employee, $date, $tz, $shift, $holiday !== null, $sessions !== [] || $orphanOut !== null, $open || $orphanOut !== null, $late > 0, $assignment !== null && $assignment->schedule_id !== null);
        if ($open && CarbonImmutable::parse($date, $tz)->addDay()->endOfDay()->isPast()) {
            $anomalies[] = 'missing_clock_out';
        }

        $summary = WorkdaySummary::query()->firstOrNew(['employee_id' => $employee->id, 'date' => $date]);
        $summary->fill([
            'organization_id' => $employee->organization_id,
            'employment_id' => $employment?->id,
            'branch_id' => $assignment?->branch_id,
            'department_id' => $assignment?->department_id,
            'schedule_id' => $shift?->scheduleId,
            'shift_kind' => $shift?->kind,
            'scheduled_start' => $shift?->startsAt,
            'scheduled_end' => $shift?->endsAt,
            'scheduled_minutes' => $scheduled,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'worked_minutes' => $worked,
            'break_minutes' => $breaks,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'excess_minutes' => $excess,
            'night_minutes' => $night,
            'status' => $status,
            'has_rectifications' => $rectified,
            'anomalies' => array_values(array_unique($anomalies)),
            'legal_version_code' => $law->versionCode,
            'computed_at' => now(),
        ]);

        if ($persist) {
            $summary->save();
            $this->compliance->evaluateDay($employee, $summary, $law);
        }

        return $summary;
    }

    /**
     * Agrupa los eventos reconocidos en sesiones entrada→salida atribuidas a $date.
     *
     * @return list<array{in: CarbonImmutable, out: CarbonImmutable|null, break: int, break_open: bool, rectified: bool}>
     */
    public function sessionsFor(Employee $employee, string $date, string $tz, ?ScheduledShift $shift): array
    {
        $dayStart = CarbonImmutable::parse($date, $tz)->startOfDay()->utc();
        $events = $this->timeline->between($employee->id, $dayStart->subDay(), $dayStart->addDays(2));

        $prevShift = $this->schedules->shiftFor($employee, CarbonImmutable::parse($date)->subDay()->toDateString());
        $nextShift = $this->schedules->shiftFor($employee, CarbonImmutable::parse($date)->addDay()->toDateString());

        $sessions = [];
        $current = null;
        foreach ($events as $event) {
            if ($event->type === PunchType::ClockIn) {
                if ($current !== null) {
                    $sessions[] = $current; // entrada sin salida previa: sesión abierta
                }
                $current = $this->attribute($event->time, $date, $tz, $shift, $prevShift, $nextShift) === $date
                    ? ['in' => $event->time, 'out' => null, 'break' => 0, 'break_open' => false, 'break_start' => null, 'rectified' => $event->isRectified()]
                    : null;

                continue;
            }
            if ($current === null) {
                continue;
            }
            $current['rectified'] = $current['rectified'] || $event->isRectified();
            if ($event->type === PunchType::BreakStart && $current['break_start'] === null) {
                $current['break_start'] = $event->time;
                $current['break_open'] = true;
            } elseif ($event->type === PunchType::BreakEnd && $current['break_start'] !== null) {
                $current['break'] += (int) $current['break_start']->diffInMinutes($event->time);
                $current['break_start'] = null;
                $current['break_open'] = false;
            } elseif ($event->type === PunchType::ClockOut) {
                // Descanso sin cierre: NO se descuenta (no se presume tiempo no trabajado);
                // queda `break_open` para generar anomalía y revisión.
                $current['out'] = $event->time;
                $sessions[] = $current;
                $current = null;
            }
        }
        if ($current !== null) {
            $sessions[] = $current;
        }

        return array_map(fn ($s) => [
            'in' => $s['in'], 'out' => $s['out'], 'break' => $s['break'], 'break_open' => $s['break_open'], 'rectified' => $s['rectified'],
        ], $sessions);
    }

    /**
     * Salida reconocida atribuible a `$date` sin entrada previa en la misma sesión.
     * Se usa la ventana del turno programado (fin + 6 h) o, sin turno, la fecha civil.
     */
    private function orphanClockOut(Employee $employee, string $date, string $tz, ?ScheduledShift $shift): ?EffectiveEvent
    {
        $dayStart = CarbonImmutable::parse($date, $tz)->startOfDay()->utc();
        $events = $this->timeline->between($employee->id, $dayStart->subDay(), $dayStart->addDays(2));

        $inSession = false;
        foreach ($events as $event) {
            if ($event->type === PunchType::ClockIn) {
                $inSession = true;

                continue;
            }
            if ($event->type !== PunchType::ClockOut) {
                continue;
            }
            if ($inSession) {
                $inSession = false;

                continue;
            }
            $belongs = $shift !== null
                ? $event->time->gte($shift->startsAt) && $event->time->lte($shift->endsAt->addHours(6))
                : $event->time->setTimezone($tz)->toDateString() === $date;
            if ($belongs) {
                return $event;
            }
        }

        return null;
    }

    private function attribute(CarbonImmutable $time, string $date, string $tz, ?ScheduledShift $shift, ?ScheduledShift $prev, ?ScheduledShift $next): string
    {
        foreach ([$shift, $prev, $next] as $candidate) {
            if ($candidate !== null && $time->gte($candidate->startsAt->subHours(4)) && $time->lte($candidate->startsAt->addHours(6))) {
                return $candidate->date;
            }
        }

        return $time->setTimezone($tz)->toDateString();
    }

    private function status(Employee $employee, string $date, string $tz, ?ScheduledShift $shift, bool $holiday, bool $worked, bool $open, bool $late, bool $hasSchedule): WorkdayStatus
    {
        if ($worked) {
            if ($open) {
                return WorkdayStatus::Incomplete;
            }
            if ($holiday) {
                return WorkdayStatus::HolidayWorked;
            }
            if ($shift === null && $hasSchedule) {
                return WorkdayStatus::RestDayWorked;
            }

            return $late ? WorkdayStatus::Late : WorkdayStatus::Present;
        }

        if ($this->onApproved(VacationRequest::class, $employee, $date)) {
            return WorkdayStatus::Vacation;
        }
        if ($this->onApproved(Incidence::class, $employee, $date)) {
            return WorkdayStatus::Leave;
        }
        if ($holiday) {
            return WorkdayStatus::Holiday;
        }
        if ($shift === null) {
            return $hasSchedule ? WorkdayStatus::RestDay : WorkdayStatus::Unscheduled;
        }
        if ($shift->endsAt->isFuture()) {
            return WorkdayStatus::Future;
        }

        return WorkdayStatus::Absent;
    }

    /**
     * @param  class-string<VacationRequest|Incidence>  $model
     */
    private function onApproved(string $model, Employee $employee, string $date): bool
    {
        return $model::query()
            ->where('employee_id', $employee->id)
            ->where('status', ApprovalStatus::Approved)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->exists();
    }
}
