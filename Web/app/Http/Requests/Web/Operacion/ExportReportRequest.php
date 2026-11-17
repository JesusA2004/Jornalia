<?php

namespace App\Http\Requests\Web\Operacion;

use App\Domain\Reports\ReportCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportReportRequest extends FormRequest
{
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
            'report' => ['required', 'string', Rule::in(ReportCatalog::keys())],
            'format' => ['required', 'in:xlsx,pdf,csv'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['report' => 'reporte', 'format' => 'formato', 'from' => 'fecha inicial', 'to' => 'fecha final', 'observations' => 'observaciones'];
    }
}
