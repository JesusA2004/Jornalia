<?php

namespace App\Http\Requests\Web\Operacion;

use App\Enums\PunchType;
use App\Enums\RectificationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreRectificationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $single = $this->file('evidence');
        if ($single instanceof UploadedFile) {
            $this->files->set('evidence', [$single]);
            $this->convertedFiles = null; // Recalcula allFiles() con el arreglo.
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::enum(RectificationKind::class)],
            'punch_uuid' => ['nullable', 'uuid'],
            'proposed_type' => ['nullable', Rule::enum(PunchType::class)],
            'proposed_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            // Evidencia OBLIGATORIA (foto, documento o constancia): 1 a 5 archivos.
            'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'colaborador',
            'date' => 'fecha',
            'kind' => 'tipo de rectificación',
            'punch_uuid' => 'marcaje',
            'proposed_type' => 'tipo de marcaje',
            'proposed_time' => 'hora propuesta',
            'reason' => 'motivo',
            'evidence' => 'evidencia',
            'evidence.*' => 'evidencia',
        ];
    }
}
