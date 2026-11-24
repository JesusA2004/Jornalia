<?php

namespace App\Domain\Reports\Builders;

use App\Domain\Reports\ReportParameters;
use App\Domain\Security\AccessScope;
use App\Models\Employee;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Colaboradores que entran en un reporte: dentro del alcance del usuario
 * (AccessScope + organización activa) y con relación laboral vigente en algún
 * día del periodo. Incluye bajas lógicas y personal ya separado, porque su
 * registro histórico debe poder reportarse.
 */
final class ScopedEmployees
{
    public const CHUNK = 50;

    public function __construct(private readonly AccessScope $scope) {}

    /**
     * @return Builder<Employee>
     */
    public function query(ReportParameters $p, User $user): Builder
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();

        $q = Employee::query()->withTrashed()->select('employees.*');
        $this->scope->applyToEmployees($q, $user);

        return $q
            ->when($p->employeeId, fn (Builder $w) => $w->whereKey($p->employeeId))
            ->whereExists(function (QueryBuilder $sub) use ($p, $from, $to) {
                $sub->selectRaw('1')->from('employments as em')
                    ->whereColumn('em.employee_id', 'employees.id')
                    ->where('em.starts_on', '<=', $to)
                    ->where(fn ($w) => $w->whereNull('em.ends_on')->orWhere('em.ends_on', '>=', $from));
                if ($p->branchId || $p->departmentId || $p->positionId || $p->region) {
                    $sub->whereExists(function (QueryBuilder $a) use ($p, $from, $to) {
                        $a->selectRaw('1')->from('employment_assignments as ea')
                            ->whereColumn('ea.employment_id', 'em.id')
                            ->where('ea.effective_from', '<=', $to)
                            ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhere('ea.effective_to', '>=', $from))
                            ->when($p->branchId, fn ($w) => $w->where('ea.branch_id', $p->branchId))
                            ->when($p->departmentId, fn ($w) => $w->where('ea.department_id', $p->departmentId))
                            ->when($p->positionId, fn ($w) => $w->where('ea.position_id', $p->positionId))
                            ->when($p->region, fn ($w) => $w->whereIn('ea.branch_id', fn ($b) => $b->select('id')->from('branches')->where('region', $p->region)));
                    });
                }
            })
            ->orderBy('employees.last_name')
            ->orderBy('employees.second_last_name')
            ->orderBy('employees.first_name')
            ->orderBy('employees.id');
    }

    public function count(ReportParameters $p, User $user): int
    {
        return $this->query($p, $user)->count();
    }

    /**
     * Bloques de colaboradores en orden alfabético, sin cargar todo en memoria.
     *
     * @return Generator<int, Collection<int, Employee>>
     */
    public function chunks(ReportParameters $p, User $user, int $size = self::CHUNK): Generator
    {
        $page = 1;
        do {
            $chunk = $this->query($p, $user)->forPage($page, $size)->get();
            if ($chunk->isNotEmpty()) {
                yield $chunk;
            }
            $page++;
        } while ($chunk->count() === $size);
    }
}
