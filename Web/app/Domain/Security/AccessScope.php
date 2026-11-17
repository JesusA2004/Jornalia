<?php

namespace App\Domain\Security;

use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Alcance efectivo de datos de un usuario dentro de la organización activa.
 *
 *  - Superadministrador o pertenencia con `access_all_branches`: todo el personal.
 *  - Sucursales asignadas (`branch_user`): personal con asignación vigente ahí.
 *  - Supervisor: sus reportes directos vigentes.
 *  - Siempre: su propio expediente.
 *
 * Se aplica en backend a TODA consulta de colaboradores, marcajes, solicitudes
 * y reportes. Los filtros del frontend son sólo de conveniencia.
 */
final class AccessScope
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function hasFullAccess(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        $organizationId = $this->current->id();

        return $organizationId !== null && $user->organizations()
            ->where('organizations.id', $organizationId)
            ->wherePivot('status', 'active')
            ->wherePivot('access_all_branches', true)
            ->exists();
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function applyToEmployees(Builder $query, User $user, string $column = 'employees.id'): Builder
    {
        if ($this->hasFullAccess($user)) {
            return $query;
        }

        $today = now()->toDateString();
        $branchIds = $user->branches()->pluck('branches.id')->all();
        $ownEmployeeId = Employee::query()->where('user_id', $user->id)->value('id');

        return $query->where(function (Builder $q) use ($column, $today, $branchIds, $ownEmployeeId) {
            $q->whereIn($column, function (QueryBuilder $sub) use ($today, $branchIds, $ownEmployeeId) {
                $sub->select('em.employee_id')
                    ->from('employment_assignments as ea')
                    ->join('employments as em', 'em.id', '=', 'ea.employment_id')
                    ->whereDate('ea.effective_from', '<=', $today)
                    ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
                    ->where(function ($w) use ($branchIds, $ownEmployeeId) {
                        $w->whereIn('ea.branch_id', $branchIds ?: [0]);
                        if ($ownEmployeeId !== null) {
                            $w->orWhere('ea.supervisor_employee_id', $ownEmployeeId);
                        }
                    });
            });
            if ($ownEmployeeId !== null) {
                $q->orWhere($column, $ownEmployeeId);
            }
        });
    }

    /**
     * @return Builder<Employee>
     */
    public function employees(User $user): Builder
    {
        return $this->applyToEmployees(Employee::query(), $user);
    }

    public function canAccessEmployee(User $user, Employee|int $employee): bool
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $this->employees($user)->whereKey($id)->exists();
    }

    /**
     * Restringe una consulta de cualquier tabla con `employee_id`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeByEmployee(Builder $query, User $user): Builder
    {
        if ($this->hasFullAccess($user)) {
            return $query;
        }
        $ids = $this->employees($user)->select('employees.id');

        return $query->whereIn($query->getModel()->qualifyColumn('employee_id'), $ids);
    }
}
