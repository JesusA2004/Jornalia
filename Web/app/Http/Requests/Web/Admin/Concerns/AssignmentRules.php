<?php

namespace App\Http\Requests\Web\Admin\Concerns;

/**
 * Reglas de los campos de asignación (sucursal, puesto, horario…).
 */
trait AssignmentRules
{
    use OrganizationRules;

    /**
     * @return array<string, mixed>
     */
    protected function assignmentRules(string $prefix = '', bool $branchRequired = false): array
    {
        return [
            $prefix.'work_center_id' => ['nullable', 'integer', $this->existsInOrg('work_centers')],
            $prefix.'branch_id' => [$branchRequired ? 'required' : 'nullable', 'integer', $this->existsInOrg('branches')],
            $prefix.'department_id' => ['nullable', 'integer', $this->existsInOrg('departments')],
            $prefix.'position_id' => ['nullable', 'integer', $this->existsInOrg('positions')],
            $prefix.'supervisor_employee_id' => ['nullable', 'integer', $this->existsInOrg('employees')],
            $prefix.'schedule_id' => ['nullable', 'integer', $this->existsInOrg('schedules')],
            $prefix.'attendance_policy_id' => ['nullable', 'integer', $this->existsInOrg('attendance_policies')],
            $prefix.'auto_clock_out_at' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function assignmentAttributes(string $prefix = ''): array
    {
        return [
            $prefix.'work_center_id' => 'centro de trabajo',
            $prefix.'branch_id' => 'sucursal',
            $prefix.'department_id' => 'departamento',
            $prefix.'position_id' => 'puesto',
            $prefix.'supervisor_employee_id' => 'supervisor',
            $prefix.'schedule_id' => 'horario',
            $prefix.'attendance_policy_id' => 'política de asistencia',
            $prefix.'auto_clock_out_at' => 'salida automática personal',
        ];
    }
}
