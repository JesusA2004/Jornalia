<?php

namespace App\Http\Resources\V1;

use App\Models\VacationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `VacationRequest`. Requiere `reviewedBy` precargado.
 *
 * @mixin VacationRequest
 */
final class VacationRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VacationRequest $v */
        $v = $this->resource;

        return [
            'id' => $v->id,
            'starts_on' => ApiFormat::date($v->starts_on),
            'ends_on' => ApiFormat::date($v->ends_on),
            'days' => (int) $v->days,
            'status' => $v->status->value,
            'comment' => $v->comment,
            'reviewer' => $v->reviewedBy?->name,
            'review_comment' => $v->review_comment,
            'created_at' => ApiFormat::dateTime($v->created_at),
        ];
    }
}
