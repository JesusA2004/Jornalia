<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PunchType;
use App\Enums\RectificationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Contrato `RectificationRequest` (multipart). La evidencia es OBLIGATORIA:
 * `evidence[]` (1 a 5 archivos; también se acepta `evidence` único por
 * compatibilidad con versiones anteriores de la app).
 */
final class StoreRectificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $single = $this->file('evidence');
        if ($single instanceof UploadedFile) {
            $this->files->set('evidence', [$single]);
            $this->convertedFiles = null; // Recalcula allFiles() con el arreglo.
        }
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
            'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic,heif,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidence.required' => 'Adjunta la evidencia (foto, documento o constancia).',
        ];
    }
}
