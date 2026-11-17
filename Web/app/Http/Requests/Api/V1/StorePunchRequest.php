<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Controllers\Api\V1\Support\PunchPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Contrato `PunchRequest`.
 */
final class StorePunchRequest extends FormRequest
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
        return PunchPayload::rules();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $offline = filter_var($this->input('captured_offline', false), FILTER_VALIDATE_BOOLEAN);
                if (! $offline && blank($this->input('nonce'))) {
                    $validator->errors()->add('nonce', 'El desafío (nonce) es obligatorio para marcajes en línea.');
                }
            },
        ];
    }
}
