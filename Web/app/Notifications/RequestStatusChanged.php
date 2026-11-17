<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Resolución de una solicitud (rectificación, incidencia, vacaciones, horas extra).
 */
final class RequestStatusChanged extends AppNotification
{
    private const SUBJECTS = [
        'rectification' => 'rectificación',
        'incidence' => 'incidencia',
        'vacation' => 'vacaciones',
        'overtime' => 'horas extraordinarias',
    ];

    public function __construct(
        public readonly string $subject,
        public readonly int $subjectId,
        public readonly ApprovalStatus $status,
        public readonly ?string $comment,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Request;
    }

    public function kind(): string
    {
        return "request.{$this->status->value}";
    }

    /**
     * @return list<string|class-string>
     */
    public function via(User $notifiable): array
    {
        $channels = parent::via($notifiable);
        if (config('mail.default') !== 'log' && config('mail.default') !== 'array' && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function title(User $notifiable): string
    {
        $what = self::SUBJECTS[$this->subject] ?? 'solicitud';

        return match ($this->status) {
            ApprovalStatus::Approved => "Solicitud de {$what} aprobada",
            ApprovalStatus::Rejected => "Solicitud de {$what} rechazada",
            default => "Solicitud de {$what} actualizada",
        };
    }

    public function body(User $notifiable): string
    {
        return $this->comment ? 'Comentario: '.$this->comment : 'Consulta el detalle en la app.';
    }

    public function data(User $notifiable): array
    {
        return ['subject' => $this->subject, 'subject_id' => (string) $this->subjectId, 'status' => $this->status->value];
    }

    protected function extraDatabase(): array
    {
        return ['subject' => $this->subject, 'subject_id' => $this->subjectId];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting('Hola')
            ->line($this->title($notifiable).'.')
            ->line('Ingresa a la plataforma para consultar el detalle.');
    }
}
