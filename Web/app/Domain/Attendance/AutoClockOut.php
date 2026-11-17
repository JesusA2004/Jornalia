<?php

namespace App\Domain\Attendance;

use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Events\PunchRecorded;
use App\Models\Employee;
use App\Models\EmploymentAssignment;
use App\Models\Position;
use App\Models\Punch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Cierre automático de jornada (hora máxima de salida configurable).
 *
 * Prioridad: persona (asignación vigente) > puesto > política de asistencia.
 *
 * Transparencia:
 *  - El sistema registra una SALIDA identificada como automática
 *    (source=system, auth_method=system, anomalía `auto_clock_out`), encadenada e
 *    inmutable. Nunca se presenta como marcaje del trabajador.
 *  - `server_time` = momento en que el sistema la registró; `device_time` = hora
 *    límite aplicada (hora reconocida).
 *  - El tiempo posterior al cierre se conserva aparte (`after_cutoff_minutes`) y no
 *    se reconoce como tiempo extraordinario.
 *  - Si el trabajador marcó su salida antes, no ocurre nada.
 */
final class AutoClockOut
{
    public function __construct(
        private readonly HashChain $chain,
        private readonly EffectiveTimeline $timeline,
        private readonly ScheduleResolver $schedules,
        private readonly PolicyResolver $policies,
    ) {}

    /**
     * Hora límite configurada (HH:MM:SS) para el colaborador, o null si no aplica.
     */
    public function configuredTime(Employee $employee, ?EmploymentAssignment $assignment): ?string
    {
        if ($assignment?->auto_clock_out_at) {
            return (string) $assignment->auto_clock_out_at;
        }
        if ($assignment?->position_id) {
            $position = Position::query()->withTrashed()->find($assignment->position_id);
            if ($position?->auto_clock_out_at) {
                return (string) $position->auto_clock_out_at;
            }
        }
        $policy = $this->policies->for($employee, $assignment);

        return $policy->auto_clock_out_at ? (string) $policy->auto_clock_out_at : null;
    }

    /**
     * Instante UTC de cierre para una jornada iniciada en `$clockIn`.
     */
    public function cutoffFor(Employee $employee, CarbonImmutable $clockIn, ?EmploymentAssignment $assignment = null): ?CarbonImmutable
    {
        $assignment ??= $employee->assignmentOn($clockIn);
        $time = $this->configuredTime($employee, $assignment);
        if ($time === null) {
            return null;
        }

        $tz = $this->schedules->timezoneFor($employee, $assignment);
        $local = $clockIn->setTimezone($tz);
        $cutoff = CarbonImmutable::parse($local->toDateString().' '.$time, $tz);
        if ($cutoff->lte($local)) {
            // Entrada posterior a la hora límite del día: aplica la del día siguiente
            // (turnos nocturnos). Para jornadas diurnas no se configura así.
            $cutoff = $cutoff->addDay();
        }

        return $cutoff->utc();
    }

    /**
     * Cierra jornadas abiertas cuya hora límite ya pasó. Idempotente.
     */
    public function closeIfDue(Employee $employee, ?CarbonImmutable $now = null): ?Punch
    {
        $now ??= CarbonImmutable::now('UTC');
        $events = $this->timeline->between($employee->id, $now->subHours(30), $now->addMinute());
        $last = end($events) ?: null;
        if ($last === null || $last->type === PunchType::ClockOut) {
            return null;
        }

        $openIn = null;
        foreach (array_reverse($events) as $e) {
            if ($e->type === PunchType::ClockIn) {
                $openIn = $e;
                break;
            }
            if ($e->type === PunchType::ClockOut) {
                break;
            }
        }
        if ($openIn === null) {
            return null;
        }

        $cutoff = $this->cutoffFor($employee, $openIn->time);
        if ($cutoff === null || $now->lt($cutoff)) {
            return null;
        }

        $employment = $employee->employmentOn($openIn->time);
        $tz = $this->schedules->timezoneFor($employee);

        $punch = $this->chain->transaction(function () use ($employee, $employment, $cutoff, $now, $tz) {
            $link = $this->chain->next($employee->organization_id, Punch::class);
            $punch = new Punch([
                'uuid' => (string) Str::uuid7(),
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'employment_id' => $employment?->id,
                'device_id' => null,
                'recorded_by' => null,
                'client_uuid' => (string) Str::uuid7(),
                'type' => PunchType::ClockOut,
                'source' => PunchSource::System,
                'server_time' => $now,
                'device_time' => $cutoff,
                'clock_skew_seconds' => null,
                'timezone' => $tz,
                'local_date' => $cutoff->setTimezone($tz)->toDateString(),
                'auth_method' => AuthMethod::System,
                'signature_valid' => false,
                'captured_offline' => false,
                'status' => PunchStatus::Accepted,
                'anomalies' => ['auto_clock_out'],
                'sequence' => $link['sequence'],
                'previous_hash' => $link['previous_hash'],
                'created_at' => $now,
            ]);
            $punch->hash = $this->chain->hash($link['previous_hash'], HashChain::punchPayload($punch));
            $punch->save();

            return $punch;
        });

        PunchRecorded::dispatch($punch);

        return $punch;
    }
}
