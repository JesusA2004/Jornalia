<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato `LocationBatchRequest`. Sólo se valida el lote: cada punto lo
 * evalúa el dominio y se informa en `rejections` sin abortar el lote.
 */
final class LocationBatchRequest extends FormRequest
{
    public const MAX_POINTS = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'list', 'min:1', 'max:'.self::MAX_POINTS],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function points(): array
    {
        return array_values(array_map(fn ($p) => is_array($p) ? $p : [], (array) $this->input('points', [])));
    }
}
