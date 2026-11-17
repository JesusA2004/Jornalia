<?php

namespace App\Http\Resources\V1;

use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `Device`. Nunca expone el secreto ni el token push.
 *
 * @mixin Device
 */
final class DeviceResource extends JsonResource
{
    private ?string $currentUuid = null;

    public function current(?string $deviceUuid): self
    {
        $this->currentUuid = $deviceUuid;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Device $d */
        $d = $this->resource;
        $current = $this->currentUuid ?? ApiContext::tokenDeviceUuid($request);

        return [
            'id' => $d->id,
            'device_uuid' => $d->device_uuid,
            'name' => $d->name,
            'platform' => $d->platform,
            'model' => $d->model,
            'status' => $d->status->value,
            'pending_reason' => $d->status->value === 'pending' ? ($d->pending_reason ?? 'new_device') : null,
            'approved_at' => ApiFormat::dateTime($d->approved_at),
            'revoked_at' => ApiFormat::dateTime($d->revoked_at),
            'last_seen_at' => ApiFormat::dateTime($d->last_seen_at),
            'is_current' => $current !== null && $current === $d->device_uuid,
        ];
    }
}
