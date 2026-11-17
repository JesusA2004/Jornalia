<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja de notificaciones del usuario autenticado (canal `database`).
 * Las notificaciones son personales: sólo se consultan las del propio usuario.
 */
final class NotificationController extends Controller
{
    /** Ruta web del detalle según el sujeto de la notificación. */
    private const SUBJECT_URLS = [
        'rectification' => '/rectificaciones/%d',
        'incidence' => '/incidencias/%d',
        'vacation' => '/vacaciones/%d',
        'vacation_request' => '/vacaciones/%d',
        'overtime' => '/horas-extra/%d',
        'overtime_request' => '/horas-extra/%d',
    ];

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filter = $request->string('filtro')->toString() === 'no-leidas' ? 'no-leidas' : 'todas';

        $query = $user->notifications()->latest();
        if ($filter === 'no-leidas') {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(20)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification) => $this->present($notification));

        return Inertia::render('notificaciones/Index', [
            'notifications' => $notifications,
            'filters' => ['filtro' => $filter],
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return redirect()->back();
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->unreadNotifications()->update(['read_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Todas las notificaciones quedaron como leídas.']);

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $subject = is_string($data['subject'] ?? null) ? $data['subject'] : null;
        $subjectId = is_numeric($data['subject_id'] ?? null) ? (int) $data['subject_id'] : null;
        $kind = is_string($data['kind'] ?? null) ? $data['kind'] : null;

        return [
            'id' => $notification->id,
            'title' => (string) ($data['title'] ?? 'Notificación'),
            'body' => isset($data['body']) ? (string) $data['body'] : null,
            'kind' => $kind,
            'tone' => match (true) {
                $kind !== null && str_ends_with($kind, 'approved') => 'success',
                $kind !== null && str_ends_with($kind, 'rejected') => 'danger',
                default => 'info',
            },
            'url' => $subject !== null && $subjectId !== null && isset(self::SUBJECT_URLS[$subject])
                ? sprintf(self::SUBJECT_URLS[$subject], $subjectId)
                : null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
        ];
    }
}
