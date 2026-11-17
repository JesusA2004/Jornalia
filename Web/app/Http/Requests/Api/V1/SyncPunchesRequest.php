<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato `SyncRequest`. Cada elemento se valida por separado en el
 * controlador para que un elemento inválido no aborte el lote.
 */
final class SyncPunchesRequest extends FormRequest
{
    public const MAX_ITEMS = 100;

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
            'punches' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'punches.*' => ['array'],
        ];
    }
}
