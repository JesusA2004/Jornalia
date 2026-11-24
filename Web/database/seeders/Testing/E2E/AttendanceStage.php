<?php

namespace Database\Seeders\Testing\E2E;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\DeviceSignature;
use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchRecorder;
use App\Domain\Location\LocationService;
use App\Domain\Security\DeviceService;
use App\Domain\Security\KioskService;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\Kiosk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Marcajes de las últimas semanas por el camino oficial (`PunchRecorder`, con
 * desafío de un solo uso y firma HMAC del teléfono, o `KioskService`). Incluye
 * jornadas completas, retardos, salidas omitidas, capturas sin conexión
 * (sincronizadas en minutos y una horas después), reloj del teléfono alterado,
 * ubicación simulada, fuera de geocerca, kiosco y recorridos de ubicación.
 */
final class AttendanceStage
{
    public function __construct(
        private readonly PunchRecorder $recorder,
        private readonly DeviceService $devices,
        private readonly KioskService $kiosks,
        private readonly LocationService $locations,
        private readonly PeopleStage $people,
    ) {}

    public function schedule(Context $ctx): void
    {
        $terminatedOn = $ctx->fixtures['terminated_on'] ?? '';
        $terminatedOn = is_string($terminatedOn) ? $terminatedOn : '';
        $tracked = [$ctx->workday(1)->toDateString(), $ctx->workday(2)->toDateString()];

        foreach (Roster::all() as $index => $person) {
            if (! in_array($person['app'], ['app', 'kiosk'], true)) {
                continue;
            }
            $hired = $this->people->date((string) $person['hired'], $ctx);
            foreach ($ctx->days() as $day) {
                if ($day->lt($hired) || ($index === Roster::TERMINATED && $terminatedOn !== '' && $day->toDateString() > $terminatedOn)) {
                    continue;
                }
                $events = $this->events($ctx, $index, $person, $day);
                foreach ($events as $event) {
                    $server = $event['at']->addMinutes((int) ($event['opts']['offline_delay'] ?? 0))->addSeconds(Context::vary("sec|{$index}|{$event['at']->getTimestamp()}", 0, 50));
                    if ($server->gt($ctx->realNow->subMinutes(2))) {
                        break; // hoy: lo que aún no ocurre no se registra
                    }
                    $kiosk = $person['app'] === 'kiosk';
                    $ctx->schedule($server, "Marcaje {$index} {$event['type']->value}", fn () => $this->punch($ctx, $index, $event['type'], $event['at'], $event['opts'], $kiosk), 20);
                }

                // Recorrido de ubicación de días anteriores (se sincroniza al cerrar la jornada).
                if (in_array($index, Roster::TRACKED, true) && in_array($day->toDateString(), $tracked, true)) {
                    $in = collect($events)->firstWhere('type', PunchType::ClockIn);
                    $out = collect($events)->firstWhere('type', PunchType::ClockOut);
                    if ($in !== null && $out !== null) {
                        $ctx->schedule($out['at']->addMinutes(4), "Recorrido {$index}", fn () => $this->route($ctx, $index, $in['at'], $out['at']), 30);
                    }
                }
            }
        }
    }

    /**
     * Plan determinista de marcajes de un colaborador en un día.
     *
     * @param  array<string, mixed>  $person
     * @return list<array{type: PunchType, at: CarbonImmutable, opts: array<string, mixed>}>
     */
    public function events(Context $ctx, int $index, array $person, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $o = $ctx->plan[$index][$date] ?? [];
        $special = $ctx->special[$index][$date] ?? [];
        $templates = OrganizationStage::SCHEDULES[$person['schedule']][3];
        $tpl = $templates[$day->dayOfWeekIso] ?? null;
        $work = ! empty($o['work']);
        $planned = $o !== [];

        if ($tpl === null && ! $work) {
            return [];
        }
        $tpl ??= $templates[1];
        if ($ctx->isHoliday($day) && ! in_array($index, Roster::HOLIDAY_WORKERS, true) && ! $work) {
            return [];
        }
        if (! empty($o['absent']) || (! $planned && Context::vary("abs|{$index}|{$date}", 0, 99) < 3)) {
            return [];
        }

        [$s, $e, $break] = $tpl;
        $start = $ctx->at($day, $s);
        $end = $ctx->at($day, $e);
        if ($end->lte($start)) {
            $end = $end->addDay();
        }

        $in = match (true) {
            isset($o['late']) => $ctx->at($day, (string) $o['late']),
            ! $planned && Context::vary("late|{$index}|{$date}", 0, 99) < 9 => $start->addMinutes(Context::vary("lateby|{$index}|{$date}", 15, 38)),
            default => $start->addMinutes(Context::vary("in|{$index}|{$date}", 1, 8)),
        };
        $events = [['type' => PunchType::ClockIn, 'at' => $in, 'opts' => $special['clock_in'] ?? []]];

        if (in_array($index, Roster::BREAKS, true) && $break >= 30) {
            $span = (int) $start->diffInMinutes($end);
            $breakStart = $start->addMinutes(intdiv($span, 2) - 30 + Context::vary("bs|{$index}|{$date}", -5, 8));
            if ($breakStart->gt($in)) {
                $events[] = ['type' => PunchType::BreakStart, 'at' => $breakStart, 'opts' => []];
                $events[] = ['type' => PunchType::BreakEnd, 'at' => $breakStart->addMinutes($break + Context::vary("be|{$index}|{$date}", 0, 3)), 'opts' => []];
            }
        }

        $out = match (true) {
            isset($o['overtime']) => $end->addMinutes((int) $o['overtime'] + Context::vary("ot|{$index}|{$date}", 0, 2)),
            isset($o['early']) => $end->subMinutes((int) $o['early']),
            ! $planned && Context::vary("early|{$index}|{$date}", 0, 99) < 3 => $end->subMinutes(Context::vary("earlyby|{$index}|{$date}", 20, 50)),
            default => $end->addMinutes(Context::vary("out|{$index}|{$date}", 0, 3)),
        };
        $omitted = ! empty($o['no_out']) || (! $planned && Context::vary("omit|{$index}|{$date}", 0, 999) < 12);
        if (! $omitted) {
            $events[] = ['type' => PunchType::ClockOut, 'at' => $out, 'opts' => $special['clock_out'] ?? []];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $opts  offline_delay (min), skew (s), mocked, far (m)
     */
    private function punch(Context $ctx, int $index, PunchType $type, CarbonImmutable $eventAt, array $opts, bool $kiosk): void
    {
        $employee = $ctx->employee($index);
        $user = $ctx->employeeUser($index);
        $ctx->actAs($user);
        $now = CarbonImmutable::now('UTC');

        try {
            if ($kiosk) {
                $model = Kiosk::query()->where('branch_id', $employee->assignmentOn($now)?->branch_id)->where('status', Kiosk::STATUS_ACTIVE)->first();
                if ($model === null) {
                    $ctx->warn("Sin kiosco activo para el colaborador {$index}.");

                    return;
                }
                $this->kiosks->punch($model, [
                    'employee_number' => (string) $employee->employee_number,
                    'pin' => $ctx->pin,
                    'type' => $type->value,
                    'client_uuid' => (string) Str::uuid(),
                    'device_time' => $now->format('Y-m-d\TH:i:s.v\Z'),
                    'timezone' => Context::TZ,
                    'captured_offline' => false,
                ], '10.20.0.15', 'MRLanaKiosco/1.4.0 (Android 13; Lenovo Tab M10)');
                $ctx->count('marcajes_kiosco');

                return;
            }

            $phone = $ctx->devices[$index] ?? null;
            if ($phone === null) {
                $ctx->warn("Sin teléfono autorizado para el colaborador {$index}.");

                return;
            }
            $device = $phone['device']->refresh();
            $offline = isset($opts['offline_delay']);
            $deviceTime = $offline ? $eventAt->utc() : $now->addSeconds((int) ($opts['skew'] ?? 0));
            $raw = $deviceTime->utc()->format('Y-m-d\TH:i:s.v\Z');
            $clientUuid = (string) Str::uuid();
            $nonce = $offline ? null : $this->devices->challenge($device)->nonce;
            $signature = DeviceSignature::sign($phone['secret'], DeviceSignature::canonical($clientUuid, $type->value, $raw, $nonce, (string) $device->device_uuid));
            [$lat, $lng, $accuracy] = $this->location($ctx, $index, $eventAt, (int) ($opts['far'] ?? 0));

            $input = new PunchInput(
                clientUuid: $clientUuid,
                type: $type,
                source: $offline ? PunchSource::MobileOffline : PunchSource::Mobile,
                authMethod: $index % 5 === 0 ? AuthMethod::DeviceCredential : AuthMethod::DeviceBiometric,
                deviceTime: CarbonImmutable::parse($raw),
                deviceTimeRaw: $raw,
                timezone: Context::TZ,
                deviceUuid: (string) $device->device_uuid,
                nonce: $nonce,
                signature: $signature,
                capturedOffline: $offline,
                latitude: $lat,
                longitude: $lng,
                accuracyMeters: $accuracy,
                locationCapturedAt: $deviceTime,
                locationMocked: ! empty($opts['mocked']) ? true : false,
                faceVerificationId: null,
                ipAddress: $offline ? null : '189.203.'.($index % 200).'.'.(10 + $index),
                userAgent: 'MRLanaAsistencias/1.4.0 ('.($device->platform === 'ios' ? 'iOS 17.6' : 'Android 14').')',
                kioskId: null,
                syncedAt: $offline ? $now : null,
            );
            $result = $this->recorder->record($user, $employee, $input, $device);
            $ctx->count($offline ? 'marcajes_sin_conexion' : 'marcajes_app');
            if (! empty($opts['fixture'])) {
                $ctx->fixtures[(string) $opts['fixture']] = (string) $result['punch']->uuid;
            }
        } catch (AttendanceException $e) {
            $ctx->count('marcajes_rechazados');
            $ctx->warn("Marcaje rechazado ({$index}, {$type->value}, {$eventAt->setTimezone(Context::TZ)->format('Y-m-d H:i')}): {$e->getMessage()}");
        }
    }

    /**
     * Coordenadas cercanas a la sucursal (o a `$farMeters` de distancia).
     *
     * @return array{0: float, 1: float, 2: int}
     */
    private function location(Context $ctx, int $index, CarbonImmutable $at, int $farMeters): array
    {
        $branch = $ctx->branches[Roster::all()[$index]['branch']];
        $key = "{$index}|{$at->getTimestamp()}";
        $dLat = Context::vary("lat|{$key}", -22, 22) + $farMeters;
        $dLng = Context::vary("lng|{$key}", -22, 22);
        $lat = (float) $branch->latitude + $dLat / 111_320;
        $lng = (float) $branch->longitude + $dLng / (111_320 * cos(deg2rad((float) $branch->latitude)));

        return [round($lat, 6), round($lng, 6), Context::vary("acc|{$key}", 6, 24)];
    }

    /**
     * Puntos de ubicación (cada ~12 min) entre entrada y salida, enviados por la app.
     */
    public function route(Context $ctx, int $index, CarbonImmutable $from, CarbonImmutable $to, int $everyMinutes = 12): int
    {
        $phone = $ctx->devices[$index] ?? null;
        if ($phone === null) {
            return 0;
        }
        $branch = $ctx->branches[Roster::all()[$index]['branch']];
        $points = [];
        $k = 0;
        for ($t = $from->addMinutes(3); $t->lt($to->subMinutes(2)) && count($points) < 60; $t = $t->addMinutes($everyMinutes)) {
            $k++;
            $radius = 350 + 900 * abs(sin($k / 6));
            $lat = (float) $branch->latitude + ($radius * sin($k / 4 + $index)) / 111_320;
            $lng = (float) $branch->longitude + ($radius * cos($k / 5 + $index)) / (111_320 * cos(deg2rad((float) $branch->latitude)));
            $points[] = [
                'uuid' => (string) Str::uuid(),
                'latitude' => round($lat, 6),
                'longitude' => round($lng, 6),
                'accuracy_m' => Context::vary("racc|{$index}|{$k}|{$from->getTimestamp()}", 5, 22),
                'speed_mps' => round(Context::vary("spd|{$index}|{$k}", 0, 90) / 10, 1),
                'heading' => (float) Context::vary("hdg|{$index}|{$k}", 0, 359),
                'is_mocked' => false,
                'app_state' => $k % 3 === 0 ? 'background' : 'foreground',
                'captured_at' => $t->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }
        if ($points === []) {
            return 0;
        }

        $user = $ctx->employeeUser($index);
        $ctx->actAs($user);
        $result = $this->locations->ingest($user, $ctx->employee($index)->refresh(), $phone['device'], $points);
        $ctx->count('puntos_ubicacion', $result['accepted']);
        if ($result['rejected'] > 0) {
            $ctx->count('puntos_ubicacion_rechazados', $result['rejected']);
        }

        return $result['accepted'];
    }
}
