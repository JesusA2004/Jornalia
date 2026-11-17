<?php

namespace App\Domain\Notifications;

/**
 * Categoría de un aviso (contrato `AppNotification.category`).
 */
enum NotificationCategory: string
{
    case Message = 'message';
    case Reminder = 'reminder';
    case Request = 'request';
    case Attendance = 'attendance';
    case System = 'system';

    /**
     * Texto visible NEUTRO para la pantalla de bloqueo (sin datos sensibles).
     * El detalle sólo se muestra dentro de la app.
     */
    public function lockScreenBody(): string
    {
        return match ($this) {
            self::Message => 'Tienes un comunicado nuevo.',
            self::Reminder => 'Tienes un recordatorio de asistencia.',
            self::Request => 'Una de tus solicitudes cambió de estado.',
            self::Attendance => 'Hay una novedad en tu asistencia.',
            self::System => 'Tienes un aviso nuevo.',
        };
    }
}
