<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Dispositivo autorizado o revocado.
 */
final class DeviceStatusChanged extends AppNotification
{
    public function __construct(
        public readonly string $deviceUuid,
        public readonly string $deviceName,
        public readonly string $status,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::System;
    }

    public function kind(): string
    {
        return "device.{$this->status}";
    }

    public function title(User $notifiable): string
    {
        return $this->status === 'approved' ? 'Dispositivo autorizado' : 'Dispositivo revocado';
    }

    public function body(User $notifiable): string
    {
        return $this->status === 'approved'
            ? "{$this->deviceName} ya puede registrar tu asistencia."
            : "{$this->deviceName} ya no puede registrar tu asistencia.";
    }

    public function data(User $notifiable): array
    {
        return ['device_uuid' => $this->deviceUuid, 'status' => $this->status];
    }
}
