<?php

namespace App\Notifications\Channels;

use App\Domain\Notifications\Jobs\SendPushToUser;
use App\Domain\Notifications\PushOutcome;
use App\Domain\Notifications\PushSender;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Notification;

/**
 * Canal push vía Firebase Cloud Messaging HTTP v1 (reemplaza al canal Expo).
 * Texto visible neutro; el detalle viaja sólo a la bandeja de la app.
 * Sin credenciales: no envía nada (la bandeja `database` sigue funcionando).
 */
final class FcmChannel
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(User $notifiable, Notification $notification): ?PushOutcome
    {
        if (! $notification instanceof AppNotification) {
            return null;
        }

        $message = $notification->toPush($notifiable);
        $outcome = $this->sender->toUser($notifiable, $notification->organizationId, $message);

        if ($outcome->status === PushOutcome::TRANSIENT) {
            SendPushToUser::dispatch($notifiable->id, $notification->organizationId, $message->toArray(), 2)
                ->delay(now()->addMinute());
        }

        return $outcome;
    }
}
