<?php

namespace App\Http\Resources\V1;

use App\Models\PolicyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato `AcknowledgementDocument`.
 *
 * @mixin PolicyDocument
 */
final class AcknowledgementDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PolicyDocument $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'code' => $d->code,
            'title' => $d->title,
            'version' => $d->version,
            'body_markdown' => $d->body_markdown,
            'published_at' => ApiFormat::dateTime($d->published_at),
        ];
    }
}
