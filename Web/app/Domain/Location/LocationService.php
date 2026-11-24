<?php

namespace App\Domain\Location;

use App\Domain\Scheduling\ScheduleResolver;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LocationPoint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Ubicación de trabajo en tiempo real (mismo modelo que la app de Auditoría).
 *
 * Sólo se aceptan puntos de puestos con «Seguimiento durante la jornada» y
 * mientras la jornada está abierta (entrada sin salida), fuera de descanso o
 * comida y antes del límite de contingencia (ver LocationTracking). Cualquier
 * otro punto se rechaza: no se rastrea fuera de la jornada laboral.
 * Los puntos son evidencia append-only (salvo la depuración legal programada).
 */
final class LocationService
{
    public const FOREGROUND_INTERVAL_S = 15;

    public const BACKGROUND_INTERVAL_S = 60;

    public const FOREGROUND_DISTANCE_M = 10;

    public const BACKGROUND_DISTANCE_M = 50;

    private const MAX_FUTURE_S = 120;

    private const MAX_AGE_H = 24;

    private const MAX_ACCURACY_M = 5000;

    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly LocationTracking $tracking,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $points
     * @return array{accepted:int, duplicates:int, rejected:int, rejections:list<array{index:int, uuid:string|null, code:string}>, settings:array<string,mixed>}
     */
    public function ingest(User $user, Employee $employee, ?Device $device, array $points): array
    {
        $now = CarbonImmutable::now('UTC');
        $accepted = 0;
        $duplicates = 0;
        $rejections = [];
        $latest = null;

        foreach ($points as $i => $p) {
            $uuid = isset($p['uuid']) && is_string($p['uuid']) && Str::isUuid($p['uuid']) ? $p['uuid'] : null;
            $code = $this->validate($p, $uuid, $now);
            $capturedAt = $code === null ? CarbonImmutable::parse((string) $p['captured_at'])->utc() : null;

            if ($code === null) {
                // Rechazo por regla de uso: puesto sin seguimiento, fuera de jornada,
                // en descanso/comida o después del límite de contingencia.
                $code = $this->tracking->phaseAt($employee, $capturedAt)['reason'];
            }
            if ($code !== null) {
                $rejections[] = ['index' => $i, 'uuid' => $uuid, 'code' => $code];

                continue;
            }

            try {
                LocationPoint::query()->create([
                    'uuid' => $uuid,
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'user_id' => $user->id,
                    'device_id' => $device?->id,
                    'latitude' => (float) $p['latitude'],
                    'longitude' => (float) $p['longitude'],
                    'accuracy_m' => isset($p['accuracy_m']) ? (int) round((float) $p['accuracy_m']) : null,
                    'heading' => isset($p['heading']) ? (float) $p['heading'] : null,
                    'speed_mps' => isset($p['speed_mps']) ? max(0, (float) $p['speed_mps']) : null,
                    'altitude_m' => isset($p['altitude_m']) ? (float) $p['altitude_m'] : null,
                    'is_mocked' => isset($p['is_mocked']) ? (bool) $p['is_mocked'] : null,
                    'app_state' => $p['app_state'] === 'background' ? 'background' : 'foreground',
                    'captured_at' => $capturedAt,
                    'received_at' => $now,
                ]);
                $accepted++;
                $latest = $latest === null || $capturedAt->gt($latest) ? $capturedAt : $latest;
            } catch (UniqueConstraintViolationException) {
                $duplicates++;
            }
        }

        if ($latest !== null && ($employee->last_location_at === null || $latest->gt($employee->last_location_at))) {
            $employee->forceFill(['last_location_at' => $latest])->saveQuietly();
        }

        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => count($rejections),
            'rejections' => $rejections,
            'settings' => $this->settings($employee),
        ];
    }

    /**
     * @return array{window: array<string, mixed>, mode: string, phase: string, paused_states: list<string>, interval_s: array{foreground:int, background:int}, distance_m: array{foreground:int, background:int}}
     */
    public function settings(Employee $employee): array
    {
        $now = CarbonImmutable::now('UTC');
        $tz = $this->schedules->timezoneFor($employee);
        $today = $now->setTimezone($tz)->toDateString();
        [$start, $end] = $this->shiftWindow($employee, $today);
        $next = null;
        if ($start === null || $now->gt($end)) {
            for ($i = 1; $i <= 7 && $next === null; $i++) {
                [$s] = $this->shiftWindow($employee, CarbonImmutable::parse($today)->addDays($i)->toDateString());
                $next = $s;
            }
        }

        $phase = $this->tracking->phaseAt($employee, $now);

        return [
            'mode' => $phase['mode'],
            'phase' => $phase['phase'],
            'paused_states' => LocationTracking::PAUSED_STATES,
            'window' => [
                'enabled' => $phase['mode'] === LocationTracking::MODE_DURING_SHIFT,
                'active_now' => $phase['phase'] === LocationTracking::PHASE_ACTIVE,
                'starts_at' => $start?->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => ($phase['cutoff_at'] ?? $end)?->format('Y-m-d\TH:i:s\Z'),
                'next_start_at' => $next?->format('Y-m-d\TH:i:s\Z'),
                'timezone' => $tz,
            ],
            'interval_s' => ['foreground' => self::FOREGROUND_INTERVAL_S, 'background' => self::BACKGROUND_INTERVAL_S],
            'distance_m' => ['foreground' => self::FOREGROUND_DISTANCE_M, 'background' => self::BACKGROUND_DISTANCE_M],
        ];
    }

    /**
     * ¿Se acepta ubicación en este instante? (seguimiento activo del puesto y jornada abierta).
     */
    public function inWindow(Employee $employee, CarbonImmutable $at): bool
    {
        return $this->tracking->phaseAt($employee, $at)['phase'] === LocationTracking::PHASE_ACTIVE;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function shiftWindow(Employee $employee, string $date): array
    {
        $shift = $this->schedules->shiftFor($employee, $date);

        return $shift ? [$shift->startsAt->subMinutes(30), $shift->endsAt->addMinutes(60)] : [null, null];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function validate(array $p, ?string $uuid, CarbonImmutable $now): ?string
    {
        if ($uuid === null || ! isset($p['latitude'], $p['longitude'], $p['captured_at'], $p['app_state'])) {
            return 'invalid_point';
        }
        $lat = $p['latitude'];
        $lng = $p['longitude'];
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
            return 'invalid_coordinates';
        }
        if (isset($p['accuracy_m']) && (! is_numeric($p['accuracy_m']) || (float) $p['accuracy_m'] < 0 || (float) $p['accuracy_m'] > self::MAX_ACCURACY_M)) {
            return 'invalid_accuracy';
        }
        try {
            $at = CarbonImmutable::parse((string) $p['captured_at']);
        } catch (\Throwable) {
            return 'invalid_point';
        }
        if ($at->gt($now->addSeconds(self::MAX_FUTURE_S))) {
            return 'future_time';
        }
        if ($at->lt($now->subHours(self::MAX_AGE_H))) {
            return 'too_old';
        }

        return null;
    }
}
