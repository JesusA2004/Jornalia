<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Attendance\RectificationService;
use App\Domain\Rectifications\RectificationSettings;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Signatures\ElectronicSignatureService;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Enums\RectificationStage;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rectificaciones en 5 pasos (PDF pantalla 05). El marcaje original nunca se
 * modifica: la validación RH —sólo con firma del colaborador— genera ajustes
 * encadenados y recalcula la jornada.
 */
final class RectificationController extends Controller
{
    /** Pestaña (URL) → etapa. */
    public const TABS = [
        'por_autorizar' => RectificationStage::ToAuthorize,
        'por_firmar' => RectificationStage::ToSign,
        'por_validar' => RectificationStage::ToValidate,
        'aplicadas' => RectificationStage::Applied,
        'rechazadas' => RectificationStage::Rejected,
    ];

    /** Alias aceptados en `?tab=`. */
    private const TAB_ALIASES = ['aplicada' => 'aplicadas', 'rechazada' => 'rechazadas', 'pendientes' => 'por_autorizar', 'por_firmar_colaborador' => 'por_firmar', 'por_validar_rh' => 'por_validar'];

    public function __construct(
        private readonly OperacionScope $scope,
        private readonly RectificationService $service,
    ) {}

    public function index(ListFiltersRequest $request, RectificationSettings $settings): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $tab = $this->tab($request->query('tab'));

        $query = RectificationRequest::query()->with(['requestedBy:id,name', 'authorizedBy:id,name', 'signature'])->withCount('attachments')
            ->where('stage', self::TABS[$tab])
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']));
        $this->scope->apply($query, $user, $filters);

        $open = in_array($tab, ['por_autorizar', 'por_firmar', 'por_validar'], true);
        [$column, $direction] = $request->sorting(['created_at' => 'created_at', 'date' => 'date'], 'created_at', $open ? 'asc' : 'desc');
        $page = $query->orderBy($column, $direction)->paginate($request->perPage(15))->withQueryString();
        $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
        $page->through(fn (RectificationRequest $r) => $this->row($r, $cards[$r->employee_id] ?? null, $user));

        return Inertia::render('rectificaciones/Index', [
            'tab' => $tab,
            'requests' => $page,
            'counts' => $this->counts($user),
            'filters' => $this->filterProps($filters),
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'kinds' => RectificationKind::options(),
                'punch_types' => PunchType::options(),
                'stages' => RectificationStage::options(),
            ],
            'settings' => $settings->all(),
            'can' => ['create' => $user->can('rectifications.create')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function show(ListFiltersRequest $request, AttendancePresenter $presenter, ElectronicSignatureService $signatures, int $rectification): Response
    {
        $user = $request->user();
        $r = $this->find($user, $rectification);
        $r->load(['requestedBy:id,name', 'reviewedBy:id,name', 'authorizedBy:id,name', 'validatedBy:id,name', 'rejectedBy:id,name',
            'attachments.uploadedBy:id,name', 'decisions.decidedBy:id,name', 'adjustments.approvedBy:id,name', 'signature.device']);

        $target = $r->punch_uuid ? Punch::query()->with(['device', 'geofence'])->where('uuid', $r->punch_uuid)->first() : null;
        $sig = $r->signature;

        return Inertia::render('rectificaciones/Show', [
            'request' => $this->row($r, $this->scope->employeeCards([$r->employee_id])[$r->employee_id] ?? null, $user) + [
                'reason' => $r->reason,
                'review_comment' => $r->review_comment,
                'proposed_type' => $r->proposed_type?->value,
                'authorization_comment' => $r->authorization_comment,
                'validation_comment' => $r->validation_comment,
                'rejection_reason' => $r->rejection_reason,
                'rejected_by' => $r->rejectedBy?->name,
                'rejected_at' => OperacionScope::iso($r->rejected_at),
                'validated_by' => $r->validatedBy?->name,
                'validated_at' => OperacionScope::iso($r->validated_at),
                'authorized_at' => OperacionScope::iso($r->authorized_at),
                'applied_at' => OperacionScope::iso($r->applied_at),
                'legacy_without_signature' => (bool) $r->legacy_without_signature,
                'origin' => $r->origin,
                'incidence_id' => $r->incidence_id,
            ],
            'signature' => $sig ? [
                'method' => $sig->method,
                'method_label' => $sig->methodLabel(),
                'signed_at' => OperacionScope::iso($sig->signed_at),
                'device' => $sig->device?->name,
                'ip' => $sig->ip,
                'hash' => $sig->hash,
                'payload_sha256' => $sig->payload_sha256,
                'statement' => $sig->statement,
                'sealed' => $signatures->verifySeal($sig),
            ] : null,
            'target_punch' => $target ? AttendancePresenter::punch($target, true) : null,
            'attachments' => ApprovalPresenter::attachments($r->attachments, fn ($a) => route('rectificaciones.evidence', [$r->id, $a->id])),
            'decisions' => ApprovalPresenter::decisions($r->decisions),
            'adjustments' => $r->adjustments->map(fn ($a) => AttendancePresenter::adjustment($a))->values(),
            'day' => $presenter->day($r->employee, $r->date->toDateString()),
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function store(StoreRectificationRequest $request, ScheduleResolver $schedules): RedirectResponse
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

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->file('evidence', []), fn ($f) => $f instanceof UploadedFile));

        $created = $this->service->submit($user, $employee, [
            'date' => $data['date'],
            'punch_uuid' => $data['punch_uuid'] ?? null,
            'kind' => $data['kind'],
            'proposed_type' => $data['proposed_type'] ?? null,
            'proposed_time' => $proposed,
            'reason' => $data['reason'],
        ], $files, $employee->user_id === $user->id ? 'employee' : 'manager');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud registrada. Pasa a autorización del jefe inmediato.']);

        return to_route('rectificaciones.show', $created->id);
    }

    /**
     * Decisión de la etapa actual: autorización del jefe (por_autorizar) o
     * validación RH (por_validar). `stage` es la etapa que vio quien decide:
     * si cambió entretanto, la decisión se rechaza (evita decisiones duplicadas).
     */
    public function decide(DecisionRequest $request, int $rectification): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['rectifications.approve', 'rectifications.validate']), 403);
        $r = $this->find($user, $rectification);
        $expected = (string) $request->input('stage', $r->stage->value);
        if ($expected !== $r->stage->value) {
            throw ValidationException::withMessages(['stage' => 'La solicitud cambió de etapa; recarga la página.']);
        }

        $approve = $request->approves();
        $comment = $request->validated('comment');

        $result = match ($r->stage) {
            RectificationStage::ToAuthorize => $this->service->authorize($user, $r, $approve, $comment),
            RectificationStage::ToValidate => $this->service->validate($user, $r, $approve, $comment),
            default => throw ValidationException::withMessages(['stage' => 'Esta etapa no admite decisión desde la web.']),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($result->stage) {
            RectificationStage::ToSign => 'Autorizada. Se envió al colaborador para su firma.',
            RectificationStage::Applied => 'Validada y aplicada. La jornada se recalculará.',
            default => 'Solicitud rechazada.',
        }]);

        return back();
    }

    public function cancel(ListFiltersRequest $request, int $rectification): RedirectResponse
    {
        $r = $this->find($request->user(), $rectification);
        $this->service->cancel($request->user(), $r);
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
                'type_label' => $p['type_label'],
                'time' => $p['recognized_time'],
                'server_time' => $p['server_time'],
                'source_label' => $p['source_label'],
                'auth_method_label' => $p['auth_method_label'],
                'status' => $p['status'],
                'status_label' => $p['status_label'],
                'is_system' => (bool) ($p['is_system'] ?? false),
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

    private function tab(mixed $value): string
    {
        $tab = is_string($value) ? (self::TAB_ALIASES[$value] ?? $value) : 'por_autorizar';

        return array_key_exists($tab, self::TABS) ? $tab : 'por_autorizar';
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
        $signatureStatus = match (true) {
            $r->signature !== null => 'firmada',
            (bool) $r->legacy_without_signature => 'no_requerida',
            $r->stage === RectificationStage::ToSign => 'pendiente',
            $r->rejection_stage === 'vencida' => 'vencida',
            $r->rejection_stage === 'declinada' => 'no_aceptada',
            default => 'en_espera',
        };

        return [
            'id' => $r->id,
            'employee' => $card,
            'date' => $r->date->toDateString(),
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'stage' => $r->stage->value,
            'stage_label' => $r->stage->label(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'punch_uuid' => $r->punch_uuid,
            'original_summary' => $this->service->originalSummary($r),
            'proposal_summary' => $this->service->proposalSummary($r),
            'proposed_type_label' => $r->proposed_type?->label(),
            'proposed_time' => OperacionScope::iso($r->proposed_time),
            'reason_excerpt' => Str::limit($r->reason, 120),
            'signature_status' => $signatureStatus,
            'rejection_stage' => $r->rejection_stage,
            'signature_due_at' => OperacionScope::iso($r->signature_due_at),
            'signed_at' => OperacionScope::iso($r->signed_at),
            'is_late' => $r->stage === RectificationStage::Applied ? (bool) $r->is_late : $this->service->isLate($r),
            'requested_by' => $r->requestedBy?->name,
            'authorized_by' => $r->authorizedBy?->name,
            'reviewed_by' => $r->reviewedBy?->name,
            'reviewed_at' => OperacionScope::iso($r->reviewed_at),
            'attachments_count' => (int) ($r->attachments_count ?? $r->attachments()->count()),
            'created_at' => OperacionScope::iso($r->created_at),
            'can_authorize' => $this->service->canAuthorize($user, $r),
            'can_validate' => $this->service->canValidate($user, $r),
            'can_apply_late' => $user->can('rectifications.apply_late'),
            'can_cancel' => $r->stage === RectificationStage::ToAuthorize && $r->requested_by === $user->id,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        $base = RectificationRequest::query();
        $this->scope->apply($base, $user, []);
        $byStage = $base->toBase()->selectRaw('stage, COUNT(*) as total')->groupBy('stage')->pluck('total', 'stage');

        $counts = [];
        foreach (self::TABS as $tab => $stage) {
            $counts[$tab] = (int) ($byStage[$stage->value] ?? 0);
        }

        return $counts;
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
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ];
    }
}
