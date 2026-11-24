<?php

namespace App\Http\Requests\Web\Operacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreIncidenceRequest extends FormRequest
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
            'incidence_type_id' => ['required', 'integer', 'min:1'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'folio' => ['nullable', 'string', 'max:40'],
            'proposed_time' => ['nullable', 'date_format:H:i'],
            'evidence' => ['nullable', 'array', 'max:5'],
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
            'incidence_type_id' => 'tipo de incidencia',
            'starts_on' => 'fecha inicial',
            'ends_on' => 'fecha final',
            'minutes' => 'minutos',
            'reason' => 'motivo',
            'evidence' => 'evidencia',
        ];
    }
}
