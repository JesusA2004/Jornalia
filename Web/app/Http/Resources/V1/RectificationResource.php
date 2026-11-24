<?php

namespace App\Http\Resources\V1;

use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\RectificationService;
use App\Enums\RectificationStage;
use App\Models\Attachment;
use App\Models\Punch;
use App\Models\RectificationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Contrato `Rectification`. Requiere `reviewedBy`, `attachments` y `signature` precargados.
 *
 * Mientras está «por_firmar» incluye el texto exacto a firmar y su SHA-256
 * (`signature_payload_sha256`) para la firma biométrica HMAC.
 *
 * @mixin RectificationRequest
 */
final class RectificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RectificationRequest $r */
        $r = $this->resource;
        $service = app(RectificationService::class);
        $punch = $r->punch_uuid ? Punch::query()->where('uuid', $r->punch_uuid)->first() : null;
        $toSign = $r->stage === RectificationStage::ToSign;

        return [
            'id' => $r->id,
            'date' => ApiFormat::date($r->date),
            'punch_uuid' => $r->punch_uuid,
            'kind' => $r->kind->value,
            'proposed_type' => $r->proposed_type?->value,
            'proposed_time' => ApiFormat::dateTime($r->proposed_time),
            'reason' => $r->reason,
            'status' => $r->status->value,
            'stage' => $r->stage->value,
            'stage_label' => $r->stage->label(),
            'original' => [
                'summary' => $service->originalSummary($r),
                'punch_uuid' => $r->punch_uuid,
                'type' => $punch?->type->value,
                'time' => $punch ? ApiFormat::dateTime(EffectiveTimeline::punchTime($punch)) : null,
            ],
            'proposal' => [
                'summary' => $service->proposalSummary($r),
                'type' => $r->proposed_type?->value,
                'time' => ApiFormat::dateTime($r->proposed_time),
            ],
            'evidences' => $r->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime' => $a->mime,
                'size' => (int) $a->size,
                'sha256' => $a->sha256,
                // URL firmada temporal (10 min): el navegador del teléfono no envía el token.
                'url' => URL::temporarySignedRoute('api.v1.files.evidence', now()->addMinutes(10), ['attachment' => $a->id]),
            ])->values()->all(),
            'is_late' => (bool) $r->is_late,
            'authorized_at' => ApiFormat::dateTime($r->authorized_at),
            'signature_due_at' => ApiFormat::dateTime($r->signature_due_at),
            'signature' => $r->signature ? [
                'signed_at' => ApiFormat::dateTime($r->signature->signed_at),
                'method' => $r->signature->method,
                'hash' => $r->signature->hash,
            ] : null,
            'signature_statement' => $toSign ? $service->statement($r) : null,
            'signature_payload_sha256' => $toSign ? $service->payloadSha256($r) : null,
            'can_sign' => $toSign && ($r->signature_due_at === null || $r->signature_due_at->isFuture()),
            'rejection_stage' => $r->rejection_stage,
            'rejection_reason' => $r->rejection_reason,
            'applied_at' => ApiFormat::dateTime($r->applied_at),
            'reviewer' => $r->reviewedBy?->name,
            'review_comment' => $r->review_comment,
            'reviewed_at' => ApiFormat::dateTime($r->reviewed_at),
            'created_at' => ApiFormat::dateTime($r->created_at),
        ];
    }
}
