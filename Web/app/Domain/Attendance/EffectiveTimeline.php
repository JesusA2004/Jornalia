<?php

namespace App\Domain\Attendance;

use App\Enums\AdjustmentAction;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendanceAdjustment;
use App\Models\Punch;
use Carbon\CarbonImmutable;

/**
 * Construye la línea de tiempo reconocida de un colaborador aplicando, sobre
 * los marcajes originales inmutables, los ajustes de rectificaciones aprobadas.
 */
final class EffectiveTimeline
{
    /**
     * Hora efectiva de un marcaje original: la del servidor; para capturas
     * sin conexión, la declarada por el dispositivo si no es posterior a la de
     * recepción (en cuyo caso se usa la de servidor y se marca anomalía).
     */
    public static function punchTime(Punch $punch): CarbonImmutable
    {
        // Cierre automático: la hora reconocida es la hora límite aplicada.
        if ($punch->source === PunchSource::System && $punch->device_time !== null) {
            return CarbonImmutable::instance($punch->device_time);
        }

        if ($punch->captured_offline && $punch->device_time !== null && $punch->device_time->lte($punch->server_time)) {
            return CarbonImmutable::instance($punch->device_time);
        }

        return CarbonImmutable::instance($punch->server_time);
    }

    /**
     * @return list<EffectiveEvent> ordenados por hora
     */
    public function between(int $employeeId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $margin = 4; // días: cubre capturas offline sincronizadas tarde
        $punches = Punch::query()
            ->where('employee_id', $employeeId)
            ->where('server_time', '>=', $fromUtc->subDays($margin))
            ->where(fn ($q) => $q->where('server_time', '<', $toUtc->addDays($margin)))
            ->orderBy('server_time')
            ->get();

        $adjustments = AttendanceAdjustment::query()
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($fromUtc, $toUtc, $punches) {
                $q->whereBetween('recognized_time', [$fromUtc, $toUtc])
                    ->orWhereIn('target_punch_uuid', $punches->pluck('uuid'));
            })
            ->orderBy('sequence')
            ->get();

        /** @var array<string, AttendanceAdjustment> $byTarget */
        $byTarget = [];
        foreach ($adjustments as $adj) {
            if ($adj->target_punch_uuid !== null) {
                $byTarget[$adj->target_punch_uuid] = $adj; // el último ajuste aprobado prevalece
            }
        }

        $events = [];
        foreach ($punches as $punch) {
            $adj = $byTarget[$punch->uuid] ?? null;
            if ($adj?->action === AdjustmentAction::Void) {
                continue;
            }
            if ($adj?->action === AdjustmentAction::Replace) {
                $events[] = new EffectiveEvent(
                    $adj->type ?? $punch->type,
                    CarbonImmutable::instance($adj->recognized_time ?? $punch->server_time),
                    'rectified',
                    $punch,
                    $adj,
                );

                continue;
            }
            $events[] = new EffectiveEvent($punch->type, self::punchTime($punch), 'original', $punch, null);
        }

        foreach ($adjustments as $adj) {
            if ($adj->action === AdjustmentAction::Add && $adj->recognized_time !== null && $adj->type !== null) {
                $events[] = new EffectiveEvent($adj->type, CarbonImmutable::instance($adj->recognized_time), 'added', null, $adj);
            }
        }

        $events = array_values(array_filter(
            $events,
            fn (EffectiveEvent $e) => $e->time->gte($fromUtc) && $e->time->lt($toUtc),
        ));

        usort($events, fn (EffectiveEvent $a, EffectiveEvent $b) => $a->time <=> $b->time ?: self::order($a->type) <=> self::order($b->type));

        return $events;
    }

    /**
     * Siguiente evento esperado a partir del último evento reconocido.
     */
    public function nextExpected(int $employeeId, CarbonImmutable $nowUtc, int $openSessionMaxHours = 20): PunchType
    {
        $events = $this->between($employeeId, $nowUtc->subHours($openSessionMaxHours), $nowUtc->addMinute());
        $last = end($events) ?: null;

        return match ($last?->type) {
            PunchType::ClockIn, PunchType::BreakEnd => PunchType::ClockOut,
            PunchType::BreakStart => PunchType::BreakEnd,
            default => PunchType::ClockIn,
        };
    }

    /**
     * ¿Es válido registrar `$type` dado el estado actual?
     */
    public function isAllowed(PunchType $type, PunchType $expected): bool
    {
        if ($type === $expected) {
            return true;
        }

        // Estando en jornada, se permite iniciar descanso además de salir.
        return $expected === PunchType::ClockOut && $type === PunchType::BreakStart;
    }

    private static function order(PunchType $t): int
    {
        return match ($t) {
            PunchType::ClockIn => 0,
            PunchType::BreakStart => 1,
            PunchType::BreakEnd => 2,
            PunchType::ClockOut => 3,
        };
    }
}
