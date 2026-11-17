<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Attendance\RectificationService;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\ApprovalPresenter;
use App\Http\Controllers\Web\Operacion\Support\AttendancePresenter;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\DecisionRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Http\Requests\Web\Operacion\StoreRectificationRequest;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja de rectificaciones: el marcaje original nunca se modifica; la
 * aprobación genera ajustes encadenados y recalcula la jornada.
 */
final class RectificationController extends Controller
{
    public function __construct(
        private readonly OperacionScope $scope,
        private readonly ApprovalWorkflow $workflow,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $tab = in_array($filters['tab'] ?? null, ['pendientes', 'resueltas', 'todas'], true) ? $filters['tab'] : 'pendientes';

        $query = RectificationRequest::query()->with(['requestedBy:id,name', 'reviewedBy:id,name'])->withCount('attachments')
            ->when($tab === 'pendientes', fn ($q) => $q->where('status', ApprovalStatus::Pending))
            ->when($tab === 'resueltas', fn ($q) => $q->where('status', '!=', ApprovalStatus::Pending))
            ->when(isset($filters['status']) && $tab !== 'pendientes', fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']));
        $this->scope->apply($query, $user, $filters);

        $counts = $this->counts($user);

        [$column, $direction] = $request->sorting(['created_at' => 'created_at', 'date' => 'date'], 'created_at', $tab === 'pendientes' ? 'asc' : 'desc');
        $page = $query->orderBy($column, $direction)->paginate($request->perPage(15))->withQueryString();
        $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
        $page->through(fn (RectificationRequest $r) => $this->row($r, $cards[$r->employee_id] ?? null, $user));

        return Inertia::render('rectificaciones/Index', [
            'tab' => $tab,
            'requests' => $page,
            'counts' => $counts,
            'filters' => $this->filterProps($filters),
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'kinds' => RectificationKind::options(),
                'punch_types' => PunchType::options(),
                'statuses' => ApprovalStatus::options(),
            ],
            'can' => ['create' => $user->can('rectifications.create')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function show(ListFiltersRequest $request, AttendancePresenter $presenter, int $rectification): Response
    {
        $user = $request->user();
        $r = $this->find($user, $rectification);
        $r->load(['requestedBy:id,name', 'reviewedBy:id,name', 'attachments.uploadedBy:id,name', 'decisions.decidedBy:id,name', 'adjustments.approvedBy:id,name']);

        $target = $r->punch_uuid ? Punch::query()->with(['device', 'geofence'])->where('uuid', $r->punch_uuid)->first() : null;

        return Inertia::render('rectificaciones/Show', [
            'request' => $this->row($r, $this->scope->employeeCards([$r->employee_id])[$r->employee_id] ?? null, $user) + [
                'reason' => $r->reason,
                'review_comment' => $r->review_comment,
                'proposed_time' => OperacionScope::iso($r->proposed_time),
                'proposed_type' => $r->proposed_type?->value,
                'proposed_type_label' => $r->proposed_type?->label(),
            ],
            'target_punch' => $target ? AttendancePresenter::punch($target, true) : null,
            'attachments' => ApprovalPresenter::attachments($r->attachments, fn ($a) => route('rectificaciones.evidence', [$r->id, $a->id])),
            'decisions' => ApprovalPresenter::decisions($r->decisions),
            'levels' => ApprovalPresenter::levels($this->workflow, 'rectification', $r),
            'adjustments' => $r->adjustments->map(fn ($a) => AttendancePresenter::adjustment($a))->values(),
            'day' => $presenter->day($r->employee, $r->date->toDateString()),
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function store(StoreRectificationRequest $request, RectificationService $service, ScheduleResolver $schedules): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $employee = $this->scope->employeeOrFail($user, (int) $data['employee_id']);
        abort_unless($employee->user_id === $user->id || $user->can('rectifications.create'), 403);

        $proposed = null;
        if (! empty($data['proposed_time'])) {
            $tz = $schedules->timezoneFor($employee);
            $proposed = CarbonImmutable::parse($data['date'].' '.$data['proposed_time'], $tz)->utc()->toIso8601String();
        }

        $created = $service->submit($user, $employee, [
            'date' => $data['date'],
            'punch_uuid' => $data['punch_uuid'] ?? null,
            'kind' => $data['kind'],
            'proposed_type' => $data['proposed_type'] ?? null,
            'proposed_time' => $proposed,
            'reason' => $data['reason'],
        ], $request->file('evidence'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud de rectificación registrada.']);

        return to_route('rectificaciones.show', $created->id);
    }

    public function decide(DecisionRequest $request, RectificationService $service, int $rectification): RedirectResponse
    {
        $user = $request->user();
        $r = $this->find($user, $rectification);
        $result = $service->decide($user, $r, $request->approves(), $request->validated('comment'), (bool) $request->validated('void_target', false));

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($result->status) {
            ApprovalStatus::Approved => 'Rectificación aprobada. La jornada se recalculará.',
            ApprovalStatus::Rejected => 'Rectificación rechazada.',
            default => 'Decisión registrada; la solicitud pasa al siguiente nivel.',
        }]);

        return back();
    }

    public function cancel(ListFiltersRequest $request, RectificationService $service, int $rectification): RedirectResponse
    {
        $r = $this->find($request->user(), $rectification);
        $service->cancel($request->user(), $r);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud cancelada.']);

        return back();
    }

    /**
     * Marcajes de un colaborador en un día, para elegir visualmente cuál rectificar.
     */
    public function punches(ListFiltersRequest $request, AttendancePresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $employeeId = (int) $request->validated('employee_id');
        $date = (string) $request->query('date', '');
        abort_if($employeeId === 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1, 422, 'Indica colaborador y fecha.');
        $employee = $this->scope->employeeOrFail($user, $employeeId);
        abort_unless($employee->user_id === $user->id || $user->can('rectifications.create'), 403);

        $day = $presenter->day($employee, $date);

        return response()->json([
            'date' => $date,
            'timezone' => $day['timezone'],
            'shift' => $day['shift'],
            'punches' => array_map(fn (array $p) => [
                'uuid' => $p['uuid'],
                'type' => $p['type'],
                'type_label' => $p['is_system'] ? 'Salida automática' : $p['type_label'],
                'time' => $p['recognized_time'],
                'server_time' => $p['server_time'],
                'source_label' => $p['source_label'],
                'auth_method_label' => $p['auth_method_label'],
                'status' => $p['status'],
                'status_label' => $p['status_label'],
                'is_system' => $p['is_system'],
                'voided' => $p['voided'] ?? false,
                'anomalies' => $p['anomalies'],
            ], $day['punches']),
        ]);
    }

    public function evidence(ListFiltersRequest $request, int $rectification, int $attachment): StreamedResponse
    {
        $r = $this->find($request->user(), $rectification);
        $file = $r->attachments()->whereKey($attachment)->firstOrFail();
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    private function find(User $user, int $id): RectificationRequest
    {
        $r = RectificationRequest::query()->with('employee')->findOrFail($id);
        abort_unless($this->scope->canAccessEmployee($user, $r->employee_id), 404);

        return $r;
    }

    /**
     * @param  array<string, mixed>|null  $card
     * @return array<string, mixed>
     */
    private function row(RectificationRequest $r, ?array $card, User $user): array
    {
        $level = $r->status === ApprovalStatus::Pending ? $this->workflow->currentLevel($r, 'rectification') : null;

        return [
            'id' => $r->id,
            'employee' => $card,
            'date' => $r->date->toDateString(),
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'punch_uuid' => $r->punch_uuid,
            'proposed_type_label' => $r->proposed_type?->label(),
            'proposed_time' => OperacionScope::iso($r->proposed_time),
            'reason_excerpt' => Str::limit($r->reason, 120),
            'current_level' => (int) $r->current_level,
            'current_level_label' => $level['label'] ?? null,
            'requested_by' => $r->requestedBy?->name,
            'reviewed_by' => $r->reviewedBy?->name,
            'reviewed_at' => OperacionScope::iso($r->reviewed_at),
            'attachments_count' => (int) ($r->attachments_count ?? $r->attachments()->count()),
            'created_at' => OperacionScope::iso($r->created_at),
            'can_decide' => $r->status === ApprovalStatus::Pending && $this->workflow->canDecide($user, $r, 'rectification'),
            'can_cancel' => $r->status === ApprovalStatus::Pending && $r->requested_by === $user->id,
        ];
    }

    /**
     * @return array{pending:int, resolved:int, all:int}
     */
    private function counts(User $user): array
    {
        $base = RectificationRequest::query();
        $this->scope->apply($base, $user, []);
        $pending = (clone $base)->where('status', ApprovalStatus::Pending)->count();
        $all = (clone $base)->count();

        return ['pending' => $pending, 'resolved' => $all - $pending, 'all' => $all];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterProps(array $filters): array
    {
        return [
            'search' => $filters['search'] ?? '',
            'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
            'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
            'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ];
    }
}
