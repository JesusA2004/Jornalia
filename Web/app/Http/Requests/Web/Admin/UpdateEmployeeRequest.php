<?php

namespace App\Http\Requests\Web\Admin;

use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    use OrganizationRules;

    public function authorize(): bool
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return $this->user()?->can('update', $employee) ?? false;
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
        /** @var Employee $employee */
        $employee = $this->route('employee');
        $sensitive = $this->user()?->can('employees.view_sensitive') ?? false;

        return [
            'employee_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueInOrg('employees', 'employee_number', $employee->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'second_last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]{7,30}$/'],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'update_identifiers' => ['sometimes', 'boolean'],
            'curp' => [$sensitive ? 'nullable' : 'prohibited', 'string', 'regex:'.StoreEmployeeRequest::CURP],
            'rfc' => [$sensitive ? 'nullable' : 'prohibited', 'string', 'regex:'.StoreEmployeeRequest::RFC],
            'nss' => [$sensitive ? 'nullable' : 'prohibited', 'string', 'regex:/^\d{11}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreEmployeeRequest)->messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new StoreEmployeeRequest)->attributes();
    }
}
