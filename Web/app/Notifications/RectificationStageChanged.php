<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Aviso de cambio de etapa de una rectificación (bandeja + push).
 *
 * `audience`: employee (el colaborador) | approver (jefe que autoriza) | validator (RH).
 */
final class RectificationStageChanged extends AppNotification
{
    public function __construct(
        public readonly int $rectificationId,
        public readonly string $event,
        public readonly string $audience,
        public readonly ?string $detail = null,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Request;
    }

    public function kind(): string
    {
        return 'rectification.'.$this->event;
    }

    public function priority(): string
    {
        return $this->event === 'to_sign' ? 'high' : 'normal';
    }

    public function title(User $notifiable): string
    {
        return match ($this->event) {
            'submitted' => 'Rectificación por autorizar',
            'to_sign' => 'Rectificación por firmar',
            'signed' => 'Rectificación por validar',
            'applied' => 'Rectificación aplicada',
            'expired' => 'Rectificación vencida',
            'declined' => 'Rectificación no firmada',
            default => 'Rectificación rechazada',
        };
    }

    public function body(User $notifiable): string
    {
        $base = match ($this->event) {
            'submitted' => 'Hay una solicitud nueva de tu equipo.',
            'to_sign' => 'Revisa y firma la corrección en la app.',
            'signed' => 'El colaborador firmó; falta la validación de RH.',
            'applied' => 'El ajuste quedó aplicado a tu jornada.',
            'expired' => 'Venció el plazo de firma; el día queda como omisión.',
            'declined' => 'El colaborador no aceptó la corrección.',
            default => 'La solicitud fue rechazada.',
        };

        return $this->detail ? $base.' '.$this->detail : $base;
    }

    public function data(User $notifiable): array
    {
        return ['subject' => 'rectification', 'subject_id' => (string) $this->rectificationId, 'event' => $this->event, 'audience' => $this->audience];
    }

    protected function extraDatabase(): array
    {
        return ['subject' => 'rectification', 'subject_id' => $this->rectificationId];
    }
}
