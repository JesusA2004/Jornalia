<?php

namespace App\Domain\Location;

use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Legal\Exceptions\LegalVersionNotFound;
use App\Domain\Legal\LegalFramework;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;

/**
 * Reglas de uso de la ubicación en tiempo real (PDF pantalla 04; LFPDPPP):
 *
 *  - Parámetro por PUESTO: «Solo ubicación al marcar» (`on_punch`, por omisión) o
 *    «Seguimiento durante la jornada» (`during_shift`, puestos de campo).
 *  - El seguimiento inicia con la entrada, se pausa en descanso/comida registrados,
 *    se reanuda al regresar y termina con la salida.
 *  - Contingencia si la salida se omite: el seguimiento se corta al fin del turno
 *    programado + margen configurable (sin turno: entrada + jornada total máxima + margen).
 *    Nunca se genera una salida: sólo deja de aceptarse ubicación.
 *  - Retención de recorridos configurable; la depuración es automática (`ubicacion:depurar`).
 */
final class LocationTracking
{
    public const MODE_ON_PUNCH = 'on_punch';

    public const MODE_DURING_SHIFT = 'during_shift';

    public const MODES = [self::MODE_ON_PUNCH => 'Solo ubicación al marcar', self::MODE_DURING_SHIFT => 'Seguimiento durante la jornada'];

    /** Estados de la jornada en los que el seguimiento se pausa (contrato `tracking_paused_states`). */
    public const PAUSED_STATES = ['break', 'meal'];

    public const SETTINGS_KEY = 'location.policy';

    /** Valores iniciales editables; el plazo de retención lo define Jurídico (pendiente). */
    public const DEFAULTS = ['retention_days' => 90, 'contingency_minutes' => 60, 'retention_confirmed' => false];

    public const PHASE_ACTIVE = 'active';

    public const PHASE_PAUSED = 'paused';

    public const PHASE_OFF = 'off';

    public function __construct(
        private readonly EffectiveTimeline $timeline,
        private readonly ScheduleResolver $schedules,
        private readonly LegalFramework $law,
    ) {}

    /**
     * @return array{retention_days:int, contingency_minutes:int, retention_confirmed:bool}
     */
    public function settings(): array
    {
        $stored = (array) (OrganizationSetting::query()->where('key', self::SETTINGS_KEY)->value('value') ?? []);
        $v = $stored + self::DEFAULTS;

        return [
            'retention_days' => max(7, min(3650, (int) $v['retention_days'])),
            'contingency_minutes' => max(0, min(240, (int) $v['contingency_minutes'])),
            'retention_confirmed' => (bool) $v['retention_confirmed'],
        ];
    }

    /**
     * @param  array{retention_days:int, contingency_minutes:int, retention_confirmed:bool}  $values
     */
    public function save(array $values, ?int $userId): void
    {
        $setting = OrganizationSetting::query()->firstOrNew(['key' => self::SETTINGS_KEY]);
        $setting->value = $values;
        $setting->updated_by = $userId;
        $setting->save();
    }

    public function modeFor(Employee $employee, ?CarbonImmutable $at = null): string
    {
        $position = $employee->assignmentOn(($at ?? CarbonImmutable::now())->toDateString())?->position;
        $mode = $position?->location_tracking;

        return $mode === self::MODE_DURING_SHIFT ? self::MODE_DURING_SHIFT : self::MODE_ON_PUNCH;
    }

    /**
     * Fase del seguimiento en un instante (UTC) y motivo de rechazo cuando no está activo.
     *
     * @return array{mode:string, phase:string, reason:string|null, cutoff_at:CarbonImmutable|null}
     */
    public function phaseAt(Employee $employee, CarbonImmutable $at): array
    {
        $mode = $this->modeFor($employee, $at);
        if ($mode !== self::MODE_DURING_SHIFT) {
            return ['mode' => $mode, 'phase' => self::PHASE_OFF, 'reason' => 'tracking_disabled', 'cutoff_at' => null];
        }

        $events = $this->timeline->between($employee->id, $at->subHours(20), $at->addSecond());
        $events = array_values(array_filter($events, fn ($e) => $e->time->lte($at)));
        $last = end($events) ?: null;

        if ($last === null || $last->type === PunchType::ClockOut) {
            return ['mode' => $mode, 'phase' => self::PHASE_OFF, 'reason' => 'outside_window', 'cutoff_at' => null];
        }
        if ($last->type === PunchType::BreakStart) {
            return ['mode' => $mode, 'phase' => self::PHASE_PAUSED, 'reason' => 'on_break', 'cutoff_at' => null];
        }

        // Entrada abierta: límite de contingencia por salida omitida.
        $clockIn = null;
        foreach (array_reverse($events) as $e) {
            if ($e->type === PunchType::ClockIn) {
                $clockIn = $e;
                break;
            }
            if ($e->type === PunchType::ClockOut) {
                break;
            }
        }
        $cutoff = $this->cutoff($employee, $clockIn?->time ?? $last->time);
        if ($at->gt($cutoff)) {
            return ['mode' => $mode, 'phase' => self::PHASE_OFF, 'reason' => 'contingency_cutoff', 'cutoff_at' => $cutoff];
        }

        return ['mode' => $mode, 'phase' => self::PHASE_ACTIVE, 'reason' => null, 'cutoff_at' => $cutoff];
    }

    /**
     * Fin programado + margen; sin turno, entrada + jornada total máxima legal + margen.
     */
    public function cutoff(Employee $employee, CarbonImmutable $clockIn): CarbonImmutable
    {
        $margin = $this->settings()['contingency_minutes'];
        $tz = $this->schedules->timezoneFor($employee);
        $local = $clockIn->setTimezone($tz);
        foreach ([$local->toDateString(), $local->subDay()->toDateString()] as $date) {
            $shift = $this->schedules->shiftFor($employee, $date);
            if ($shift !== null && $clockIn->betweenIncluded($shift->startsAt->subHours(4), $shift->endsAt)) {
                return $shift->endsAt->addMinutes($margin);
            }
        }

        try {
            $max = $this->law->for($local->toDateString())->dailyTotalMax ?? 720;
        } catch (LegalVersionNotFound) {
            $max = 720;
        }

        return $clockIn->addMinutes($max + $margin);
    }
}
