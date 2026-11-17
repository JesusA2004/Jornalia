<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\Anomaly;
use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Security\AccessScope;
use App\Enums\AlertSeverity;
use App\Enums\ApprovalStatus;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Enums\WorkdayStatus;
use App\Models\ComplianceAlert;
use App\Models\Incidence;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Construye la consulta de cada reporte con el alcance del usuario aplicado
 * (AccessScope + organización activa) y un mapeador a filas normalizadas:
 * fechas civiles `Y-m-d`, instantes ISO-8601 UTC, minutos enteros.
 */
final class ReportQuery
{
    private const SCHEDULED = "'present','late','incomplete','absent'";

    private const ATTENDED = "'present','late','incomplete','rest_day_worked','holiday_worked'";

    public function __construct(private readonly AccessScope $scope) {}

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    public function build(string $report, ReportParameters $p, User $user): array
    {
        return match ($report) {
            'asistencia_diaria' => $this->daily($p, $user),
            'asistencia_semanal' => $this->periodic($p, $user, 'week'),
            'asistencia_mensual' => $this->periodic($p, $user, 'month'),
            'puntualidad' => $this->punctuality($p, $user),
            'retardos' => $this->daily($p, $user, fn ($q) => $q->where('workday_summaries.late_minutes', '>', 0)),
            'faltas' => $this->daily($p, $user, fn ($q) => $q->where('workday_summaries.status', WorkdayStatus::Absent->value)),
            'horas_trabajadas' => $this->worked($p, $user),
            'horas_extra' => $this->overtime($p, $user),
            'vacaciones' => $this->vacations($p, $user),
            'incidencias' => $this->incidences($p, $user),
            'cumplimiento' => $this->compliance($p, $user),
            'historial_marcajes', 'inspeccion_laboral' => $this->punches($p, $user),
            'rectificaciones' => $this->rectifications($p, $user),
            'por_sucursal' => $this->grouped($p, $user, 'branch'),
            'por_departamento' => $this->grouped($p, $user, 'department'),
            'por_colaborador' => $this->byEmployee($p, $user),
            default => throw new InvalidArgumentException("Reporte desconocido: {$report}"),
        };
    }

    // ── Asistencia ────────────────────────────────────────────────

    /**
     * @param  (Closure(Builder<WorkdaySummary>): mixed)|null  $extra
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function daily(ReportParameters $p, User $user, ?Closure $extra = null): array
    {
        $q = $this->summaries($p, $user);
        if ($extra) {
            $extra($q);
        }
        $this->joinEmployee($q, 'workday_summaries.employee_id', summaryBranch: true);
        $q->select([
            'workday_summaries.date', 'workday_summaries.status', 'workday_summaries.scheduled_start', 'workday_summaries.scheduled_end',
            'workday_summaries.scheduled_minutes', 'workday_summaries.first_in', 'workday_summaries.last_out', 'workday_summaries.worked_minutes',
            'workday_summaries.late_minutes', 'workday_summaries.excess_minutes', 'workday_summaries.has_rectifications',
            'workday_summaries.auto_clock_out_at', 'workday_summaries.after_cutoff_minutes',
            ...$this->employeeSelect(),
        ])->orderBy('workday_summaries.date')->orderBy('e.last_name')->orderBy('e.first_name')->orderBy('workday_summaries.id');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            'date' => substr((string) $r->date, 0, 10),
            'status' => $r->status,
            'status_label' => WorkdayStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
            'scheduled_start' => self::iso($r->scheduled_start),
            'scheduled_end' => self::iso($r->scheduled_end),
            'scheduled_minutes' => (int) $r->scheduled_minutes,
            'first_in' => self::iso($r->first_in),
            'last_out' => self::iso($r->last_out),
            'worked_minutes' => (int) $r->worked_minutes,
            'late_minutes' => (int) $r->late_minutes,
            'excess_minutes' => (int) $r->excess_minutes,
            'auto_clock_out_at' => self::iso($r->auto_clock_out_at),
            'after_cutoff_minutes' => (int) $r->after_cutoff_minutes,
            'rectified' => $r->has_rectifications ? 'Sí' : 'No',
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function periodic(ReportParameters $p, User $user, string $unit): array
    {
        $q = $this->summaries($p, $user);
        $this->joinEmployee($q, 'workday_summaries.employee_id', summaryBranch: true);
        $periodExpr = $unit === 'week'
            ? 'DATE_SUB(workday_summaries.date, INTERVAL WEEKDAY(workday_summaries.date) DAY)'
            : "DATE_FORMAT(workday_summaries.date, '%Y-%m-01')";

        $q->select($this->employeeSelect())
            ->selectRaw("{$periodExpr} as period_start")
            ->selectRaw($this->aggregateSql())
            ->groupBy('workday_summaries.employee_id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.second_last_name', 'branch')
            ->groupByRaw($periodExpr)
            ->orderBy('period_start')->orderBy('e.last_name')->orderBy('e.first_name');

        return ['query' => $q->toBase(), 'map' => function (object $r) use ($unit) {
            $start = CarbonImmutable::parse((string) $r->period_start);

            return [
                ...$this->employeeRow($r),
                'period' => $unit === 'week'
                    ? 'Semana '.$start->format('d/m/Y').' – '.$start->addDays(6)->format('d/m/Y')
                    : ucfirst($start->locale('es')->isoFormat('MMMM YYYY')),
                ...$this->aggregateRow($r),
            ];
        }];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function punctuality(ReportParameters $p, User $user): array
    {
        $q = $this->summaries($p, $user);
        $this->joinEmployee($q, 'workday_summaries.employee_id', summaryBranch: true);
        $q->select($this->employeeSelect())
            ->selectRaw($this->aggregateSql())
            ->groupBy('workday_summaries.employee_id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.second_last_name', 'branch')
            ->orderBy('e.last_name')->orderBy('e.first_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [...$this->employeeRow($r), ...$this->aggregateRow($r)]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function worked(ReportParameters $p, User $user): array
    {
        $q = $this->summaries($p, $user);
        $this->joinEmployee($q, 'workday_summaries.employee_id', summaryBranch: true);
        $q->select($this->employeeSelect())
            ->selectRaw($this->aggregateSql())
            ->selectRaw('COALESCE(SUM(workday_summaries.night_minutes),0) as night_minutes')
            ->groupBy('workday_summaries.employee_id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.second_last_name', 'branch')
            ->orderBy('e.last_name')->orderBy('e.first_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            ...$this->aggregateRow($r),
            'ordinary_minutes' => max(0, (int) $r->worked_minutes - (int) $r->excess_minutes),
            'night_minutes' => (int) $r->night_minutes,
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function grouped(ReportParameters $p, User $user, string $by): array
    {
        $q = $this->summaries($p, $user);
        $table = $by === 'branch' ? 'branches' : 'departments';
        $column = $by === 'branch' ? 'workday_summaries.branch_id' : 'workday_summaries.department_id';
        $q->leftJoin("{$table} as g", 'g.id', '=', $column)
            ->select(DB::raw("COALESCE(g.name, 'Sin asignar') as group_name"))
            ->selectRaw('COUNT(DISTINCT workday_summaries.employee_id) as employees')
            ->selectRaw($this->aggregateSql())
            ->groupBy($column, 'g.name')
            ->orderBy('group_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            'group_name' => $r->group_name,
            'employees' => (int) $r->employees,
            ...$this->aggregateRow($r),
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function byEmployee(ReportParameters $p, User $user): array
    {
        $q = $this->summaries($p, $user);
        $this->joinEmployee($q, 'workday_summaries.employee_id', summaryBranch: true);
        $q->select($this->employeeSelect())
            ->selectRaw('MAX(sd.name) as department')
            ->leftJoin('departments as sd', 'sd.id', '=', 'workday_summaries.department_id')
            ->selectRaw($this->aggregateSql())
            ->groupBy('workday_summaries.employee_id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.second_last_name', 'branch')
            ->orderBy('e.last_name')->orderBy('e.first_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            'department' => $r->department,
            ...$this->aggregateRow($r),
        ]];
    }

    // ── Tiempo extraordinario y ausencias ─────────────────────────

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function overtime(ReportParameters $p, User $user): array
    {
        $q = OvertimeRecord::query()->whereBetween('overtime_records.date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('overtime_records.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'overtime_records.employee_id');
        $this->employeeFilters($q, $p, 'overtime_records.employee_id');
        $q->leftJoin('workday_summaries as ws', fn ($j) => $j->on('ws.employee_id', '=', 'overtime_records.employee_id')->on('ws.date', '=', 'overtime_records.date'))
            ->select(['overtime_records.*', 'ws.after_cutoff_minutes', ...$this->employeeSelect()])->orderBy('overtime_records.date')->orderBy('e.last_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            'date' => substr((string) $r->date, 0, 10),
            'week_start' => substr((string) $r->week_start, 0, 10),
            'recorded_excess_minutes' => (int) $r->recorded_excess_minutes,
            'double_minutes' => (int) $r->double_minutes,
            'triple_minutes' => (int) $r->triple_minutes,
            'over_limit_minutes' => (int) $r->over_limit_minutes,
            'authorized_minutes' => (int) $r->authorized_minutes,
            'status' => $r->status,
            'status_label' => ApprovalStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
            'justification' => $r->justification,
            'after_cutoff_minutes' => (int) ($r->after_cutoff_minutes ?? 0),
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function vacations(ReportParameters $p, User $user): array
    {
        $q = VacationRequest::query()
            ->whereDate('vacation_requests.starts_on', '<=', $p->to)->whereDate('vacation_requests.ends_on', '>=', $p->from)
            ->when($p->status, fn ($w) => $w->where('vacation_requests.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'vacation_requests.employee_id');
        $this->employeeFilters($q, $p, 'vacation_requests.employee_id');
        $q->leftJoin('users as rv', 'rv.id', '=', 'vacation_requests.reviewed_by')
            ->select(['vacation_requests.starts_on', 'vacation_requests.ends_on', 'vacation_requests.days', 'vacation_requests.service_year', 'vacation_requests.status', 'rv.name as reviewer', ...$this->employeeSelect()])
            ->orderBy('vacation_requests.starts_on')->orderBy('e.last_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            'starts_on' => substr((string) $r->starts_on, 0, 10),
            'ends_on' => substr((string) $r->ends_on, 0, 10),
            'days' => (int) $r->days,
            'service_year' => (int) $r->service_year,
            'status' => $r->status,
            'status_label' => ApprovalStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
            'reviewed_by' => $r->reviewer,
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function incidences(ReportParameters $p, User $user): array
    {
        $q = Incidence::query()
            ->whereDate('incidences.starts_on', '<=', $p->to)->whereDate('incidences.ends_on', '>=', $p->from)
            ->when($p->status, fn ($w) => $w->where('incidences.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'incidences.employee_id');
        $this->employeeFilters($q, $p, 'incidences.employee_id');
        $q->leftJoin('incidence_types as it', 'it.id', '=', 'incidences.incidence_type_id')
            ->select(['incidences.starts_on', 'incidences.ends_on', 'incidences.source', 'incidences.status', 'incidences.reason', 'it.name as type_name', ...$this->employeeSelect()])
            ->orderBy('incidences.starts_on')->orderBy('e.last_name');

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            'type_name' => $r->type_name,
            'starts_on' => substr((string) $r->starts_on, 0, 10),
            'ends_on' => substr((string) $r->ends_on, 0, 10),
            'source_label' => match ($r->source) {
                'manager' => 'Responsable',
                'system' => 'Sistema',
                default => 'Colaborador',
            },
            'status' => $r->status,
            'status_label' => ApprovalStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
            'reason' => $r->reason,
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function compliance(ReportParameters $p, User $user): array
    {
        $q = ComplianceAlert::query()->whereBetween('compliance_alerts.date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('compliance_alerts.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'compliance_alerts.employee_id', left: true);
        $this->employeeFilters($q, $p, 'compliance_alerts.employee_id');
        $q->select(['compliance_alerts.date', 'compliance_alerts.severity', 'compliance_alerts.message', 'compliance_alerts.legal_basis', 'compliance_alerts.status', 'compliance_alerts.resolution_note', ...$this->employeeSelect()])
            ->orderBy('compliance_alerts.date')->orderByRaw("FIELD(compliance_alerts.severity, 'critical', 'warning', 'info')");

        $statuses = ['open' => 'Abierta', 'acknowledged' => 'Atendida', 'resolved' => 'Resuelta'];

        return ['query' => $q->toBase(), 'map' => fn (object $r) => [
            ...$this->employeeRow($r),
            'date' => substr((string) $r->date, 0, 10),
            'severity' => $r->severity,
            'severity_label' => AlertSeverity::tryFrom((string) $r->severity)?->label() ?? $r->severity,
            'message' => $r->message,
            'legal_basis' => $r->legal_basis,
            'status' => $r->status,
            'status_label' => $statuses[$r->status] ?? $r->status,
            'resolution_note' => $r->resolution_note,
        ]];
    }

    // ── Registro original ─────────────────────────────────────────

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function punches(ReportParameters $p, User $user): array
    {
        $q = Punch::query()->whereBetween('attendance_punches.local_date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('attendance_punches.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'attendance_punches.employee_id');
        $this->employeeFilters($q, $p, 'attendance_punches.employee_id');
        $q->select([
            'attendance_punches.sequence', 'attendance_punches.server_time', 'attendance_punches.type', 'attendance_punches.source',
            'attendance_punches.auth_method', 'attendance_punches.status', 'attendance_punches.captured_offline', 'attendance_punches.latitude',
            'attendance_punches.longitude', 'attendance_punches.accuracy_meters', 'attendance_punches.anomalies', 'attendance_punches.hash',
            'attendance_punches.device_time', 'ws.after_cutoff_minutes',
            ...$this->employeeSelect(),
        ])->leftJoin('workday_summaries as ws', fn ($j) => $j->on('ws.employee_id', '=', 'attendance_punches.employee_id')->on('ws.date', '=', 'attendance_punches.local_date'))
            ->orderBy('attendance_punches.sequence');

        return ['query' => $q->toBase(), 'map' => function (object $r) {
            $anomalies = json_decode((string) $r->anomalies, true) ?: [];

            return [
                ...$this->employeeRow($r),
                'sequence' => (int) $r->sequence,
                'server_time' => self::iso($r->server_time),
                'type_label' => PunchType::tryFrom((string) $r->type)?->label() ?? $r->type,
                // Hora reconocida según el dominio (salida automática = hora límite).
                'recognized_time' => self::iso(EffectiveTimeline::punchTime((new Punch)->newFromBuilder([
                    'source' => $r->source, 'server_time' => $r->server_time, 'device_time' => $r->device_time, 'captured_offline' => $r->captured_offline,
                ]))),
                'origin_label' => $r->source === PunchSource::System->value ? 'Sistema (cierre automático)' : 'Colaborador',
                'source_label' => PunchSource::tryFrom((string) $r->source)?->label() ?? $r->source,
                'auth_label' => AuthMethod::tryFrom((string) $r->auth_method)?->label() ?? $r->auth_method,
                'status_label' => PunchStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
                'offline' => $r->captured_offline ? 'Sí' : 'No',
                'location' => $r->latitude !== null && $r->longitude !== null
                    ? sprintf('%s, %s%s', $r->latitude, $r->longitude, $r->accuracy_meters !== null ? " (±{$r->accuracy_meters} m)" : '')
                    : 'Sin ubicación',
                'anomalies' => implode('; ', array_map(fn ($a) => Anomaly::labelFor((string) $a), $anomalies)),
                'after_cutoff_minutes' => $r->source === PunchSource::System->value ? (int) ($r->after_cutoff_minutes ?? 0) : 0,
                'hash' => $r->hash,
            ];
        }];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(object): array<string, mixed>}
     */
    private function rectifications(ReportParameters $p, User $user): array
    {
        $q = RectificationRequest::query()->whereBetween('rectification_requests.date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('rectification_requests.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'rectification_requests.employee_id');
        $this->employeeFilters($q, $p, 'rectification_requests.employee_id');
        $q->leftJoin('users as rv', 'rv.id', '=', 'rectification_requests.reviewed_by')
            ->select(['rectification_requests.date', 'rectification_requests.kind', 'rectification_requests.proposed_type', 'rectification_requests.proposed_time',
                'rectification_requests.reason', 'rectification_requests.status', 'rectification_requests.review_comment', 'rv.name as reviewer', ...$this->employeeSelect()])
            ->orderBy('rectification_requests.date')->orderBy('e.last_name');

        return ['query' => $q->toBase(), 'map' => function (object $r) use ($p) {
            $proposed = array_filter([
                PunchType::tryFrom((string) $r->proposed_type)?->label(),
                $r->proposed_time ? CarbonImmutable::parse((string) $r->proposed_time, 'UTC')->setTimezone($p->timezone)->format('H:i') : null,
            ]);

            return [
                ...$this->employeeRow($r),
                'date' => substr((string) $r->date, 0, 10),
                'kind_label' => RectificationKind::tryFrom((string) $r->kind)?->label() ?? $r->kind,
                'proposed' => $proposed ? implode(' · ', $proposed) : '—',
                'reason' => $r->reason,
                'status' => $r->status,
                'status_label' => ApprovalStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
                'reviewed_by' => $r->reviewer,
                'review_comment' => $r->review_comment,
            ];
        }];
    }

    // ── Utilidades ────────────────────────────────────────────────

    /**
     * @return Builder<WorkdaySummary>
     */
    private function summaries(ReportParameters $p, User $user): Builder
    {
        $q = WorkdaySummary::query()->whereBetween('workday_summaries.date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->employeeId, fn ($w) => $w->where('workday_summaries.employee_id', $p->employeeId))
            ->when($p->branchId, fn ($w) => $w->where('workday_summaries.branch_id', $p->branchId))
            ->when($p->departmentId, fn ($w) => $w->where('workday_summaries.department_id', $p->departmentId))
            ->when($p->status, fn ($w) => $w->where('workday_summaries.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);

        return $q;
    }

    /**
     * Une el colaborador y su asignación vigente (sucursal/departamento actuales).
     * En reportes de jornadas, la sucursal es la registrada en la proyección del día.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     */
    private function joinEmployee(Builder $q, string $employeeColumn, bool $summaryBranch = false, bool $left = false): void
    {
        $today = now()->toDateString();
        $current = DB::table('employment_assignments as ea')
            ->join('employments as em', 'em.id', '=', 'ea.employment_id')
            ->whereDate('ea.effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
            ->selectRaw('em.employee_id, MAX(ea.id) as assignment_id')
            ->groupBy('em.employee_id');

        $left
            ? $q->leftJoin('employees as e', 'e.id', '=', $employeeColumn)
            : $q->join('employees as e', 'e.id', '=', $employeeColumn);

        $q->leftJoinSub($current, 'ca', 'ca.employee_id', '=', 'e.id')
            ->leftJoin('employment_assignments as cas', 'cas.id', '=', 'ca.assignment_id');

        if ($summaryBranch) {
            $q->leftJoin('branches as cb', 'cb.id', '=', 'workday_summaries.branch_id');
        } else {
            $q->leftJoin('branches as cb', 'cb.id', '=', 'cas.branch_id');
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     */
    private function employeeFilters(Builder $q, ReportParameters $p, string $employeeColumn): void
    {
        $q->when($p->employeeId, fn ($w) => $w->where($employeeColumn, $p->employeeId))
            ->when($p->branchId, fn ($w) => $w->where('cas.branch_id', $p->branchId))
            ->when($p->departmentId, fn ($w) => $w->where('cas.department_id', $p->departmentId));
    }

    /**
     * @return list<Expression|string>
     */
    private function employeeSelect(): array
    {
        return [
            'e.employee_number',
            'e.first_name',
            'e.last_name',
            'e.second_last_name',
            DB::raw('cb.name as branch'),
        ];
    }

    /**
     * @return array{employee_number:?string, employee_name:string, branch:?string}
     */
    private function employeeRow(object $r): array
    {
        return [
            'employee_number' => $r->employee_number ?? null,
            'employee_name' => trim(implode(' ', array_filter([$r->first_name ?? null, $r->last_name ?? null, $r->second_last_name ?? null]))) ?: '—',
            'branch' => $r->branch ?? null,
        ];
    }

    private function aggregateSql(): string
    {
        return implode(', ', [
            'SUM(CASE WHEN workday_summaries.status IN ('.self::SCHEDULED.') THEN 1 ELSE 0 END) as scheduled_days',
            'SUM(CASE WHEN workday_summaries.status IN ('.self::ATTENDED.') THEN 1 ELSE 0 END) as attended_days',
            'SUM(CASE WHEN workday_summaries.status IN ('.self::ATTENDED.') AND workday_summaries.late_minutes = 0 THEN 1 ELSE 0 END) as punctual_days',
            "SUM(CASE WHEN workday_summaries.status = 'absent' THEN 1 ELSE 0 END) as absent_days",
            'SUM(CASE WHEN workday_summaries.late_minutes > 0 THEN 1 ELSE 0 END) as late_days',
            'COALESCE(SUM(workday_summaries.late_minutes),0) as late_minutes',
            'COALESCE(SUM(workday_summaries.worked_minutes),0) as worked_minutes',
            'COALESCE(SUM(workday_summaries.excess_minutes),0) as excess_minutes',
            'COALESCE(SUM(workday_summaries.after_cutoff_minutes),0) as after_cutoff_minutes',
        ]);
    }

    /**
     * @return array<string, int|float|null>
     */
    private function aggregateRow(object $r): array
    {
        $attended = (int) $r->attended_days;

        return [
            'scheduled_days' => (int) $r->scheduled_days,
            'attended_days' => $attended,
            'punctual_days' => (int) $r->punctual_days,
            'absent_days' => (int) $r->absent_days,
            'late_days' => (int) $r->late_days,
            'late_minutes' => (int) $r->late_minutes,
            'worked_minutes' => (int) $r->worked_minutes,
            'excess_minutes' => (int) $r->excess_minutes,
            'after_cutoff_minutes' => (int) $r->after_cutoff_minutes,
            'punctuality_rate' => $attended > 0 ? round(((int) $r->punctual_days) * 100 / $attended, 1) : null,
        ];
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
