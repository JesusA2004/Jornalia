<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Contrato `IncidenceRequest` (multipart si incluye `evidence`).
 */
final class StoreIncidenceRequest extends FormRequest
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
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'incidence_type_id' => ['required', 'integer', Rule::exists('incidence_types', 'id')->where('organization_id', $organizationId)->where('active', true)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'folio' => ['nullable', 'string', 'max:40'],
            'proposed_time' => ['nullable', 'date_format:H:i'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic,heif,webp'],
        ];
    }
}
