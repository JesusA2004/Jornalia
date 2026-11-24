<?php

namespace App\Http\Requests\Web\Operacion;

use App\Domain\Reports\ReportParameters;
use Illuminate\Validation\Rule;

/**
 * Filtros de la vista previa de un reporte.
 */
class ReportPreviewRequest extends ListFiltersRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [
            'position_id' => ['nullable', 'integer', 'min:1'],
            'region' => ['nullable', 'string', 'max:80'],
            'period_unit' => ['nullable', Rule::in(ReportParameters::PERIOD_UNITS)],
        ];
    }
}
