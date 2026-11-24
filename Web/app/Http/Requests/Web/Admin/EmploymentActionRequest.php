<?php

namespace App\Http\Requests\Web\Admin;

use App\Domain\Workforce\WorkforceService;
use App\Enums\ContractType;
use App\Enums\WorkModality;
use App\Http\Requests\Web\Admin\Concerns\AssignmentRules;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Baja, reingreso y movimientos de personal (según la ruta).
 */
class EmploymentActionRequest extends FormRequest
{
    use AssignmentRules;

    public function authorize(): bool
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');
        $ability = match ($this->action()) {
            'terminate' => 'terminate',
            'rehire' => 'rehire',
            default => 'move',
        };

        return $this->user()?->can($ability, $employee) ?? false;
    }

    public function action(): string
    {
        return match (true) {
            $this->routeIs('colaboradores.terminate') => 'terminate',
            $this->routeIs('colaboradores.rehire') => 'rehire',
            default => 'move',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->action()) {
            'terminate' => [
                'ends_on' => ['required', 'date'],
                'reason' => ['required', 'string', 'min:3', 'max:255'],
            ],
            'rehire' => [
                'starts_on' => ['required', 'date'],
                'seniority_starts_on' => ['nullable', 'date', 'before_or_equal:starts_on'],
                'contract_type' => ['required', Rule::enum(ContractType::class)],
                'work_modality' => ['required', Rule::enum(WorkModality::class)],
                'legal_entity_id' => ['nullable', 'integer', $this->existsInOrg('legal_entities')],
                ...$this->assignmentRules(branchRequired: true),
            ],
            default => [
                'effective_from' => ['required', 'date', 'after_or_equal:today'],
                'reason' => ['required', 'string', 'min:3', 'max:255'],
                'fields' => ['required', 'array', 'min:1'],
                'fields.*' => ['string', Rule::in(WorkforceService::ASSIGNMENT_FIELDS)],
                ...$this->assignmentRules(),
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ends_on' => 'fecha de baja',
            'reason' => 'motivo',
            'starts_on' => 'fecha de reingreso',
            'seniority_starts_on' => 'inicio de antigüedad',
            'effective_from' => 'fecha de vigencia',
            'fields' => 'datos a modificar',
            ...$this->assignmentAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.after_or_equal' => 'Los movimientos no pueden aplicarse retroactivamente: elige hoy o una fecha posterior.',
            'fields.required' => 'Selecciona al menos un dato a modificar.',
        ];
    }

    /**
     * Cambios del movimiento: sólo los campos seleccionados.
     *
     * @return array<string, int|null>
     */
    public function changes(): array
    {
        $changes = [];
        foreach ((array) $this->validated('fields', []) as $field) {
            $value = $this->validated($field);
            $changes[$field] = $value === null || $value === '' ? null : (int) $value;
        }

        return $changes;
    }
}
