<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato `SignaturePinRequest`: define o cambia el NIP de firma.
 */
final class SignaturePinRequest extends FormRequest
{
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
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'current_pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }
}
