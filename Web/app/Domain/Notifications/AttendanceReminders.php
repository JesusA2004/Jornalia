<?php

namespace App\Domain\Notifications;

use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Notifications\Models\NotificationDispatch;
use App\Domain\Scheduling\ScheduledShift;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\Organization;
use App\Models\VacationRequest;
use App\Notifications\AttendanceReminder;
use Carbon\CarbonImmutable;

/**
 * Avisos automáticos de asistencia (se ejecuta cada 5 min; idempotente por
 * colaborador, fecha civil y tipo mediante `notification_dispatches`).
 *
 *  - Entrada pendiente: turno de hoy iniciado + tolerancia + gracia, sin
 *    entrada efectiva; no aplica en descanso, festivo, vacaciones ni
 *    incidencia aprobada. Una sola vez por día.
 *  - Salida pendiente: X min después del fin de turno con jornada abierta.
 *
 * Debe ejecutarse dentro del contexto de la organización (`runAs`).
 */
final class AttendanceReminders
{
    /** Ventana máxima tras el fin de turno para recordar la salida. */
    private const CLOCK_OUT_WINDOW_HOURS = 4;

    /** Margen antes del inicio del turno en que una entrada cuenta para ese turno. */
    private const EARLY_CLOCK_IN_HOURS = 3;

    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly EffectiveTimeline $timeline,
        private readonly NotificationSettings $settings,
    ) {}

    /**
     * @return array{late:int, clock_out:int}
     */
    public function run(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $config = $this->settings->all();
        $sent = ['late' => 0, 'clock_out' => 0];
        if (! $config['late']['enabled'] && ! $config['clock_out']['enabled']) {
            return $sent;
        }

        Employee::query()->where('status', 'active')->whereNotNull('user_id')->with('user')
            ->chunkById(200, function ($employees) use ($organization, $now, $config, &$sent) {
                foreach ($employees as $employee) {
                    $result = $this->forEmployee($organization, $employee, $now, $config);
                    $sent['late'] += $result['late'];
                    $sent['clock_out'] += $result['clock_out'];
                }
            });

        return $sent;
    }

    /**
     * @param  array{late: array{enabled: bool, grace_minutes: int}, clock_out: array{enabled: bool, minutes_after: int}}  $config
     * @return array{late:int, clock_out:int}
     */
    public function forEmployee(Organization $organization, Employee $employee, CarbonImmutable $now, array $config): array
    {
        $sent = ['late' => 0, 'clock_out' => 0];
        if ($employee->user === null) {
            return $sent;
        }

        $tz = $this->schedules->timezoneFor($employee);
        $today = $now->setTimezone($tz)->toDateString();
        $dates = [$now->setTimezone($tz)->subDay()->toDateString(), $today];

        foreach ($dates as $date) {
            $assignment = $employee->assignmentOn($date);
            $shift = $assignment ? $this->schedules->shiftFor($employee, $date, $assignment) : null;
            if ($shift === null || $now->lt($shift->startsAt)) {
                continue;
            }
            if ($this->isExempt($employee, $date)) {
                continue;
            }

            if ($config['late']['enabled'] && $this->lateDue($employee, $shift, $now, $config['late']['grace_minutes'])
                && $this->claim($organization, $employee, AttendanceReminder::LATE_CLOCK_IN, $date)) {
                $employee->user->notify((new AttendanceReminder(AttendanceReminder::LATE_CLOCK_IN, $date, $shift->startsAt->setTimezone($tz)->format('H:i')))->forOrganization($organization->id));
                $sent['late']++;
            }

            if ($config['clock_out']['enabled'] && $this->clockOutDue($employee, $shift, $now, $config['clock_out']['minutes_after'])
                && $this->claim($organization, $employee, AttendanceReminder::CLOCK_OUT, $date)) {
                $employee->user->notify((new AttendanceReminder(AttendanceReminder::CLOCK_OUT, $date, $shift->endsAt->setTimezone($tz)->format('H:i')))->forOrganization($organization->id));
                $sent['clock_out']++;
            }
        }

        return $sent;
    }

    private function lateDue(Employee $employee, ScheduledShift $shift, CarbonImmutable $now, int $grace): bool
    {
        $threshold = $shift->startsAt->addMinutes($shift->toleranceMinutes + $grace);
        if ($now->lt($threshold) || $now->gte($shift->endsAt)) {
            return false;
        }
        if ($this->timeline->nextExpected($employee->id, $now) !== PunchType::ClockIn) {
            return false;
        }

        return ! $this->hasClockIn($employee, $shift, $now);
    }

    private function clockOutDue(Employee $employee, ScheduledShift $shift, CarbonImmutable $now, int $minutesAfter): bool
    {
        $at = $shift->endsAt->addMinutes($minutesAfter);
        if ($now->lt($at) || $now->gte($at->addHours(self::CLOCK_OUT_WINDOW_HOURS))) {
            return false;
        }
        if ($this->timeline->nextExpected($employee->id, $now) === PunchType::ClockIn) {
            return false;
        }

        return $this->hasClockIn($employee, $shift, $now);
    }

    private function hasClockIn(Employee $employee, ScheduledShift $shift, CarbonImmutable $now): bool
    {
        foreach ($this->timeline->between($employee->id, $shift->startsAt->subHours(self::EARLY_CLOCK_IN_HOURS), $now->addMinute()) as $event) {
            if ($event->type === PunchType::ClockIn) {
                return true;
            }
        }

        return false;
    }

    /** Festivo de descanso obligatorio, vacaciones o incidencia aprobadas. */
    private function isExempt(Employee $employee, string $date): bool
    {
        if ($this->schedules->holidayOn($employee, $date) !== null) {
            return true;
        }

        foreach ([VacationRequest::class, Incidence::class] as $model) {
            $covered = $model::query()
                ->where('employee_id', $employee->id)
                ->where('status', ApprovalStatus::Approved)
                ->whereDate('starts_on', '<=', $date)
                ->whereDate('ends_on', '>=', $date)
                ->exists();
            if ($covered) {
                return true;
            }
        }

        return false;
    }

    /** Reclama el envío (índice único): true sólo la primera vez. */
    private function claim(Organization $organization, Employee $employee, string $type, string $date, string $reference = ''): bool
    {
        return NotificationDispatch::query()->insertOrIgnore([
            'organization_id' => $organization->id,
            'employee_id' => $employee->id,
            'type' => $type,
            'local_date' => $date,
            'reference' => $reference,
            'created_at' => now(),
        ]) === 1;
    }
}
