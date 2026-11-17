<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PunchType;
use App\Enums\RectificationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato `RectificationRequest` (multipart si incluye `evidence`).
 */
final class StoreRectificationRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'punch_uuid' => ['nullable', 'uuid'],
            'kind' => ['required', Rule::enum(RectificationKind::class)],
            'proposed_type' => ['nullable', Rule::enum(PunchType::class)],
            'proposed_time' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic,webp'],
        ];
    }
}
