<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementRecipient;
use App\Domain\Notifications\PushOutcome;
use App\Domain\Notifications\PushSender;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Entrega de un comunicado a UN destinatario. Idempotente:
 *  1) Bandeja: se crea una sola notificación (id fijado en el destinatario
 *     bajo bloqueo); repetir el job no la duplica.
 *  2) Push: sólo mientras `push_status = pending`; fallas transitorias se
 *     reintentan con espera; token muerto → token desactivado.
 */
final class DeliverAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $recipientId) {}

    public function handle(PushSender $push, CurrentOrganization $current): void
    {
        $recipient = AnnouncementRecipient::query()->withoutGlobalScope('organization')->find($this->recipientId);
        if ($recipient === null) {
            return;
        }
        $organization = Organization::query()->find($recipient->organization_id);
        if ($organization === null) {
            return;
        }

        $current->runAs($organization, fn () => $this->deliver($recipient, $push));
    }

    private function deliver(AnnouncementRecipient $recipient, PushSender $push): void
    {
        $announcement = Announcement::query()->find($recipient->announcement_id);
        if ($announcement === null || $announcement->isWithdrawn()) {
            return;
        }

        $user = $recipient->user_id ? User::query()->find($recipient->user_id) : null;
        if ($user === null) {
            if ($recipient->failed_at === null) {
                $recipient->forceFill(['failed_at' => now(), 'error' => 'sin_cuenta', 'push_status' => AnnouncementRecipient::PUSH_FAILED])->save();
            }

            return;
        }

        // 1) Bandeja interna (una sola vez).
        $notification = DB::transaction(function () use ($recipient, $announcement, $user) {
            /** @var AnnouncementRecipient $locked */
            $locked = AnnouncementRecipient::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            $notification = AnnouncementPublished::for($announcement);
            $notification->id = $locked->notification_id ?? (string) Str::uuid();

            if ($locked->delivered_at === null) {
                $exists = DB::table('notifications')->where('id', $notification->id)->exists();
                $locked->forceFill(['notification_id' => $notification->id])->save();
                if (! $exists) {
                    $user->notifyNow($notification, ['database']);
                }
                $locked->forceFill(['delivered_at' => now()])->save();
            }

            return [$locked->fresh(), $notification];
        });

        /** @var AnnouncementRecipient $fresh */
        [$fresh, $message] = $notification;
        if ($fresh->push_status !== AnnouncementRecipient::PUSH_PENDING) {
            return;
        }

        // 2) Push FCM.
        $outcome = $push->toUser($user, (int) $announcement->organization_id, $message->toPush($user));
        $fresh->forceFill(['attempts' => $fresh->attempts + 1]);

        match ($outcome->status) {
            PushOutcome::SENT => $fresh->forceFill(['push_status' => AnnouncementRecipient::PUSH_SENT, 'push_sent_at' => now(), 'error' => null]),
            PushOutcome::NO_DEVICE => $fresh->forceFill(['push_status' => AnnouncementRecipient::PUSH_NO_DEVICE, 'error' => $outcome->code]),
            PushOutcome::DISABLED => $fresh->forceFill(['push_status' => AnnouncementRecipient::PUSH_DISABLED]),
            PushOutcome::FAILED => $fresh->forceFill(['push_status' => AnnouncementRecipient::PUSH_FAILED, 'error' => $outcome->code]),
            default => $fresh->forceFill(['error' => $outcome->code]),
        };
        $fresh->save();

        if ($outcome->status === PushOutcome::TRANSIENT) {
            if ($this->attempts() >= $this->tries) {
                $fresh->forceFill(['push_status' => AnnouncementRecipient::PUSH_FAILED])->save();

                return;
            }
            if ($this->job !== null) {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }
            throw new RuntimeException('Push transitorio; se reintentará.');
        }
    }
}
