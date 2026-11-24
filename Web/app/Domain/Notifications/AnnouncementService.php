<?php

namespace App\Domain\Notifications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Jobs\DeliverAnnouncement;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementRecipient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comunicados: alta (inmediata o programada), envío por cola idempotente por
 * destinatario, retiro lógico / reactivación y estadísticas de entrega.
 * Siempre dentro del contexto de la organización activa.
 */
final class AnnouncementService
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{title:string, body:string, priority:string, audience_type:string, audience_ids:list<int>, scheduled_at:?CarbonImmutable, requires_confirmation?:bool}  $data
     */
    public function create(User $sender, array $data): Announcement
    {
        $scheduled = $data['scheduled_at'] !== null && $data['scheduled_at']->isFuture();

        $announcement = Announcement::query()->create([
            'uuid' => (string) Str::uuid7(),
            'title' => $data['title'],
            'body' => $data['body'],
            'priority' => $data['priority'] === 'high' ? 'high' : 'normal',
            // Comunicado importante: el colaborador confirma la lectura en la app.
            'requires_confirmation' => (bool) ($data['requires_confirmation'] ?? false),
            'audience_type' => $data['audience_type'],
            'audience_ids' => $data['audience_type'] === 'all' ? null : $data['audience_ids'],
            'status' => $scheduled ? 'scheduled' : 'sending',
            'scheduled_at' => $scheduled ? $data['scheduled_at'] : null,
            'created_by' => $sender->id,
        ]);

        $this->audit->log('announcement.created', $announcement, 'Comunicado: '.$announcement->title, [
            'audience' => $announcement->audience_type,
            'audience_ids' => $announcement->audience_ids,
            'requires_confirmation' => $announcement->requires_confirmation,
            'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
        ]);

        if (! $scheduled) {
            $this->send($announcement, $sender);
        }

        return $announcement->refresh();
    }

    /**
     * Genera los destinatarios (idempotente) y encola su entrega.
     */
    public function send(Announcement $announcement, ?User $sender = null): void
    {
        if ($announcement->isWithdrawn()) {
            return;
        }
        $sender ??= User::query()->find($announcement->created_by);
        if ($sender === null) {
            $announcement->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

            return;
        }

        $now = now();
        $this->audience->query($sender, $announcement->audience_type, (array) ($announcement->audience_ids ?? []))
            ->select(['employees.id', 'employees.user_id'])
            ->chunkById(500, function ($employees) use ($announcement, $now) {
                $rows = [];
                foreach ($employees as $employee) {
                    $rows[] = [
                        'organization_id' => $announcement->organization_id,
                        'announcement_id' => $announcement->id,
                        'employee_id' => $employee->id,
                        'user_id' => $employee->user_id,
                        'push_status' => AnnouncementRecipient::PUSH_PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                AnnouncementRecipient::query()->insertOrIgnore($rows);
            }, 'employees.id', 'id');

        $count = AnnouncementRecipient::query()->where('announcement_id', $announcement->id)->count();
        $announcement->forceFill(['status' => 'sent', 'sent_at' => $now, 'recipients_count' => $count])->save();

        AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('delivered_at')->orWhere('push_status', AnnouncementRecipient::PUSH_PENDING))
            ->select('id')
            ->chunkById(500, function ($recipients) {
                foreach ($recipients as $recipient) {
                    DeliverAnnouncement::dispatch($recipient->id);
                }
            });
    }

    /**
     * Envía los comunicados programados cuya hora ya llegó. Devuelve cuántos.
     */
    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;
        $due = Announcement::query()->where('status', 'scheduled')->whereNull('withdrawn_at')
            ->where('scheduled_at', '<=', $now)->pluck('id')->map(fn ($id) => (int) $id);

        foreach ($due as $id) {
            // Reclamo atómico: sólo un proceso pasa de scheduled → sending.
            $claimed = Announcement::query()->whereKey($id)->where('status', 'scheduled')->update(['status' => 'sending', 'updated_at' => $now]);
            if ($claimed === 1) {
                $this->send(Announcement::query()->findOrFail($id));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Retiro lógico: deja de mostrarse en la app (nunca se borra).
     */
    public function withdraw(Announcement $announcement, User $by): void
    {
        if ($announcement->isWithdrawn()) {
            return;
        }
        $now = now();
        $announcement->forceFill(['withdrawn_at' => $now, 'withdrawn_by' => $by->id])->save();
        $this->notificationRows($announcement)->update(['withdrawn_at' => $now]);
        $this->audit->log('announcement.withdrawn', $announcement, 'Comunicado retirado: '.$announcement->title);
    }

    public function reactivate(Announcement $announcement, User $by): void
    {
        if (! $announcement->isWithdrawn()) {
            return;
        }
        $announcement->forceFill(['withdrawn_at' => null, 'withdrawn_by' => null])->save();
        $this->notificationRows($announcement)->update(['withdrawn_at' => null]);
        $this->audit->log('announcement.reactivated', $announcement, 'Comunicado reactivado: '.$announcement->title, ['by' => $by->id]);
    }

    /**
     * Confirmación explícita de lectura de un comunicado importante (idempotente).
     * También marca como leído el aviso de la bandeja.
     */
    public function confirm(Announcement $announcement, User $user): AnnouncementRecipient
    {
        /** @var AnnouncementRecipient $recipient */
        $recipient = AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($recipient->confirmed_at === null) {
            $now = now();
            $recipient->forceFill(['confirmed_at' => $now, 'read_at' => $recipient->read_at ?? $now])->save();
            if ($recipient->notification_id !== null) {
                DB::table('notifications')->where('id', $recipient->notification_id)->whereNull('read_at')->update(['read_at' => $now]);
            }
            $this->audit->log('announcement.confirmed', $announcement, 'Lectura confirmada: '.$announcement->title, [
                'employee_id' => $recipient->employee_id,
            ]);
        }

        return $recipient;
    }

    /**
     * Copia la lectura de la bandeja (notifications.read_at) a los destinatarios.
     */
    public function syncReadState(Announcement $announcement): void
    {
        DB::table('announcement_recipients as r')
            ->join('notifications as n', 'n.id', '=', 'r.notification_id')
            ->where('r.announcement_id', $announcement->id)
            ->whereNull('r.read_at')
            ->whereNotNull('n.read_at')
            ->update(['r.read_at' => DB::raw('n.read_at')]);
    }

    /**
     * @param  list<int>  $announcementIds
     * @return array<int, array{total:int, delivered:int, read:int, confirmed:int, push_sent:int, failed:int}>
     */
    public function stats(array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = AnnouncementRecipient::query()
            ->whereIn('announcement_id', $announcementIds)
            ->groupBy('announcement_id')
            ->selectRaw('announcement_id, count(*) as total')
            ->selectRaw('sum(delivered_at is not null) as delivered')
            ->selectRaw('sum(read_at is not null) as read_count')
            ->selectRaw('sum(confirmed_at is not null) as confirmed_count')
            ->selectRaw("sum(push_status = 'sent') as push_sent")
            ->selectRaw("sum(failed_at is not null or push_status = 'failed') as failed")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->getAttribute('announcement_id')] = [
                'total' => (int) $r->getAttribute('total'),
                'delivered' => (int) $r->getAttribute('delivered'),
                'read' => (int) $r->getAttribute('read_count'),
                'confirmed' => (int) $r->getAttribute('confirmed_count'),
                'push_sent' => (int) $r->getAttribute('push_sent'),
                'failed' => (int) $r->getAttribute('failed'),
            ];
        }

        return $out;
    }

    private function notificationRows(Announcement $announcement): Builder
    {
        return DB::table('notifications')->whereIn('id', AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->whereNotNull('notification_id')
            ->select('notification_id'));
    }
}
