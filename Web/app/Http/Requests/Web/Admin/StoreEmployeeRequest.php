<?php

namespace App\Http\Requests\Web\Admin;

use App\Enums\ContractType;
use App\Enums\WorkModality;
use App\Http\Requests\Web\Admin\Concerns\AssignmentRules;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    use AssignmentRules;

    public const CURP = '/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/';

    public const RFC = '/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/u';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'curp' => $this->filled('curp') ? strtoupper(trim((string) $this->input('curp'))) : null,
            'rfc' => $this->filled('rfc') ? strtoupper(trim((string) $this->input('rfc'))) : null,
            'email' => $this->filled('email') ? strtolower(trim((string) $this->input('email'))) : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sensitive = $this->user()?->can('employees.view_sensitive') ?? false;

        return [
            'employee_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueInOrg('employees', 'employee_number')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'second_last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]{7,30}$/'],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'curp' => [$sensitive ? 'nullable' : 'prohibited', 'string', 'regex:'.self::CURP],
            'rfc' => [$sensitive ? 'nullable' : 'prohibited', 'string', 'regex:'.self::RFC],
            'nss' => [$sensitive ? 'nullable' : 'prohibited', 'string', 'regex:/^\d{11}$/'],

            'starts_on' => ['required', 'date', 'after:1950-01-01'],
            'seniority_starts_on' => ['nullable', 'date', 'before_or_equal:starts_on'],
            'contract_type' => ['required', Rule::enum(ContractType::class)],
            'work_modality' => ['required', Rule::enum(WorkModality::class)],
            'legal_entity_id' => ['nullable', 'integer', $this->existsInOrg('legal_entities')],

            ...$this->assignmentRules(branchRequired: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_number' => 'número de empleado',
            'first_name' => 'nombre',
            'last_name' => 'apellido paterno',
            'second_last_name' => 'apellido materno',
            'email' => 'correo',
            'phone' => 'teléfono',
            'birth_date' => 'fecha de nacimiento',
            'starts_on' => 'fecha de ingreso',
            'seniority_starts_on' => 'inicio de antigüedad',
            'contract_type' => 'tipo de contrato',
            'work_modality' => 'modalidad',
            'legal_entity_id' => 'razón social',
            ...$this->assignmentAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'curp.regex' => 'La CURP no tiene un formato válido (18 caracteres).',
            'rfc.regex' => 'El RFC de persona física debe tener 13 caracteres.',
            'nss.regex' => 'El NSS debe tener 11 dígitos.',
            '*.prohibited' => 'No tienes permiso para capturar identificadores oficiales.',
            'employee_number.unique' => 'Ya existe un colaborador con ese número de empleado.',
        ];
    }
}
