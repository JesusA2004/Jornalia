<?php

namespace App\Http\Resources\V1;

use App\Models\RectificationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `Rectification`. Requiere `reviewedBy` precargado.
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

        return [
            'id' => $r->id,
            'date' => ApiFormat::date($r->date),
            'punch_uuid' => $r->punch_uuid,
            'kind' => $r->kind->value,
            'proposed_type' => $r->proposed_type?->value,
            'proposed_time' => ApiFormat::dateTime($r->proposed_time),
            'reason' => $r->reason,
            'status' => $r->status->value,
            'reviewer' => $r->reviewedBy?->name,
            'review_comment' => $r->review_comment,
            'reviewed_at' => ApiFormat::dateTime($r->reviewed_at),
            'created_at' => ApiFormat::dateTime($r->created_at),
        ];
    }
}
