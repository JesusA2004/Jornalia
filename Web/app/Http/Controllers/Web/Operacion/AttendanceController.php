<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Attendance\Anomaly;
use App\Domain\Attendance\Omissions;
use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchOrigin;
use App\Domain\Attendance\PunchRecorder;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Enums\RectificationStage;
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
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asistencias: jornadas calculadas (proyección) y marcajes originales
 * (append-only, sólo lectura). Detalle por colaborador y día.
 */
final class AttendanceController extends Controller
{
    /** Máximo de marcajes por exportación CSV. */
    private const EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly OperacionScope $scope,
        private readonly AttendancePresenter $presenter,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $onlyOmissions = $request->query('omisiones') === '1';
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
                'omisiones' => $onlyOmissions,
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
            // «Sólo omisiones de checada»: combinable con el resto de filtros.
            $query->when($onlyOmissions, fn ($q) => Omissions::only($q));

            $totals = (clone $query)->toBase()->reorder()
                ->selectRaw('COUNT(*) as days, COALESCE(SUM(worked_minutes),0) as worked, COALESCE(SUM(late_minutes),0) as late, COALESCE(SUM(excess_minutes),0) as excess')
                ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent")
                ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days')
                ->selectRaw("SUM(CASE WHEN status = 'incomplete' THEN 1 ELSE 0 END) as incomplete")
                ->first();
            $omissions = Omissions::only(clone $query)->count();

            $page = $query->orderBy($column, $direction)->orderBy('id', 'desc')->paginate($request->perPage(25))->withQueryString();
            $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
            $origins = $this->origins($page->getCollection());
            $rectifications = $this->appliedRectifications($page->getCollection());
            $page->through(fn (WorkdaySummary $w) => AttendancePresenter::workday(
                $w,
                $cards[$w->employee_id] ?? null,
                PunchOrigin::present($origins[$w->id] ?? null),
                $rectifications[$w->employee_id.'|'.$w->date->toDateString()] ?? null,
            ));

            $props['workdays'] = $page;
            $props['totals'] = [
                'days' => (int) ($totals->days ?? 0),
                'worked_minutes' => (int) ($totals->worked ?? 0),
                'late_minutes' => (int) ($totals->late ?? 0),
                'excess_minutes' => (int) ($totals->excess ?? 0),
                'absent_days' => (int) ($totals->absent ?? 0),
                'late_days' => (int) ($totals->late_days ?? 0),
                'omissions' => $omissions,
            ];
        } else {
            [$column, $direction] = $request->sorting(['server_time' => 'server_time', 'sequence' => 'sequence'], 'server_time');
            $query = Punch::query()
                ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
            $this->scope->apply($query, $user, $filters);
            $counts = (clone $query)->toBase()->reorder()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged")
                ->selectRaw('SUM(CASE WHEN captured_offline = 1 THEN 1 ELSE 0 END) as offline')
                ->first();

            $page = $query->orderBy($column, $direction)->paginate($request->perPage(25))->withQueryString();
            $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
            $page->through(fn (Punch $p) => AttendancePresenter::punch($p) + ['employee' => $cards[$p->employee_id] ?? null]);

            $props['punches'] = $page;
            $props['totals'] = [
                'punches' => (int) ($counts->total ?? 0),
                'flagged' => (int) ($counts->flagged ?? 0),
                'offline' => (int) ($counts->offline ?? 0),
            ];
        }

        return Inertia::render('asistencias/Index', $props);
    }

    /**
     * Exportación CSV de los marcajes originales (sólo lectura, todos los roles con
     * `punches.view`). Respeta alcance y filtros; queda en bitácora.
     */
    public function exportPunches(ListFiltersRequest $request, AuditLogger $audit): StreamedResponse
    {
        $user = $request->user();
        $filters = $request->filters();
        [$from, $to] = $this->scope->period($filters['from'] ?? null, $filters['to'] ?? null, 7);
        $tz = $this->scope->timezone();

        $query = Punch::query()
            ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
        $this->scope->apply($query, $user, $filters);
        $query->orderBy('sequence')->limit(self::EXPORT_LIMIT);

        $audit->log('punches.exported', null, 'Exportación de marcajes originales '.$from->toDateString().' a '.$to->toDateString(), ['filters' => $filters]);

        $filename = 'marcajes-originales-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, 'ï»¿');
            fputcsv($out, ['Secuencia', 'Número', 'Colaborador', 'Fecha', 'Tipo', 'Hora oficial (servidor)', 'Hora del dispositivo', 'Recepción en servidor', 'Sincronización', 'Origen del marcaje', 'Autenticación', 'Estado', 'Anomalías', 'Latitud', 'Longitud', 'Precisión (m)', 'Hash'], escape: '');
            $query->with('employee:id,first_name,last_name,second_last_name,employee_number')->chunk(500, function ($punches) use ($out, $tz) {
                foreach ($punches as $p) {
                    /** @var Punch $p */
                    $time = fn ($t) => $t ? CarbonImmutable::instance($t)->setTimezone($tz)->format('Y-m-d H:i:s') : '';
                    fputcsv($out, [
                        $p->sequence,
                        $p->employee?->employee_number,
                        $p->employee?->fullName(),
                        $p->local_date->toDateString(),
                        $p->type->label(),
                        $time($p->server_time),
                        $time($p->device_time),
                        $time($p->received_at ?? $p->server_time),
                        $time($p->synced_at),
                        PunchOrigin::LABELS[PunchOrigin::forPunch($p)],
                        $p->auth_method->label(),
                        $p->status->label(),
                        implode('; ', array_map(fn ($a) => Anomaly::labelFor((string) $a), (array) $p->anomalies)),
                        $p->latitude,
                        $p->longitude,
                        $p->accuracy_meters,
                        $p->hash,
                    ], escape: '');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Origen del marcaje por jornada (una consulta para toda la página).
     *
     * @param  Collection<int, WorkdaySummary>  $workdays
     * @return array<int, string|null> id de jornada => código de origen
     */
    private function origins(Collection $workdays): array
    {
        if ($workdays->isEmpty()) {
            return [];
        }
        $punches = Punch::query()
            ->whereIn('employee_id', $workdays->pluck('employee_id')->unique()->values())
            ->whereIn('local_date', $workdays->map(fn (WorkdaySummary $w) => $w->date->toDateString())->unique()->values())
            ->get(['employee_id', 'local_date', 'source', 'captured_offline', 'anomalies', 'geofence_id'])
            ->groupBy(fn (Punch $p) => $p->employee_id.'|'.$p->local_date->toDateString());

        $origins = [];
        foreach ($workdays as $w) {
            $origins[$w->id] = PunchOrigin::forWorkday($w, $punches[$w->employee_id.'|'.$w->date->toDateString()] ?? collect());
        }

        return $origins;
    }

    /**
     * Rectificación aplicada (firmada) de cada jornada rectificada, para el enlace «Rectificada*».
     *
     * @param  Collection<int, WorkdaySummary>  $workdays
     * @return array<string, array{id:int, url:string|null}> "employee|fecha" => rectificación
     */
    private function appliedRectifications(Collection $workdays): array
    {
        $rectified = $workdays->filter(fn (WorkdaySummary $w) => $w->has_rectifications);
        if ($rectified->isEmpty()) {
            return [];
        }
        $canView = (bool) request()->user()?->can('rectifications.view');

        return RectificationRequest::query()
            ->whereIn('employee_id', $rectified->pluck('employee_id')->unique()->values())
            ->whereIn('date', $rectified->map(fn (WorkdaySummary $w) => $w->date->toDateString())->unique()->values())
            ->where('stage', RectificationStage::Applied)
            ->orderBy('id')
            ->get(['id', 'employee_id', 'date'])
            ->mapWithKeys(fn (RectificationRequest $r) => [$r->employee_id.'|'.$r->date->toDateString() => [
                'id' => $r->id,
                'url' => $canView ? route('rectificaciones.show', $r->id) : null,
            ]])->all();
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
