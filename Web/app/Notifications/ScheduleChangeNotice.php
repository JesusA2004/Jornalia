<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Models\ScheduleException;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Aviso al colaborador de un cambio de horario programado autorizado o anulado
 * (PDF RH pantalla 10: «notificación al colaborador en la app»).
 */
final class ScheduleChangeNotice extends AppNotification
{
    public function __construct(
        public readonly int $changeId,
        public readonly string $date,
        public readonly bool $isRestDay,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly bool $cancelled = false,
    ) {}

    public static function for(ScheduleException $change, bool $cancelled = false): self
    {
        return new self(
            $change->id,
            $change->date->toDateString(),
            (bool) $change->is_rest_day,
            $change->starts_at ? substr((string) $change->starts_at, 0, 5) : null,
            $change->ends_at ? substr((string) $change->ends_at, 0, 5) : null,
            $cancelled,
        );
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Attendance;
    }

    public function kind(): string
    {
        return $this->cancelled ? 'schedule.change_cancelled' : 'schedule.change_scheduled';
    }

    public function priority(): string
    {
        return 'high';
    }

    public function title(User $notifiable): string
    {
        return $this->cancelled ? 'Cambio de horario anulado' : 'Cambio de horario programado';
    }

    public function body(User $notifiable): string
    {
        $day = CarbonImmutable::parse($this->date)->format('d/m/Y');
        if ($this->cancelled) {
            return "El {$day} vuelves a tu horario asignado.";
        }

        return $this->isRestDay
            ? "El {$day} será día de descanso."
            : "El {$day} tu horario será de {$this->startsAt} a {$this->endsAt}.";
    }

    public function data(User $notifiable): array
    {
        return ['schedule_change_id' => (string) $this->changeId, 'date' => $this->date];
    }
}
