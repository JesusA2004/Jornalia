<?php

namespace App\Http\Requests\Web\Operacion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros comunes de listados de Operación (paginación de servidor).
 * La autorización por permiso se aplica en la ruta (`can:`); el alcance de
 * datos lo impone AccessScope en el controlador.
 */
class ListFiltersRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', 'string', 'max:30'],
            ...$this->extraRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'fecha inicial',
            'to' => 'fecha final',
            'branch_id' => 'sucursal',
            'department_id' => 'departamento',
            'employee_id' => 'colaborador',
            'search' => 'búsqueda',
        ];
    }

    /**
     * Filtros validados y no vacíos.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter($this->validated(), fn ($v) => $v !== null && $v !== '');
    }

    public function perPage(int $default = 15): int
    {
        return (int) ($this->validated('per_page') ?? $default);
    }

    /**
     * Orden permitido (lista blanca de columnas; nunca la columna cruda del cliente).
     *
     * @param  array<string, string>  $allowed  clave pública => columna
     * @return array{0:string, 1:'asc'|'desc'}
     */
    public function sorting(array $allowed, string $defaultKey, string $defaultDirection = 'desc'): array
    {
        $key = (string) ($this->validated('sort') ?? $defaultKey);
        $column = $allowed[$key] ?? $allowed[$defaultKey];
        $direction = (string) ($this->validated('direction') ?? $defaultDirection);

        return [$column, $direction === 'asc' ? 'asc' : 'desc'];
    }
}
