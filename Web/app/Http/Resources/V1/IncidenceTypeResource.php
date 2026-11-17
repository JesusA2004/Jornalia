<?php

namespace App\Http\Resources\V1;

use App\Models\IncidenceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `IncidenceType`.
 *
 * @mixin IncidenceType
 */
final class IncidenceTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IncidenceType $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'category' => $t->category,
            'requires_evidence' => (bool) $t->requires_evidence,
            'requires_approval' => (bool) $t->requires_approval,
            'employee_requestable' => (bool) $t->employee_requestable,
        ];
    }
}
