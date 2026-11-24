<?php

namespace App\Http\Resources\V1;

use App\Models\Incidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `Incidence`. Requiere `type`, `reviewedBy` y `attachments_exists` precargados.
 *
 * @mixin Incidence
 */
final class IncidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Incidence $i */
        $i = $this->resource;

        return [
            'id' => $i->id,
            'type' => (new IncidenceTypeResource($i->type))->resolve($request),
            'starts_on' => ApiFormat::date($i->starts_on),
            'ends_on' => ApiFormat::date($i->ends_on),
            'reason' => $i->reason,
            'status' => $i->status->value,
            'has_evidence' => (bool) ($i->getAttribute('attachments_exists') ?? false),
            'folio' => $i->folio,
            'rectification_id' => $i->rectification_request_id,
            'cancellation_reason' => $i->cancellation_reason,
            'reviewer' => $i->reviewedBy?->name,
            'review_comment' => $i->review_comment,
            'created_at' => ApiFormat::dateTime($i->created_at),
        ];
    }

    /**
     * Contrato `IncidenceSummary`.
     *
     * @return array{id:int, type_code:string, type_name:string, status:string}
     */
    public static function summary(Incidence $i): array
    {
        return [
            'id' => $i->id,
            'type_code' => $i->type->code,
            'type_name' => $i->type->name,
            'status' => $i->status->value,
        ];
    }
}
