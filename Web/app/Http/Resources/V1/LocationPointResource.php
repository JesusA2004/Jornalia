<?php

namespace App\Http\Resources\V1;

use App\Models\LocationPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `LocationPoint & { received_at }`.
 *
 * @mixin LocationPoint
 */
final class LocationPointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LocationPoint $p */
        $p = $this->resource;

        return [
            'uuid' => $p->uuid,
            'latitude' => (float) $p->latitude,
            'longitude' => (float) $p->longitude,
            'accuracy_m' => $p->accuracy_m === null ? null : (int) $p->accuracy_m,
            'heading' => $p->heading === null ? null : (float) $p->heading,
            'speed_mps' => $p->speed_mps === null ? null : (float) $p->speed_mps,
            'altitude_m' => $p->altitude_m === null ? null : (float) $p->altitude_m,
            'captured_at' => ApiFormat::dateTime($p->captured_at),
            'app_state' => $p->app_state,
            'is_mocked' => $p->is_mocked === null ? null : (bool) $p->is_mocked,
            'received_at' => ApiFormat::dateTime($p->received_at),
        ];
    }
}
