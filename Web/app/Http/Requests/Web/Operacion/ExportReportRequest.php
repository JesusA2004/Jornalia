<?php

namespace App\Http\Requests\Web\Operacion;

use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportParameters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'position_id' => ['nullable', 'integer', 'min:1'],
            'region' => ['nullable', 'string', 'max:80'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'],
            'period_unit' => ['nullable', Rule::in(ReportParameters::PERIOD_UNITS)],
            'observations' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Reportes individuales exigen colaborador.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $definition = ReportCatalog::definition((string) $this->input('report'));
                if ($definition?->requiresEmployee && ! $this->filled('employee_id')) {
                    $validator->errors()->add('employee_id', 'Selecciona un colaborador para este reporte.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['report' => 'reporte', 'format' => 'formato', 'from' => 'fecha inicial', 'to' => 'fecha final', 'observations' => 'observaciones', 'employee_id' => 'colaborador'];
    }
}
