<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\AnnouncementService;
use App\Domain\Notifications\Models\Announcement;
use App\Http\Resources\V1\ApiFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confirmación de lectura de comunicados importantes (PDF pantalla 12, punto 3).
 */
final class AnnouncementController extends ApiController
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    /**
     * POST /announcements/{id}/read → AnnouncementReadReceipt. `id` = uuid del
     * comunicado (`data.announcement_id` del aviso) o su id numérico. Idempotente.
     */
    public function read(Request $request, string $announcement): JsonResponse
    {
        $model = Announcement::query()
            ->where(fn ($q) => ctype_digit($announcement) ? $q->whereKey((int) $announcement) : $q->where('uuid', $announcement))
            ->whereNull('withdrawn_at')
            ->firstOrFail();

        $recipient = $this->announcements->confirm($model, $this->user($request));

        return $this->data([
            'announcement_id' => (string) $model->uuid,
            'confirmed_at' => ApiFormat::dateTime($recipient->confirmed_at),
            'read_at' => ApiFormat::dateTime($recipient->read_at),
        ]);
    }
}
