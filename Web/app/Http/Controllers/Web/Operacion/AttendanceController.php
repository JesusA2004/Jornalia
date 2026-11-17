<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchRecorder;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Enums\WorkdayStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\AttendancePresenter;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Http\Requests\Web\Operacion\WebPunchRequest;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asistencias: jornadas calculadas (proyección) y marcajes originales
 * (append-only, sólo lectura). Detalle por colaborador y día.
 */
final class AttendanceController extends Controller
{
    public function __construct(
        private readonly OperacionScope $scope,
        private readonly AttendancePresenter $presenter,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $onlyAuto = $request->query('auto') === '1';
        $tab = ($filters['tab'] ?? 'jornadas') === 'marcajes' ? 'marcajes' : 'jornadas';
        [$from, $to] = $this->scope->period($filters['from'] ?? null, $filters['to'] ?? null, 7);

        $props = [
            'tab' => $tab,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
                'auto' => $onlyAuto,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'sort' => $filters['sort'] ?? null,
                'direction' => $filters['direction'] ?? null,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'workday_statuses' => WorkdayStatus::options(),
                'punch_statuses' => PunchStatus::options(),
                'punch_types' => PunchType::options(),
            ],
            'timezone' => $this->scope->timezone(),
        ];

        if ($tab === 'jornadas') {
            [$column, $direction] = $request->sorting([
                'date' => 'date', 'worked_minutes' => 'worked_minutes', 'late_minutes' => 'late_minutes',
                'excess_minutes' => 'excess_minutes', 'first_in' => 'first_in', 'status' => 'status',
            ], 'date');

            $query = WorkdaySummary::query()->with(['branch:id,name', 'department:id,name', 'schedule:id,name'])
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
            $this->scope->apply($query, $user, $filters, useSummaryColumns: true);
            $query->when($onlyAuto, fn ($q) => $q->whereNotNull('auto_clock_out_at'));

            $totals = (clone $query)->toBase()->reorder()
                ->selectRaw('COUNT(*) as days, COALESCE(SUM(worked_minutes),0) as worked, COALESCE(SUM(late_minutes),0) as late, COALESCE(SUM(excess_minutes),0) as excess')
                ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent")
                ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days')
                ->selectRaw('SUM(CASE WHEN auto_clock_out_at IS NOT NULL THEN 1 ELSE 0 END) as auto_out')
                ->selectRaw('COALESCE(SUM(after_cutoff_minutes),0) as after_cutoff')
                ->first();

            $page = $query->orderBy($column, $direction)->orderBy('id', 'desc')->paginate($request->perPage(25))->withQueryString();
            $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
            $page->through(fn (WorkdaySummary $w) => AttendancePresenter::workday($w, $cards[$w->employee_id] ?? null));

            $props['workdays'] = $page;
            $props['totals'] = [
                'days' => (int) ($totals->days ?? 0),
                'worked_minutes' => (int) ($totals->worked ?? 0),
                'late_minutes' => (int) ($totals->late ?? 0),
                'excess_minutes' => (int) ($totals->excess ?? 0),
                'absent_days' => (int) ($totals->absent ?? 0),
                'late_days' => (int) ($totals->late_days ?? 0),
                'auto_clock_outs' => (int) ($totals->auto_out ?? 0),
                'after_cutoff_minutes' => (int) ($totals->after_cutoff ?? 0),
            ];
        } else {
            [$column, $direction] = $request->sorting(['server_time' => 'server_time', 'sequence' => 'sequence'], 'server_time');
            $query = Punch::query()
                ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
            $this->scope->apply($query, $user, $filters);
            $query->when($onlyAuto, fn ($q) => $q->where('source', PunchSource::System));

            $counts = (clone $query)->toBase()->reorder()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged")
                ->selectRaw('SUM(CASE WHEN captured_offline = 1 THEN 1 ELSE 0 END) as offline')
                ->selectRaw("SUM(CASE WHEN source = 'system' THEN 1 ELSE 0 END) as auto_out")
                ->first();

            $page = $query->orderBy($column, $direction)->paginate($request->perPage(25))->withQueryString();
            $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
            $page->through(fn (Punch $p) => AttendancePresenter::punch($p) + ['employee' => $cards[$p->employee_id] ?? null]);

            $props['punches'] = $page;
            $props['totals'] = [
                'punches' => (int) ($counts->total ?? 0),
                'flagged' => (int) ($counts->flagged ?? 0),
                'offline' => (int) ($counts->offline ?? 0),
                'auto_clock_outs' => (int) ($counts->auto_out ?? 0),
            ];
        }

        return Inertia::render('asistencias/Index', $props);
    }

    public function show(ListFiltersRequest $request, int $employee, string $date): Response
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $user = $request->user();
        $model = $this->scope->employeeOrFail($user, $employee);

        $day = CarbonImmutable::parse($date);
        $summary = WorkdaySummary::query()->with(['branch:id,name', 'department:id,name', 'schedule:id,name'])
            ->where('employee_id', $model->id)->whereDate('date', $date)->first();

        $week = WorkdaySummary::query()->where('employee_id', $model->id)
            ->whereBetween('date', [$day->subDays(6)->toDateString(), $day->addDays(6)->toDateString()])
            ->orderBy('date')->get()
            ->map(fn (WorkdaySummary $w) => ['date' => $w->date->toDateString(), 'status' => $w->status->value, 'status_label' => $w->status->label(), 'worked_minutes' => (int) $w->worked_minutes, 'late_minutes' => (int) $w->late_minutes])
            ->values();

        $rectifications = RectificationRequest::query()->where('employee_id', $model->id)->whereDate('date', $date)
            ->with('requestedBy:id,name')->latest()->get()
            ->map(fn (RectificationRequest $r) => [
                'id' => $r->id,
                'kind_label' => $r->kind->label(),
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'reason' => $r->reason,
                'requested_by' => $r->requestedBy?->name,
                'created_at' => OperacionScope::iso($r->created_at),
            ])->values();

        return Inertia::render('asistencias/Show', [
            'employee' => $this->scope->employeeCards([$model->id])[$model->id] ?? null,
            'date' => $date,
            'previous_date' => $day->subDay()->toDateString(),
            'next_date' => $day->addDay()->toDateString(),
            'summary' => $summary ? AttendancePresenter::workday($summary) : null,
            'day' => $this->presenter->day($model, $date),
            'week' => $week,
            'rectifications' => $rectifications,
            'can' => [
                'rectify' => $user->can('rectifications.create') || $model->user_id === $user->id,
            ],
        ]);
    }

    /**
     * Marcaje web propio. La política de asistencia decide si está permitido;
     * PunchRecorder valida secuencia, ubicación y anomalías.
     */
    public function punch(WebPunchRequest $request, PunchRecorder $recorder): RedirectResponse
    {
        $user = $request->user();
        $employee = Employee::query()->where('user_id', $user->id)->first();
        abort_if($employee === null, 403, 'Tu usuario no está vinculado a un expediente de colaborador.');

        $data = $request->validated();
        $hasLocation = isset($data['latitude'], $data['longitude']);

        $result = $recorder->record($user, $employee, new PunchInput(
            clientUuid: $data['client_uuid'],
            type: PunchType::from($data['type']),
            source: PunchSource::Web,
            authMethod: AuthMethod::WebSession,
            deviceTime: null,
            deviceTimeRaw: null,
            timezone: $this->scope->timezone(),
            deviceUuid: null,
            nonce: null,
            signature: null,
            capturedOffline: false,
            latitude: $hasLocation ? (float) $data['latitude'] : null,
            longitude: $hasLocation ? (float) $data['longitude'] : null,
            accuracyMeters: isset($data['accuracy_meters']) ? (int) $data['accuracy_meters'] : null,
            locationCapturedAt: $hasLocation ? CarbonImmutable::now('UTC') : null,
            locationMocked: null,
            faceVerificationId: null,
            ipAddress: $request->ip(),
            userAgent: substr((string) $request->userAgent(), 0, 250),
        ), null);

        $punch = $result['punch'];
        Inertia::flash('toast', [
            'type' => $punch->status === PunchStatus::Flagged ? 'warning' : 'success',
            'message' => $result['duplicate']
                ? 'Este marcaje ya estaba registrado.'
                : $punch->type->label().' registrada'.($punch->status === PunchStatus::Flagged ? ' — quedó en revisión.' : '.'),
        ]);

        return back();
    }
}
