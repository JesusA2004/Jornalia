<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Recordatorios automáticos: entrada pendiente y salida pendiente.
 */
final class AttendanceReminder extends AppNotification
{
    public const LATE_CLOCK_IN = 'reminder.late_clock_in';

    public const CLOCK_OUT = 'reminder.clock_out';

    public function __construct(
        public readonly string $type,
        public readonly string $localDate,
        public readonly string $shiftTime,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Reminder;
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
        return $this->type === self::LATE_CLOCK_IN ? 'Tu hora de entrada ya pasó' : 'Recuerda marcar tu salida';
    }

    public function body(User $notifiable): string
    {
        return $this->type === self::LATE_CLOCK_IN
            ? "Tu turno inició a las {$this->shiftTime}. Registra tu entrada."
            : "Tu turno terminó a las {$this->shiftTime}. Registra tu salida.";
    }

    public function data(User $notifiable): array
    {
        return ['date' => $this->localDate, 'action' => $this->type === self::LATE_CLOCK_IN ? 'clock_in' : 'clock_out'];
    }
}
