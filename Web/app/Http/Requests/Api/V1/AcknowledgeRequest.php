<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato `AcknowledgeRequest`.
 */
final class AcknowledgeRequest extends FormRequest
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
            'accepted' => ['required', 'accepted'],
            'device_uuid' => ['required', 'uuid'],
        ];
    }
}
