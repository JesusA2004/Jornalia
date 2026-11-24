<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Security\AccessScope;
use App\Domain\Workforce\BulkScheduleAssigner;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asignación individual y masiva de horarios con vigencia y vista de conflictos.
 */
class ScheduleAssignmentController extends AdminController
{
    use OrganizationRules;

    public function __construct(
        private readonly BulkScheduleAssigner $assigner,
        private readonly AccessScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('schedules.assign'), 403);
        $user = $this->actor($request);

        $data = $request->validate($this->targetRules(required: false));
        $preview = null;
        if (! empty($data['schedule_id']) && ! empty($data['effective_from']) && (! empty($data['branch_ids']) || ! empty($data['department_ids']) || ! empty($data['employee_ids']))) {
            $schedule = Schedule::query()->findOrFail((int) $data['schedule_id']);
            $preview = $this->assigner->preview($user, $schedule, $data['effective_from'], $this->target($data));
        }

        return Inertia::render('horarios/Assignment', [
            'filters' => [
                'schedule_id' => isset($data['schedule_id']) ? (string) $data['schedule_id'] : null,
                'effective_from' => $data['effective_from'] ?? null,
                'mode' => $data['mode'] ?? 'branch',
                'branch_ids' => array_map('strval', $data['branch_ids'] ?? []),
                'department_ids' => array_map('strval', $data['department_ids'] ?? []),
                'employee_ids' => array_map('strval', $data['employee_ids'] ?? []),
            ],
            'preview' => $preview,
            'options' => [
                'schedules' => $this->options(Schedule::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
                'branches' => $this->options(Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
                'departments' => $this->options(Department::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
                'employees' => $this->scope->employees($user)->where('status', 'active')->orderBy('last_name')->limit(2000)
                    ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])
                    ->map(fn (Employee $e) => ['value' => (string) $e->id, 'label' => $e->fullName(), 'description' => $e->employee_number])->values(),
            ],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('schedules.assign'), 403);
        $data = $request->validate($this->targetRules(required: true) + ['reason' => ['nullable', 'string', 'max:255']]);
        $schedule = Schedule::query()->findOrFail((int) $data['schedule_id']);
        $this->authorize('assign', $schedule);

        $result = $this->assigner->apply($this->actor($request), $schedule, $data['effective_from'], $this->target($data), $data['reason'] ?? null);

        $this->toast($result['applied'] > 0
            ? "Horario asignado a {$result['applied']} colaboradores".($result['skipped'] > 0 ? "; {$result['skipped']} omitidos por conflicto o sin cambio." : '.')
            : 'No se asignó a nadie: todos los colaboradores tienen conflictos o ya tienen ese horario.', $result['applied'] > 0 ? 'success' : 'warning');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function targetRules(bool $required): array
    {
        $req = $required ? 'required' : 'nullable';

        return [
            'schedule_id' => [$req, 'integer', $this->existsInOrg('schedules')],
            'effective_from' => [$req, 'date', 'after_or_equal:today'],
            'mode' => ['nullable', 'in:branch,department,selection'],
            'branch_ids' => ['nullable', 'array', 'max:200'],
            'branch_ids.*' => ['integer', $this->existsInOrg('branches')],
            'department_ids' => ['nullable', 'array', 'max:200'],
            'department_ids.*' => ['integer', $this->existsInOrg('departments')],
            'employee_ids' => ['nullable', 'array', 'max:1000'],
            'employee_ids.*' => ['integer', $this->existsInOrg('employees')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{branch_ids?:list<int>, department_ids?:list<int>, employee_ids?:list<int>}
     */
    private function target(array $data): array
    {
        $mode = $data['mode'] ?? 'branch';

        return match ($mode) {
            'department' => ['department_ids' => self::ids($data['department_ids'] ?? [])],
            'selection' => ['employee_ids' => self::ids($data['employee_ids'] ?? [])],
            default => ['branch_ids' => self::ids($data['branch_ids'] ?? [])],
        };
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        return array_values(array_map('intval', (array) $values));
    }
}
