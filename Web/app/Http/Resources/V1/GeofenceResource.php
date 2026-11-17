<?php

namespace App\Http\Resources\V1;

use App\Models\Geofence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `Geofence`.
 *
 * @mixin Geofence
 */
final class GeofenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Geofence $g */
        $g = $this->resource;

        return [
            'id' => $g->id,
            'name' => (string) $g->name,
            'latitude' => (float) $g->latitude,
            'longitude' => (float) $g->longitude,
            'radius_meters' => (int) $g->radius_meters,
        ];
    }
}
