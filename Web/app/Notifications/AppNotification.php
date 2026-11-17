<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\PushMessage;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base de los avisos de la app: bandeja interna (`database`) + push FCM.
 *
 * Forma guardada (contrato `AppNotification`): kind, category, priority,
 * title, body, data (Record<string,string>). El push lleva un texto NEUTRO y,
 * en `data`, las mismas claves más `notification_id` para abrir el detalle.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public ?int $organizationId = null;

    abstract public function category(): NotificationCategory;

    abstract public function kind(): string;

    abstract public function title(User $notifiable): string;

    abstract public function body(User $notifiable): string;

    /** normal | high */
    public function priority(): string
    {
        return 'normal';
    }

    /**
     * Identificadores para abrir el detalle en la app (sólo texto, sin datos sensibles).
     *
     * @return array<string, string>
     */
    public function data(User $notifiable): array
    {
        return [];
    }

    /**
     * Organización del aviso (filtra los dispositivos destino).
     */
    public function forOrganization(?int $organizationId): static
    {
        $this->organizationId = $organizationId;

        return $this;
    }

    /**
     * @return list<string|class-string>
     */
    public function via(User $notifiable): array
    {
        // Se evalúa al despachar (antes de encolar): fija la organización activa.
        $this->organizationId ??= app(CurrentOrganization::class)->id();

        return ['database', FcmChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $this->organizationId ??= app(CurrentOrganization::class)->id();

        return [
            'kind' => $this->kind(),
            'category' => $this->category()->value,
            'priority' => $this->priority(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'data' => $this->stringData($notifiable),
            'organization_id' => $this->organizationId,
        ] + $this->extraDatabase();
    }

    /**
     * Claves adicionales sólo para la web (p. ej. subject/subject_id).
     *
     * @return array<string, mixed>
     */
    protected function extraDatabase(): array
    {
        return [];
    }

    public function toPush(User $notifiable): PushMessage
    {
        $this->organizationId ??= app(CurrentOrganization::class)->id();

        return new PushMessage(
            title: PushMessage::TITLE,
            body: $this->category()->lockScreenBody(),
            data: [
                'notification_id' => (string) $this->id,
                'category' => $this->category()->value,
                'kind' => $this->kind(),
                'priority' => $this->priority(),
            ] + $this->stringData($notifiable),
            highPriority: $this->priority() === 'high' || $this->category() === NotificationCategory::Reminder,
        );
    }

    /**
     * @return array<string, string>
     */
    private function stringData(User $notifiable): array
    {
        return array_map(static fn ($v): string => (string) $v, $this->data($notifiable));
    }
}
