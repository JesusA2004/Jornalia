<?php

namespace App\Domain\Attendance;

use App\Enums\AlertSeverity;
use App\Models\ComplianceAlert;
use App\Models\Punch;
use Carbon\CarbonImmutable;

/**
 * Hora oficial del registro (Revisión RH, regla general 3).
 *
 * La hora oficial es la del servidor (America/Mexico_City para mostrar). Si la
 * hora del dispositivo difiere más de 10 minutos de la recepción en servidor se
 * genera la alerta `clock_skew` en Cumplimiento, distinguiendo:
 *  - `offline_late_sync`: captura sin conexión sincronizada después; el reloj del
 *    dispositivo era coherente al sincronizar (o no hay evidencia en contrario).
 *  - `device_clock_altered`: la hora del dispositivo no es coherente con la del
 *    servidor (marcaje en línea desfasado, hora futura o reloj desfasado al
 *    sincronizar). Puede indicar manipulación del reloj.
 *
 * Nunca altera el marcaje: sólo alerta.
 */
final class ClockSkewMonitor
{
    /** Diferencia máxima tolerada entre hora del dispositivo y del servidor. */
    public const THRESHOLD_SECONDS = 600;

    public const KIND_LATE_SYNC = 'offline_late_sync';

    public const KIND_ALTERED = 'device_clock_altered';

    /**
     * Clasifica la diferencia de reloj de un marcaje; null si está dentro de tolerancia.
     */
    public function classify(Punch $punch): ?string
    {
        if ($punch->device_time === null) {
            return null;
        }
        $received = CarbonImmutable::instance($punch->received_at ?? $punch->server_time);
        $device = CarbonImmutable::instance($punch->device_time);
        $diff = (int) $device->diffInSeconds($received, false); // positivo: dispositivo atrás del servidor

        if (abs($diff) <= self::THRESHOLD_SECONDS) {
            return null;
        }
        if ($diff < 0) {
            return self::KIND_ALTERED; // hora del dispositivo en el futuro
        }
        if (! $punch->captured_offline) {
            return self::KIND_ALTERED;
        }
        if ($punch->synced_at !== null) {
            $syncSkew = abs((int) CarbonImmutable::instance($punch->synced_at)->diffInSeconds($received, false));

            return $syncSkew > self::THRESHOLD_SECONDS ? self::KIND_ALTERED : self::KIND_LATE_SYNC;
        }

        return self::KIND_LATE_SYNC;
    }

    /**
     * Evalúa un marcaje recién registrado y abre/actualiza la alerta del día.
     */
    public function evaluate(Punch $punch): ?ComplianceAlert
    {
        $kind = $this->classify($punch);
        if ($kind === null) {
            return null;
        }

        $received = CarbonImmutable::instance($punch->received_at ?? $punch->server_time);
        $minutes = intdiv(abs((int) CarbonImmutable::instance($punch->device_time)->diffInSeconds($received, false)), 60);
        $entry = [
            'punch_uuid' => $punch->uuid,
            'kind' => $kind,
            'type' => $punch->type->value,
            'device_time' => $punch->device_time?->utc()->format('Y-m-d\TH:i:s\Z'),
            'synced_at' => $punch->synced_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'received_at' => $received->utc()->format('Y-m-d\TH:i:s\Z'),
            'difference_minutes' => $minutes,
        ];

        $alert = ComplianceAlert::query()
            ->withoutGlobalScopes()
            ->where('employee_id', $punch->employee_id)
            ->whereDate('date', $punch->local_date)
            ->where('rule_code', 'clock_skew')
            ->first();

        $data = (array) ($alert->data ?? []);
        $entries = array_values(array_filter((array) ($data['punches'] ?? []), fn ($e) => ($e['punch_uuid'] ?? null) !== $punch->uuid));
        $entries[] = $entry;
        $altered = in_array(self::KIND_ALTERED, array_column($entries, 'kind'), true);

        $attributes = [
            'severity' => $altered ? AlertSeverity::Critical : AlertSeverity::Warning,
            'message' => $altered
                ? 'Hora del dispositivo distinta a la oficial por más de 10 min: posible reloj alterado.'
                : 'Marcaje sin conexión sincronizado más de 10 min después de capturarse.',
            'legal_basis' => 'LFT art. 132 fr. XXXIV (registro electrónico de jornada)',
            'data' => ['kind' => $altered ? self::KIND_ALTERED : self::KIND_LATE_SYNC, 'punches' => $entries],
        ];

        if ($alert === null) {
            return ComplianceAlert::query()->create([
                'organization_id' => $punch->organization_id,
                'employee_id' => $punch->employee_id,
                'date' => $punch->local_date->toDateString(),
                'rule_code' => 'clock_skew',
                'status' => 'open',
                ...$attributes,
            ]);
        }

        $alert->fill($attributes);
        if ($alert->status === 'resolved') {
            $alert->fill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null]);
        }
        $alert->save();

        return $alert;
    }
}
