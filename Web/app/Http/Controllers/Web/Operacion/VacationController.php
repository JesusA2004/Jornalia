<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Vacations\VacationService;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\ApprovalPresenter;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\DecisionRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Http\Requests\Web\Operacion\StoreVacationRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vacaciones: solicitudes, saldos (VacationService, con fuente legal) y
 * calendario de ausencias con conflictos de cobertura por sucursal.
 */
final class VacationController extends Controller
{
    public function __construct(
        private readonly OperacionScope $scope,
        private readonly ApprovalWorkflow $workflow,
        private readonly VacationService $vacations,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $tab = in_array($filters['tab'] ?? null, ['solicitudes', 'saldos', 'calendario'], true) ? $filters['tab'] : 'solicitudes';

        $props = [
            'tab' => $tab,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'month' => $this->month($request->query('month'))->format('Y-m'),
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'statuses' => ApprovalStatus::options(),
            ],
            'counts' => $this->counts($user),
            'can' => ['create' => $user->can('vacations.create')],
            'own_employee_id' => Employee::query()->where('user_id', $user->id)->value('id'),
            'timezone' => $this->scope->timezone(),
        ];

        if ($tab === 'solicitudes') {
            $query = VacationRequest::query()->with(['requestedBy:id,name', 'reviewedBy:id,name'])
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
                ->when(isset($filters['from']), fn ($q) => $q->whereDate('ends_on', '>=', $filters['from']))
                ->when(isset($filters['to']), fn ($q) => $q->whereDate('starts_on', '<=', $filters['to']));
            $this->scope->apply($query, $user, $filters);
            [$column, $direction] = $request->sorting(['created_at' => 'created_at', 'starts_on' => 'starts_on'], 'created_at');

            $page = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderBy($column, $direction)
                ->paginate($request->perPage(15))->withQueryString();
            $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
            $page->through(fn (VacationRequest $v) => $this->row($v, $cards[$v->employee_id] ?? null, $user));
            $props['requests'] = $page;
        } elseif ($tab === 'saldos') {
            $query = $this->scope->employees($user)->where('employees.status', 'active');
            $this->scope->apply($query, $user, $filters, 'employees.id');
            $page = $query->orderBy('first_name')->orderBy('last_name')->paginate($request->perPage(15))->withQueryString();
            $cards = $this->scope->employeeCards($page->getCollection()->pluck('id'));
            $page->through(function (Employee $e) use ($cards) {
                try {
                    $balance = $this->vacations->balance($e);
                } catch (ValidationException) {
                    $balance = null;
                }

                return ['employee' => $cards[$e->id] ?? null, 'balance' => $balance];
            });
            $props['balances'] = $page;
        } else {
            $props['calendar'] = $this->calendar($user, $filters, $this->month($request->query('month')));
        }

        return Inertia::render('vacaciones/Index', $props);
    }

    public function show(ListFiltersRequest $request, int $vacation): Response
    {
        $user = $request->user();
        $v = $this->find($user, $vacation);
        $v->load(['requestedBy:id,name', 'reviewedBy:id,name', 'decisions.decidedBy:id,name']);

        try {
            $balance = $this->vacations->balance($v->employee);
        } catch (ValidationException) {
            $balance = null;
        }

        return Inertia::render('vacaciones/Show', [
            'request' => $this->row($v, $this->scope->employeeCards([$v->employee_id])[$v->employee_id] ?? null, $user) + [
                'comment' => $v->comment,
                'review_comment' => $v->review_comment,
            ],
            'balance' => $balance,
            'decisions' => ApprovalPresenter::decisions($v->decisions),
            'levels' => ApprovalPresenter::levels($this->workflow, 'vacation', $v),
            'overlaps' => $this->overlaps($user, $v),
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function store(StoreVacationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $employee = $this->scope->employeeOrFail($user, (int) $data['employee_id']);
        abort_unless($employee->user_id === $user->id || $user->can('vacations.create'), 403);

        $created = $this->vacations->request($user, $employee, $data['starts_on'], $data['ends_on'], $data['comment'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Solicitud registrada por {$created->days} días laborables."]);

        return to_route('vacaciones.show', $created->id);
    }

    public function decide(DecisionRequest $request, int $vacation): RedirectResponse
    {
        $user = $request->user();
        $result = $this->vacations->decide($user, $this->find($user, $vacation), $request->approves(), $request->validated('comment'));
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($result->status) {
            ApprovalStatus::Approved => 'Vacaciones aprobadas.',
            ApprovalStatus::Rejected => 'Solicitud de vacaciones rechazada.',
            default => 'Decisión registrada; la solicitud pasa al siguiente nivel.',
        }]);

        return back();
    }

    public function cancel(ListFiltersRequest $request, int $vacation): RedirectResponse
    {
        $this->vacations->cancel($request->user(), $this->find($request->user(), $vacation));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud de vacaciones cancelada.']);

        return back();
    }

    private function find(User $user, int $id): VacationRequest
    {
        $v = VacationRequest::query()->with('employee')->findOrFail($id);
        abort_unless($this->scope->canAccessEmployee($user, $v->employee_id), 404);

        return $v;
    }

    private function month(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $value)->startOfMonth();
        }

        return $this->scope->today()->startOfMonth();
    }

    /**
     * Ausencias del mes por día y conflictos de cobertura por sucursal.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function calendar(User $user, array $filters, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();

        $query = VacationRequest::query()->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Pending])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start);
        $this->scope->apply($query, $user, $filters);
        $requests = $query->orderBy('starts_on')->get();
        $cards = $this->scope->employeeCards($requests->pluck('employee_id'));

        // Plantilla por sucursal (alcance del usuario) para medir cobertura.
        $headcount = [];
        $employees = $this->scope->employees($user)->where('employees.status', 'active')->pluck('employees.id');
        foreach ($this->scope->employeeCards($employees) as $card) {
            $key = (string) ($card['branch_id'] ?? 0);
            $headcount[$key] = ($headcount[$key] ?? 0) + 1;
        }

        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $d) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'absences' => [], 'by_branch' => []];
        }

        foreach ($requests as $r) {
            $card = $cards[$r->employee_id] ?? null;
            $from = CarbonImmutable::parse($r->starts_on)->max($start);
            $to = CarbonImmutable::parse($r->ends_on)->min($end);
            foreach (CarbonPeriod::create($from, $to) as $d) {
                $key = $d->toDateString();
                $days[$key]['absences'][] = [
                    'request_id' => $r->id,
                    'employee_id' => $r->employee_id,
                    'name' => $card['name'] ?? 'Colaborador',
                    'branch' => $card['branch'] ?? null,
                    'status' => $r->status->value,
                ];
                $branchKey = (string) ($card['branch_id'] ?? 0);
                $days[$key]['by_branch'][$branchKey] = ($days[$key]['by_branch'][$branchKey] ?? 0) + 1;
            }
        }

        $branchNames = collect($cards)->mapWithKeys(fn ($c) => [(string) ($c['branch_id'] ?? 0) => $c['branch'] ?? 'Sin sucursal']);

        $out = [];
        foreach ($days as $date => $day) {
            $conflicts = [];
            foreach ($day['by_branch'] as $branchKey => $count) {
                $total = max(1, $headcount[$branchKey] ?? 1);
                $ratio = $count / $total;
                if ($count >= 2 || $ratio >= 0.3) {
                    $conflicts[] = [
                        'branch' => $branchNames[$branchKey] ?? 'Sin sucursal',
                        'absent' => $count,
                        'headcount' => $headcount[$branchKey] ?? $count,
                        'ratio' => round($ratio * 100, 1),
                        'level' => $ratio >= 0.3 ? 'high' : 'medium',
                    ];
                }
            }
            $out[] = ['date' => (string) $date, 'absences' => $day['absences'], 'conflicts' => $conflicts];
        }

        return [
            'month' => $start->format('Y-m'),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $out,
            'total_requests' => $requests->count(),
            'conflict_days' => count(array_filter($out, fn ($d) => $d['conflicts'] !== [])),
        ];
    }

    /**
     * Compañeros de la misma sucursal ausentes en el mismo periodo.
     *
     * @return list<array<string, mixed>>
     */
    private function overlaps(User $user, VacationRequest $v): array
    {
        $card = $this->scope->employeeCards([$v->employee_id])[$v->employee_id] ?? null;
        $query = VacationRequest::query()->whereKeyNot($v->id)
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Pending])
            ->whereDate('starts_on', '<=', $v->ends_on)->whereDate('ends_on', '>=', $v->starts_on);
        $this->scope->apply($query, $user, ['branch_id' => $card['branch_id'] ?? null]);
        $rows = $query->limit(20)->get();
        $cards = $this->scope->employeeCards($rows->pluck('employee_id'));

        return array_values($rows->map(fn (VacationRequest $o) => [
            'id' => $o->id,
            'employee' => $cards[$o->employee_id] ?? null,
            'starts_on' => $o->starts_on->toDateString(),
            'ends_on' => $o->ends_on->toDateString(),
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
        ])->all());
    }

    /**
     * @return array{pending:int, approved_upcoming:int}
     */
    private function counts(User $user): array
    {
        $base = VacationRequest::query();
        $this->scope->apply($base, $user, []);

        return [
            'pending' => (clone $base)->where('status', ApprovalStatus::Pending)->count(),
            'approved_upcoming' => (clone $base)->where('status', ApprovalStatus::Approved)->whereDate('ends_on', '>=', $this->scope->today())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $card
     * @return array<string, mixed>
     */
    private function row(VacationRequest $v, ?array $card, User $user): array
    {
        $level = $v->status === ApprovalStatus::Pending ? $this->workflow->currentLevel($v, 'vacation') : null;
        $own = $v->employee?->user_id === $user->id;

        return [
            'id' => $v->id,
            'employee' => $card,
            'starts_on' => $v->starts_on->toDateString(),
            'ends_on' => $v->ends_on->toDateString(),
            'days' => (int) $v->days,
            'service_year' => (int) $v->service_year,
            'status' => $v->status->value,
            'status_label' => $v->status->label(),
            'current_level_label' => $level['label'] ?? null,
            'requested_by' => $v->requestedBy?->name,
            'reviewed_by' => $v->reviewedBy?->name,
            'reviewed_at' => OperacionScope::iso($v->reviewed_at),
            'created_at' => OperacionScope::iso($v->created_at),
            'can_decide' => $v->status === ApprovalStatus::Pending && $this->workflow->canDecide($user, $v, 'vacation'),
            'can_cancel' => ($v->status === ApprovalStatus::Pending || ($v->status === ApprovalStatus::Approved && $v->starts_on->isFuture()))
                && ($own || $user->can('vacations.approve')),
        ];
    }
}
