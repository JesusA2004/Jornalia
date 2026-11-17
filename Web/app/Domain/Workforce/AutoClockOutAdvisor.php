<?php

namespace App\Domain\Workforce;

use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmploymentAssignment;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleSegment;

/**
 * Presentación y advertencias de la salida automática (hora máxima de salida).
 * La regla de prioridad es la de `App\Domain\Attendance\AutoClockOut`:
 * persona (asignación) > puesto > política de asistencia.
 */
final class AutoClockOutAdvisor
{
    /**
     * Hora vigente y su origen para mostrar en el expediente.
     *
     * @return array{time:?string, source:?string, label:?string, warning:?string}
     */
    public function effective(Employee $employee, ?EmploymentAssignment $assignment): array
    {
        $time = null;
        $source = null;
        $label = null;

        if ($assignment?->auto_clock_out_at) {
            [$time, $source, $label] = [$assignment->auto_clock_out_at, 'person', 'Persona'];
        } elseif ($assignment?->position_id && ($position = Position::query()->withTrashed()->find($assignment->position_id))?->auto_clock_out_at) {
            [$time, $source, $label] = [$position->auto_clock_out_at, 'position', 'Puesto: '.$position->name];
        } else {
            $policy = $assignment?->attendance_policy_id
                ? AttendancePolicy::query()->withTrashed()->find($assignment->attendance_policy_id)
                : AttendancePolicy::query()->where('is_default', true)->first();
            if ($policy?->auto_clock_out_at) {
                [$time, $source, $label] = [$policy->auto_clock_out_at, 'policy', 'Política: '.$policy->name];
            }
        }

        $time = $time !== null ? substr((string) $time, 0, 5) : null;

        return [
            'time' => $time,
            'source' => $source,
            'label' => $label,
            'warning' => $time !== null ? $this->warningFor($assignment?->schedule_id, $time) : null,
        ];
    }

    /**
     * Advertencia para una hora configurada en un puesto o política: revisa los
     * horarios de las asignaciones vigentes que la heredarían.
     */
    public function warningForAssigned(string $column, int $id, ?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        $today = now()->toDateString();
        $scheduleIds = EmploymentAssignment::query()
            ->where($column, $id)->whereNotNull('schedule_id')->whereNull('auto_clock_out_at')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->distinct()->pluck('schedule_id');

        foreach ($scheduleIds as $scheduleId) {
            if (($warning = $this->warningFor((int) $scheduleId, $time)) !== null) {
                return $warning;
            }
        }

        return null;
    }

    /**
     * Advierte si la hora límite cae antes del fin de algún turno diurno del horario.
     */
    public function warningFor(?int $scheduleId, ?string $time): ?string
    {
        if ($scheduleId === null || $time === null || $time === '') {
            return null;
        }
        $schedule = Schedule::query()->withTrashed()->with('segments')->find($scheduleId);
        if ($schedule === null) {
            return null;
        }

        $limit = substr($time, 0, 5);
        $latest = null;
        foreach ($schedule->segments as $segment) {
            /** @var ScheduleSegment $segment */
            $start = substr((string) $segment->starts_at, 0, 5);
            $end = substr((string) $segment->ends_at, 0, 5);
            if ($end <= $start) {
                // Turno que cruza medianoche: la hora límite debe ser posterior a su fin del día siguiente.
                if ($limit < $end || $limit > $start) {
                    $latest = max((string) $latest, $end.' (día siguiente)');
                }

                continue;
            }
            if ($end > $limit && $start < $limit) {
                $latest = max((string) $latest, $end);
            }
        }

        return $latest !== null
            ? "La salida automática ({$limit}) es anterior al fin del turno asignado ({$latest}) en «{$schedule->name}»: cerraría jornadas antes de terminar."
            : null;
    }
}
