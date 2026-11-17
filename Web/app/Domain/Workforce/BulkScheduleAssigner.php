<?php

namespace App\Domain\Workforce;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\AccessScope;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Asignación masiva de horario con vigencia (por sucursal, departamento o
 * selección). Calcula primero una vista previa con conflictos y sólo aplica
 * a los colaboradores sin conflicto.
 */
final class BulkScheduleAssigner
{
    public function __construct(
        private readonly WorkforceService $workforce,
        private readonly ScheduleLegalValidator $validator,
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{branch_ids?:list<int>, department_ids?:list<int>, employee_ids?:list<int>}  $target
     * @return array{schedule: array{id:int, name:string}, effective_from:string, legal_error:list<string>|null, total:int, ready:int, rows:list<array{employee_id:int, name:string, employee_number:string, current_schedule:?string, status:string, message:?string}>}
     */
    public function preview(User $user, Schedule $schedule, string $effectiveFrom, array $target): array
    {
        $date = CarbonImmutable::parse($effectiveFrom)->toDateString();
        $legalError = null;
        try {
            $this->validator->assertScheduleValidOn($schedule->loadMissing('segments'), $date);
        } catch (ValidationException $e) {
            $legalError = array_values($e->errors()['schedule_id'] ?? []);
        }

        $rows = [];
        foreach ($this->targets($user, $target)->with(['employments.assignments.schedule'])->orderBy('last_name')->orderBy('first_name')->limit(1000)->get() as $employee) {
            $rows[] = $this->evaluate($employee, $schedule, $date, $legalError !== null);
        }

        return [
            'schedule' => ['id' => $schedule->id, 'name' => $schedule->name],
            'effective_from' => $date,
            'legal_error' => $legalError,
            'total' => count($rows),
            'ready' => count(array_filter($rows, fn ($r) => $r['status'] === 'ready')),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{branch_ids?:list<int>, department_ids?:list<int>, employee_ids?:list<int>}  $target
     * @return array{applied:int, skipped:int}
     */
    public function apply(User $user, Schedule $schedule, string $effectiveFrom, array $target, ?string $reason): array
    {
        $preview = $this->preview($user, $schedule, $effectiveFrom, $target);
        if ($preview['legal_error'] !== null) {
            throw ValidationException::withMessages(['schedule_id' => $preview['legal_error']]);
        }

        $applied = 0;
        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== 'ready') {
                continue;
            }
            $employee = Employee::query()->findOrFail($row['employee_id']);
            $this->workforce->move($user, $employee, $preview['effective_from'], ['schedule_id' => $schedule->id], $reason ?? 'Asignación masiva de horario');
            $applied++;
        }

        $this->audit->log('schedule.bulk_assigned', $schedule, "Asignación masiva del horario «{$schedule->name}» a {$applied} colaboradores", [
            'effective_from' => $preview['effective_from'],
            'applied' => $applied,
            'skipped' => $preview['total'] - $applied,
            'target' => $target,
        ]);

        return ['applied' => $applied, 'skipped' => $preview['total'] - $applied];
    }

    /**
     * @param  array{branch_ids?:list<int>, department_ids?:list<int>, employee_ids?:list<int>}  $target
     * @return Builder<Employee>
     */
    private function targets(User $user, array $target): Builder
    {
        $branchIds = array_map('intval', $target['branch_ids'] ?? []);
        $departmentIds = array_map('intval', $target['department_ids'] ?? []);
        $employeeIds = array_map('intval', $target['employee_ids'] ?? []);
        $today = CarbonImmutable::today()->toDateString();

        $query = $this->scope->employees($user)->where('status', '!=', 'terminated');

        if ($branchIds === [] && $departmentIds === [] && $employeeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($branchIds, $departmentIds, $employeeIds, $today) {
            if ($employeeIds !== []) {
                $q->orWhereIn('employees.id', $employeeIds);
            }
            if ($branchIds !== [] || $departmentIds !== []) {
                $q->orWhereIn('employees.id', function ($sub) use ($branchIds, $departmentIds, $today) {
                    $sub->select('em.employee_id')->from('employment_assignments as ea')
                        ->join('employments as em', 'em.id', '=', 'ea.employment_id')
                        ->whereDate('ea.effective_from', '<=', $today)
                        ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
                        ->where(function ($w) use ($branchIds, $departmentIds) {
                            if ($branchIds !== []) {
                                $w->orWhereIn('ea.branch_id', $branchIds);
                            }
                            if ($departmentIds !== []) {
                                $w->orWhereIn('ea.department_id', $departmentIds);
                            }
                        });
                });
            }
        });
    }

    /**
     * @return array{employee_id:int, name:string, employee_number:string, current_schedule:?string, status:string, message:?string}
     */
    private function evaluate(Employee $employee, Schedule $schedule, string $date, bool $legalBlocked): array
    {
        $row = ['employee_id' => $employee->id, 'name' => $employee->fullName(), 'employee_number' => $employee->employee_number, 'current_schedule' => null, 'status' => 'ready', 'message' => null];

        $employment = $employee->employments
            ->filter(fn ($e) => $e->status !== 'terminated' && ($e->ends_on === null || $e->ends_on->gte(CarbonImmutable::parse($date))))
            ->sortByDesc('starts_on')->first();

        if ($employment === null) {
            return ['status' => 'conflict', 'message' => 'Sin relación laboral vigente a la fecha.'] + $row;
        }

        $current = $employment->assignments
            ->filter(fn ($a) => $a->effective_from->lte(CarbonImmutable::parse($date)) && ($a->effective_to === null || $a->effective_to->gte(CarbonImmutable::parse($date))))
            ->sortByDesc('effective_from')->first();
        $row['current_schedule'] = $current?->schedule?->name;

        $later = $employment->assignments->first(fn ($a) => $a->effective_from->gt(CarbonImmutable::parse($date)));
        if ($later !== null) {
            return ['status' => 'conflict', 'message' => 'Tiene un movimiento programado a partir del '.$later->effective_from->format('d/m/Y').'.'] + $row;
        }
        if ($employment->starts_on->gt(CarbonImmutable::parse($date))) {
            return ['status' => 'conflict', 'message' => 'Su relación laboral inicia después de la vigencia.'] + $row;
        }
        if ($current?->schedule_id === $schedule->id) {
            return ['status' => 'unchanged', 'message' => 'Ya tiene asignado este horario.'] + $row;
        }
        if ($legalBlocked) {
            return ['status' => 'conflict', 'message' => 'El horario rebasa la jornada legal vigente a la fecha.'] + $row;
        }

        return $row;
    }
}
