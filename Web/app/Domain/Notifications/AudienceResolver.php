<?php

namespace App\Domain\Notifications;

use App\Domain\Security\AccessScope;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Destinatarios de un comunicado: personal ACTIVO de la organización activa,
 * dentro del alcance (`AccessScope`) de quien lo envía, filtrado por sucursal,
 * departamento, puesto (asignación vigente) o selección explícita.
 */
final class AudienceResolver
{
    private const COLUMNS = ['branches' => 'branch_id', 'departments' => 'department_id', 'positions' => 'position_id'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return Builder<Employee>
     */
    public function query(User $sender, string $type, array $ids): Builder
    {
        $query = $this->scope->employees($sender)->where('employees.status', 'active');
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return match ($type) {
            'all' => $query,
            'employees' => $query->whereIn('employees.id', $ids ?: [0]),
            'branches', 'departments', 'positions' => $query->whereIn('employees.id', function (QueryBuilder $sub) use ($type, $ids) {
                $today = now()->toDateString();
                $sub->select('em.employee_id')
                    ->from('employment_assignments as ea')
                    ->join('employments as em', 'em.id', '=', 'ea.employment_id')
                    ->where('ea.organization_id', $this->current->require()->id)
                    ->whereDate('ea.effective_from', '<=', $today)
                    ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
                    ->whereIn('ea.'.self::COLUMNS[$type], $ids ?: [0]);
            }),
            default => throw new InvalidArgumentException("Audiencia no válida: {$type}"),
        };
    }

    /**
     * @param  list<int>  $ids
     */
    public function count(User $sender, string $type, array $ids): int
    {
        return $this->query($sender, $type, $ids)->count();
    }
}
