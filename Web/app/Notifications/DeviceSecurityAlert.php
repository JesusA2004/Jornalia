<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Aviso a Sistemas / Recursos Humanos sobre eventos de seguridad de dispositivos:
 *  - `device_change`: un colaborador inició sesión en un teléfono distinto (requiere autorización).
 *  - `new_device`: primer teléfono del colaborador pendiente de autorización.
 *  - `device_in_use`: alguien intentó usar un teléfono ya vinculado a otro colaborador.
 */
final class DeviceSecurityAlert extends AppNotification
{
    public function __construct(
        public readonly string $type,
        public readonly string $employeeName,
        public readonly ?string $otherName,
        public readonly int $deviceId,
        public readonly ?string $deviceName,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::System;
    }

    public function kind(): string
    {
        return "device.{$this->type}";
    }

    public function priority(): string
    {
        return 'high';
    }

    public function title(User $notifiable): string
    {
        return match ($this->type) {
            'device_change' => 'Cambio de teléfono por autorizar',
            'new_device' => 'Teléfono nuevo por autorizar',
            default => 'Intento de uso de teléfono ajeno',
        };
    }

    public function body(User $notifiable): string
    {
        return match ($this->type) {
            'device_change' => "{$this->employeeName} inició sesión en otro teléfono ({$this->deviceName}). Revisa y autoriza en Dispositivos.",
            'new_device' => "{$this->employeeName} vinculó un teléfono ({$this->deviceName}). Autorízalo en Dispositivos.",
            default => "{$this->otherName} intentó iniciar sesión en el teléfono de {$this->employeeName}. El acceso fue bloqueado.",
        };
    }

    public function data(User $notifiable): array
    {
        return ['device_id' => (string) $this->deviceId, 'type' => $this->type];
    }
}
