<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Salida automática registrada por el sistema (AutoClockOut).
 */
final class AutoClockOutRecorded extends AppNotification
{
    public function __construct(
        public readonly string $punchUuid,
        public readonly string $localTime,
        public readonly string $localDate,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Attendance;
    }

    public function kind(): string
    {
        return 'attendance.auto_clock_out';
    }

    public function title(User $notifiable): string
    {
        return 'Salida automática registrada';
    }

    public function body(User $notifiable): string
    {
        return "Tu jornada se cerró automáticamente a las {$this->localTime}.";
    }

    public function data(User $notifiable): array
    {
        return ['punch_uuid' => $this->punchUuid, 'date' => $this->localDate];
    }
}
