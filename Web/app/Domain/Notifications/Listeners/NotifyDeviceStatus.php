<?php

namespace App\Domain\Notifications\Listeners;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Notifications\DeviceStatusChanged;

/**
 * Avisa al usuario cuando su dispositivo se autoriza o se revoca.
 */
final class NotifyDeviceStatus
{
    public function updated(Device $device): void
    {
        if (! $device->wasChanged('status')) {
            return;
        }
        if (! in_array($device->status, [DeviceStatus::Approved, DeviceStatus::Revoked], true)) {
            return;
        }

        $device->user?->notify((new DeviceStatusChanged((string) $device->device_uuid, (string) $device->name, $device->status->value))
            ->forOrganization((int) $device->organization_id));
    }
}
