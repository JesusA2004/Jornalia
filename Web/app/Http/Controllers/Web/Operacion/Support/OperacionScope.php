<?php

namespace App\Http\Controllers\Web\Operacion\Support;

use App\Domain\Security\AccessScope;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Utilidades compartidas por los controladores de Operación: alcance de datos
 * (AccessScope), filtros de sucursal/departamento/colaborador/búsqueda,
 * catálogos para filtros y presentación de fechas en UTC.
 *
 * Toda consulta de personal pasa por AccessScope: los filtros del cliente sólo
 * reducen, nunca amplían, el alcance permitido.
 */
final class OperacionScope
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly CurrentOrganization $current,
    ) {}

    public function timezone(): string
    {
        return (string) ($this->current->get()?->timezone ?? config('app.timezone', 'UTC'));
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function hasFullAccess(User $user): bool
    {
        return $this->scope->hasFullAccess($user);
    }

    public function canAccessEmployee(User $user, Employee|int $employee): bool
    {
        return $this->scope->canAccessEmployee($user, $employee);
    }

    /**
     * Colaborador accesible o 404 (no revela la existencia de registros ajenos).
     */
    public function employeeOrFail(User $user, int $employeeId): Employee
    {
        $employee = $this->scope->employees($user)->whereKey($employeeId)->first();
        abort_if($employee === null, 404);

        return $employee;
    }

    /**
     * Aplica alcance + filtros a una consulta con columna `employee_id`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters  branch_id, department_id, employee_id, search
     * @return Builder<TModel>
     */
    public function apply(Builder $query, User $user, array $filters, string $column = 'employee_id', bool $useSummaryColumns = false): Builder
    {
        if ($query->getModel() instanceof Employee) {
            // Consulta de colaboradores: aplica el alcance directamente sobre employees.id.
            $this->scope->applyToEmployees($query, $user);
        } else {
            $this->scope->scopeByEmployee($query, $user);
        }
        $qualified = str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column);

        if (! empty($filters['employee_id'])) {
            $query->where($qualified, (int) $filters['employee_id']);
        }

        $branch = ! empty($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $department = ! empty($filters['department_id']) ? (int) $filters['department_id'] : null;

        if ($useSummaryColumns) {
            $query->when($branch, fn ($q) => $q->where($q->getModel()->qualifyColumn('branch_id'), $branch))
                ->when($department, fn ($q) => $q->where($q->getModel()->qualifyColumn('department_id'), $department));
        } elseif ($branch !== null || $department !== null) {
            $query->whereIn($qualified, $this->assignmentSubquery($branch, $department));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereIn($qualified, function (QueryBuilder $sub) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $sub->select('id')->from('employees')
                    ->where('organization_id', $this->current->id())
                    ->where(fn ($w) => $w->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('second_last_name', 'like', $like)
                        ->orWhere('employee_number', 'like', $like)
                        ->orWhereRaw("CONCAT_WS(' ', first_name, last_name, second_last_name) like ?", [$like]));
            });
        }

        return $query;
    }

    /**
     * Subconsulta de colaboradores con asignación vigente hoy en sucursal/departamento.
     */
    public function assignmentSubquery(?int $branchId, ?int $departmentId): \Closure
    {
        $today = $this->today()->toDateString();

        return function (QueryBuilder $sub) use ($branchId, $departmentId, $today) {
            $sub->select('em.employee_id')
                ->from('employment_assignments as ea')
                ->join('employments as em', 'em.id', '=', 'ea.employment_id')
                ->where('ea.organization_id', $this->current->id())
                ->whereDate('ea.effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
                ->when($branchId, fn ($w) => $w->where('ea.branch_id', $branchId))
                ->when($departmentId, fn ($w) => $w->where('ea.department_id', $departmentId));
        };
    }

    /**
     * @return Builder<Employee>
     */
    public function employees(User $user): Builder
    {
        return $this->scope->employees($user);
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function branchOptions(User $user): array
    {
        $query = Branch::query()->orderBy('name');
        if (! $this->hasFullAccess($user)) {
            $ids = $user->branches()->pluck('branches.id')->all();
            $own = $this->ownBranchId($user);
            $query->whereIn('id', array_filter([...$ids, $own]) ?: [0]);
        }

        return $query->get(['id', 'name', 'code'])
            ->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => (string) $b->name, 'description' => (string) $b->code])
            ->values()->all();
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function departmentOptions(): array
    {
        return Department::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Department $d) => ['value' => (string) $d->id, 'label' => (string) $d->name])
            ->values()->all();
    }

    /**
     * Colaboradores accesibles (para filtros y formularios). Limitado para no
     * saturar la página; el filtro de búsqueda de texto cubre el resto.
     *
     * @return list<array{value:string,label:string,description:string,keywords:string}>
     */
    public function employeeOptions(User $user, int $limit = 400): array
    {
        return $this->scope->employees($user)
            ->where('status', 'active')
            ->orderBy('first_name')->orderBy('last_name')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])
            ->map(fn (Employee $e) => [
                'value' => (string) $e->id,
                'label' => $e->fullName(),
                'description' => (string) $e->employee_number,
                'keywords' => $e->fullName().' '.$e->employee_number,
            ])->values()->all();
    }

    private function ownBranchId(User $user): ?int
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();

        return $employee?->assignmentOn($this->today())?->branch_id;
    }

    /**
     * Datos mínimos de colaboradores (nombre, número, sucursal vigente) en lote.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, array{id:int,name:string,number:string,branch:?string,department:?string}>
     */
    public function employeeCards(iterable $ids): Collection
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $today = $this->today()->toDateString();

        $assignments = DB::table('employment_assignments as ea')
            ->join('employments as em', 'em.id', '=', 'ea.employment_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ea.branch_id')
            ->leftJoin('departments as d', 'd.id', '=', 'ea.department_id')
            ->leftJoin('positions as p', 'p.id', '=', 'ea.position_id')
            ->whereIn('em.employee_id', $ids)
            ->where('ea.organization_id', $this->current->id())
            ->whereDate('ea.effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
            ->orderBy('ea.effective_from')
            ->get(['em.employee_id', 'b.id as branch_id', 'b.name as branch', 'd.name as department', 'p.name as position'])
            ->keyBy('employee_id');

        return Employee::query()->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])
            ->mapWithKeys(fn (Employee $e) => [$e->id => [
                'id' => $e->id,
                'name' => $e->fullName(),
                'number' => (string) $e->employee_number,
                'branch_id' => $assignments[$e->id]->branch_id ?? null,
                'branch' => $assignments[$e->id]->branch ?? null,
                'department' => $assignments[$e->id]->department ?? null,
                'position' => $assignments[$e->id]->position ?? null,
            ]]);
    }

    public static function iso(?CarbonInterface $value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function date(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    /**
     * Periodo civil validado (por defecto: últimos 30 días hasta hoy).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(?string $from, ?string $to, int $defaultDays = 30, int $maxDays = 370): array
    {
        $today = $this->today();
        $end = $to ? CarbonImmutable::parse($to)->startOfDay() : $today;
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $end->subDays($defaultDays - 1);
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > $maxDays) {
            $start = $end->subDays($maxDays);
        }

        return [$start, $end];
    }
}
