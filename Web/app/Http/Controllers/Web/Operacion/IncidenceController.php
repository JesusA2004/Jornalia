<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Incidences\IncidenceService;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\ApprovalPresenter;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\DecisionRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Http\Requests\Web\Operacion\StoreIncidenceRequest;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Incidencias (permisos, faltas, incapacidades…). No alteran marcajes: al
 * aprobarse, el dominio recalcula la proyección diaria de cada día afectado.
 */
final class IncidenceController extends Controller
{
    public const CATEGORIES = [
        'absence' => 'Ausencia',
        'leave' => 'Permiso',
        'disability' => 'Incapacidad',
        'vacation' => 'Vacaciones',
        'time' => 'Tiempo',
        'other' => 'Otra',
    ];

    public function __construct(
        private readonly OperacionScope $scope,
        private readonly ApprovalWorkflow $workflow,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $tab = in_array($filters['tab'] ?? null, ['pendientes', 'resueltas', 'todas'], true) ? $filters['tab'] : 'pendientes';
        $typeId = (int) $request->query('type_id', 0) ?: null;

        $query = Incidence::query()->with(['type', 'requestedBy:id,name', 'reviewedBy:id,name'])->withCount('attachments')
            ->when($tab === 'pendientes', fn ($q) => $q->where('status', ApprovalStatus::Pending))
            ->when($tab === 'resueltas', fn ($q) => $q->where('status', '!=', ApprovalStatus::Pending))
            ->when(isset($filters['status']) && $tab !== 'pendientes', fn ($q) => $q->where('status', $filters['status']))
            ->when($typeId, fn ($q) => $q->where('incidence_type_id', $typeId))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('ends_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('starts_on', '<=', $filters['to']));
        $this->scope->apply($query, $user, $filters);

        $base = Incidence::query();
        $this->scope->apply($base, $user, []);
        $pending = (clone $base)->where('status', ApprovalStatus::Pending)->count();
        $all = (clone $base)->count();

        [$column, $direction] = $request->sorting(['created_at' => 'created_at', 'starts_on' => 'starts_on'], 'created_at', $tab === 'pendientes' ? 'asc' : 'desc');
        $page = $query->orderBy($column, $direction)->paginate($request->perPage(15))->withQueryString();
        $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
        $page->through(fn (Incidence $i) => $this->row($i, $cards[$i->employee_id] ?? null, $user));

        return Inertia::render('incidencias/Index', [
            'tab' => $tab,
            'incidences' => $page,
            'counts' => ['pending' => $pending, 'resolved' => $all - $pending, 'all' => $all],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
                'type_id' => $typeId ? (string) $typeId : null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'types' => $this->types(),
                'statuses' => ApprovalStatus::options(),
            ],
            'can' => ['create' => $user->can('incidences.create')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function show(ListFiltersRequest $request, int $incidence): Response
    {
        $user = $request->user();
        $i = $this->find($user, $incidence);
        $i->load(['type', 'requestedBy:id,name', 'reviewedBy:id,name', 'attachments.uploadedBy:id,name', 'decisions.decidedBy:id,name']);

        return Inertia::render('incidencias/Show', [
            'incidence' => $this->row($i, $this->scope->employeeCards([$i->employee_id])[$i->employee_id] ?? null, $user) + [
                'reason' => $i->reason,
                'review_comment' => $i->review_comment,
                'type' => $this->typeData($i->type),
            ],
            'attachments' => ApprovalPresenter::attachments($i->attachments, fn ($a) => route('incidencias.evidence', [$i->id, $a->id])),
            'decisions' => ApprovalPresenter::decisions($i->decisions),
            'levels' => ApprovalPresenter::levels($this->workflow, 'incidence', $i),
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function store(StoreIncidenceRequest $request, IncidenceService $service): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $employee = $this->scope->employeeOrFail($user, (int) $data['employee_id']);
        $onBehalf = $employee->user_id !== $user->id;
        abort_if($onBehalf && ! $user->can('incidences.create'), 403);

        $created = $service->submit($user, $employee, [
            'incidence_type_id' => (int) $data['incidence_type_id'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'reason' => $data['reason'] ?? null,
            'minutes' => $data['minutes'] ?? null,
        ], $request->file('evidence'), $onBehalf);

        Inertia::flash('toast', ['type' => 'success', 'message' => $created->status === ApprovalStatus::Approved
            ? 'Incidencia registrada y aplicada (no requiere aprobación).'
            : 'Incidencia registrada; queda pendiente de aprobación.']);

        return to_route('incidencias.show', $created->id);
    }

    public function decide(DecisionRequest $request, IncidenceService $service, int $incidence): RedirectResponse
    {
        $user = $request->user();
        $result = $service->decide($user, $this->find($user, $incidence), $request->approves(), $request->validated('comment'));

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($result->status) {
            ApprovalStatus::Approved => 'Incidencia aprobada. Las jornadas afectadas se recalcularán.',
            ApprovalStatus::Rejected => 'Incidencia rechazada.',
            default => 'Decisión registrada; la incidencia pasa al siguiente nivel.',
        }]);

        return back();
    }

    public function cancel(ListFiltersRequest $request, IncidenceService $service, int $incidence): RedirectResponse
    {
        $service->cancel($request->user(), $this->find($request->user(), $incidence));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Incidencia cancelada.']);

        return back();
    }

    public function evidence(ListFiltersRequest $request, int $incidence, int $attachment): StreamedResponse
    {
        $i = $this->find($request->user(), $incidence);
        $file = $i->attachments()->whereKey($attachment)->firstOrFail();
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    private function find(User $user, int $id): Incidence
    {
        $i = Incidence::query()->with('employee')->findOrFail($id);
        abort_unless($this->scope->canAccessEmployee($user, $i->employee_id), 404);

        return $i;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function types(): array
    {
        return IncidenceType::query()->where('active', true)->orderBy('name')->get()
            ->map(fn (IncidenceType $t) => ['value' => (string) $t->id, 'label' => $t->name, 'description' => self::CATEGORIES[$t->category] ?? $t->category] + $this->typeData($t))
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function typeData(?IncidenceType $t): array
    {
        if ($t === null) {
            return [];
        }

        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'category' => $t->category,
            'category_label' => self::CATEGORIES[$t->category] ?? $t->category,
            'requires_evidence' => (bool) $t->requires_evidence,
            'requires_approval' => (bool) $t->requires_approval,
            'affects_payroll' => (bool) $t->affects_payroll,
            'employee_requestable' => (bool) $t->employee_requestable,
            'description' => $t->description,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $card
     * @return array<string, mixed>
     */
    private function row(Incidence $i, ?array $card, User $user): array
    {
        $level = $i->status === ApprovalStatus::Pending ? $this->workflow->currentLevel($i, 'incidence') : null;

        return [
            'id' => $i->id,
            'employee' => $card,
            'type_name' => $i->type?->name,
            'type_code' => $i->type?->code,
            'category' => $i->type?->category,
            'category_label' => self::CATEGORIES[$i->type?->category ?? ''] ?? null,
            'starts_on' => $i->starts_on->toDateString(),
            'ends_on' => $i->ends_on->toDateString(),
            'days' => (int) $i->starts_on->diffInDays($i->ends_on) + 1,
            'minutes' => $i->minutes,
            'source' => $i->source,
            'source_label' => match ($i->source) {
                'manager' => 'Registrada por responsable',
                'system' => 'Generada por el sistema',
                default => 'Solicitada por colaborador',
            },
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'reason_excerpt' => Str::limit((string) $i->reason, 120),
            'current_level' => (int) $i->current_level,
            'current_level_label' => $level['label'] ?? null,
            'requested_by' => $i->requestedBy?->name,
            'reviewed_by' => $i->reviewedBy?->name,
            'reviewed_at' => OperacionScope::iso($i->reviewed_at),
            'attachments_count' => (int) ($i->attachments_count ?? $i->attachments()->count()),
            'created_at' => OperacionScope::iso($i->created_at),
            'can_decide' => $i->status === ApprovalStatus::Pending && $this->workflow->canDecide($user, $i, 'incidence'),
            'can_cancel' => $i->status === ApprovalStatus::Pending && $i->requested_by === $user->id,
        ];
    }
}
