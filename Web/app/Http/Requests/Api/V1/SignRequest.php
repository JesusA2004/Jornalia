<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato `SignRequest`: firma con NIP o con biometría del teléfono (HMAC del dispositivo).
 */
final class SignRequest extends FormRequest
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
            'method' => ['required', Rule::in(['pin', 'biometric'])],
            'pin' => ['required_if:method,pin', 'nullable', 'string', 'regex:/^\d{4,6}$/'],
            'device_signature' => ['required_if:method,biometric', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
