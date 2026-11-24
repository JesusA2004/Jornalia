<?php

namespace App\Notifications;

use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\NotificationCategory;
use App\Models\User;

/**
 * Comunicado enviado desde la web. Lo entrega DeliverAnnouncement (job
 * idempotente por destinatario), no la cola de notificaciones.
 */
final class AnnouncementPublished extends AppNotification
{
    public function __construct(
        public readonly int $announcementId,
        public readonly string $announcementUuid,
        public readonly string $announcementTitle,
        public readonly string $announcementBody,
        public readonly string $announcementPriority,
        public readonly bool $requiresConfirmation = false,
    ) {}

    public static function for(Announcement $announcement): self
    {
        return (new self($announcement->id, $announcement->uuid, $announcement->title, $announcement->body, $announcement->priority, (bool) $announcement->requires_confirmation))
            ->forOrganization((int) $announcement->organization_id);
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Message;
    }

    public function kind(): string
    {
        return 'announcement';
    }

    public function priority(): string
    {
        return $this->announcementPriority === 'high' ? 'high' : 'normal';
    }

    public function title(User $notifiable): string
    {
        return $this->announcementTitle;
    }

    public function body(User $notifiable): string
    {
        return $this->announcementBody;
    }

    public function data(User $notifiable): array
    {
        // `requires_confirmation`: la app muestra «Confirmar lectura» (POST /announcements/{id}/read).
        return ['announcement_id' => $this->announcementUuid, 'requires_confirmation' => $this->requiresConfirmation ? '1' : '0'];
    }

    protected function extraDatabase(): array
    {
        return ['announcement_id' => $this->announcementId];
    }
}
