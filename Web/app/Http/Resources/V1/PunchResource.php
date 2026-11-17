<?php

namespace App\Http\Resources\V1;

use App\Models\Punch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `Punch`. No expone IP, agente de usuario, dispositivo ni geocerca interna.
 *
 * @mixin Punch
 */
final class PunchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Punch $p */
        $p = $this->resource;
        $hasLocation = $p->latitude !== null && $p->longitude !== null;

        return [
            'uuid' => $p->uuid,
            'type' => $p->type->value,
            'status' => $p->status->value,
            'source' => $p->source->value,
            'server_time' => ApiFormat::dateTime($p->server_time),
            'device_time' => ApiFormat::dateTime($p->device_time),
            'clock_skew_seconds' => $p->clock_skew_seconds === null ? null : (int) $p->clock_skew_seconds,
            'timezone' => $p->timezone,
            'auth_method' => $p->auth_method->value,
            'captured_offline' => (bool) $p->captured_offline,
            'anomalies' => array_values(array_map('strval', (array) $p->anomalies)),
            'location' => $hasLocation ? [
                'latitude' => (float) $p->latitude,
                'longitude' => (float) $p->longitude,
                'accuracy_meters' => $p->accuracy_meters === null ? null : (int) $p->accuracy_meters,
                'captured_at' => ApiFormat::dateTime($p->location_captured_at ?? $p->server_time),
                'is_mocked' => $p->location_mocked === null ? null : (bool) $p->location_mocked,
            ] : null,
            'hash' => $p->hash,
            'sequence' => (int) $p->sequence,
            'rectified' => (int) ($p->getAttribute('adjustments_count') ?? 0) > 0,
        ];
    }

    /**
     * Folio verificable del comprobante: derivado del hash encadenado.
     */
    public static function receiptCode(Punch $punch): string
    {
        return 'ML-'.implode('-', str_split(strtoupper(substr($punch->hash, 0, 12)), 4)).'-'.$punch->sequence;
    }
}
