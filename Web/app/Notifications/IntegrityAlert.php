<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Alerta crítica a RH y Sistemas: la verificación de integridad detectó
 * alteraciones o el anclaje externo falló.
 */
final class IntegrityAlert extends AppNotification
{
    public const VERIFICATION_FAILED = 'integrity.verification_failed';

    public const ANCHOR_FAILED = 'integrity.anchor_failed';

    public function __construct(
        public readonly string $type,
        public readonly int $findings = 0,
        public readonly ?string $detail = null,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::System;
    }

    public function kind(): string
    {
        return $this->type;
    }

    public function priority(): string
    {
        return 'high';
    }

    public function title(User $notifiable): string
    {
        return $this->type === self::VERIFICATION_FAILED
            ? 'Integridad del registro: alteración detectada'
            : 'Integridad del registro: falló el anclaje externo';
    }

    public function body(User $notifiable): string
    {
        return $this->type === self::VERIFICATION_FAILED
            ? "La verificación automática encontró {$this->findings} inconsistencia(s). Revisa Cumplimiento › Integridad del registro."
            : 'No se pudo anclar la cadena fuera de la base de datos'.($this->detail ? ": {$this->detail}" : '.').' Revisa Cumplimiento › Integridad del registro.';
    }

    public function data(User $notifiable): array
    {
        return ['type' => $this->type, 'url' => '/cumplimiento/integridad'];
    }

    protected function extraDatabase(): array
    {
        return ['url' => '/cumplimiento/integridad'];
    }
}
