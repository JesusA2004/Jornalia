<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\Anomaly;
use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\PunchOrigin;
use App\Domain\Reports\Support\ReportFormat;
use App\Domain\Security\AccessScope;
use App\Enums\AlertSeverity;
use App\Enums\ApprovalStatus;
use App\Enums\AuthMethod;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Enums\RectificationStage;
use App\Models\ComplianceAlert;
use App\Models\Incidence;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Reportes basados en registros (una fila por registro de la base): vacaciones, incidencias, alertas, marcajes originales y
 * rectificaciones con firma. Cada uno aplica el alcance del usuario
 * (AccessScope + organización activa) y devuelve una consulta paginable en
 * streaming (`lazy`) y un mapeador a filas: fechas civiles `Y-m-d`, instantes
 * ISO-8601 UTC y minutos enteros.
 */
final class ReportQuery
{
    public function __construct(private readonly AccessScope $scope) {}

    /**
     * @return array{query: QueryBuilder, map: Closure(stdClass): array<string, mixed>}
     */
    public function build(string $kind, ReportParameters $p, User $user): array
    {
        return match ($kind) {
            'vacations' => $this->vacations($p, $user),
            'incidences' => $this->incidences($p, $user),
            'compliance' => $this->compliance($p, $user),
            'punches' => $this->punches($p, $user),
            'rectifications' => $this->rectifications($p, $user),
            default => throw new InvalidArgumentException("Consulta de reporte desconocida: {$kind}"),
        };
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(stdClass): array<string, mixed>}
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
            ->select(['vacation_requests.starts_on', 'vacation_requests.ends_on', 'vacation_requests.days', 'vacation_requests.service_year', 'vacation_requests.status', 'vacation_requests.employee_id', 'rv.name as reviewer', ...$this->employeeSelect()])
            ->orderBy('e.last_name')->orderBy('e.first_name')->orderBy('vacation_requests.employee_id')->orderBy('vacation_requests.starts_on');

        return ['query' => $q->toBase(), 'map' => fn (stdClass $r) => [
            ...$this->employeeRow($r),
            'starts_on' => substr((string) $r->starts_on, 0, 10),
            'ends_on' => substr((string) $r->ends_on, 0, 10),
            'days' => (int) $r->days,
            'service_year' => (int) $r->service_year,
            'status' => $r->status,
            'status_label' => ApprovalStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
            'reviewed_by' => $r->reviewer,
            ...$this->employeeGroup($r),
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(stdClass): array<string, mixed>}
     */
    private function incidences(ReportParameters $p, User $user): array
    {
        $morph = (new Incidence)->getMorphClass();
        $q = Incidence::query()
            ->whereDate('incidences.starts_on', '<=', $p->to)->whereDate('incidences.ends_on', '>=', $p->from)
            ->when($p->status, fn ($w) => $w->where('incidences.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'incidences.employee_id');
        $this->employeeFilters($q, $p, 'incidences.employee_id');
        $q->leftJoin('incidence_types as it', 'it.id', '=', 'incidences.incidence_type_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'incidences.reviewed_by')
            ->select(['incidences.id', 'incidences.starts_on', 'incidences.ends_on', 'incidences.minutes', 'incidences.source', 'incidences.status', 'incidences.reason',
                'incidences.employee_id', 'it.name as type_name', 'it.code as type_code', 'rv.name as reviewer', ...$this->employeeSelect()])
            ->selectSub(fn ($s) => $s->from('attachments')->selectRaw('COUNT(*)')->where('attachable_type', $morph)->whereColumn('attachable_id', 'incidences.id'), 'evidence_count')
            ->orderBy('e.last_name')->orderBy('e.first_name')->orderBy('incidences.employee_id')->orderBy('incidences.starts_on');

        return ['query' => $q->toBase(), 'map' => fn (stdClass $r) => [
            ...$this->employeeRow($r),
            'folio' => 'INC-'.$r->id,
            'type_name' => $r->type_name,
            'starts_on' => substr((string) $r->starts_on, 0, 10),
            'ends_on' => substr((string) $r->ends_on, 0, 10),
            'days' => (int) CarbonImmutable::parse((string) $r->starts_on)->diffInDays(CarbonImmutable::parse((string) $r->ends_on)) + 1,
            'minutes' => $r->minutes !== null ? (int) $r->minutes : null,
            'source_label' => match ($r->source) {
                'manager' => 'Responsable',
                'system' => 'Sistema',
                default => 'Colaborador',
            },
            'status' => $r->status,
            'status_label' => ApprovalStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
            'reviewed_by' => $r->reviewer,
            'evidence' => (int) $r->evidence_count > 0 ? $r->evidence_count.' archivo(s)' : 'Sin evidencia',
            'reason' => $r->reason,
            ...$this->employeeGroup($r),
        ]];
    }

    /**
     * @return array{query: QueryBuilder, map: Closure(stdClass): array<string, mixed>}
     */
    private function compliance(ReportParameters $p, User $user): array
    {
        $q = ComplianceAlert::query()->whereBetween('compliance_alerts.date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('compliance_alerts.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'compliance_alerts.employee_id', left: true);
        $this->employeeFilters($q, $p, 'compliance_alerts.employee_id');
        $q->select(['compliance_alerts.date', 'compliance_alerts.rule_code', 'compliance_alerts.severity', 'compliance_alerts.message', 'compliance_alerts.legal_basis',
            'compliance_alerts.status', 'compliance_alerts.resolution_note', 'compliance_alerts.employee_id', ...$this->employeeSelect()])
            ->orderBy('compliance_alerts.date')->orderByRaw("FIELD(compliance_alerts.severity, 'critical', 'warning', 'info')")->orderBy('e.last_name');

        $statuses = ['open' => 'Abierta', 'acknowledged' => 'Atendida', 'resolved' => 'Resuelta'];

        return ['query' => $q->toBase(), 'map' => fn (stdClass $r) => [
            ...$this->employeeRow($r),
            'date' => substr((string) $r->date, 0, 10),
            'rule_code' => $r->rule_code,
            'severity' => $r->severity,
            'severity_label' => AlertSeverity::tryFrom((string) $r->severity)?->label() ?? $r->severity,
            'message' => $r->message,
            'legal_basis' => $r->legal_basis,
            'status' => $r->status,
            'status_label' => $statuses[$r->status] ?? $r->status,
            'resolution_note' => $r->resolution_note,
            '_date_key' => substr((string) $r->date, 0, 10),
            '_date_label' => CarbonImmutable::parse((string) $r->date)->format('d/m/Y'),
        ]];
    }

    /**
     * Marcajes originales inmutables (historial e inspección laboral).
     *
     * @return array{query: QueryBuilder, map: Closure(stdClass): array<string, mixed>}
     */
    private function punches(ReportParameters $p, User $user): array
    {
        $q = Punch::query()->whereBetween('attendance_punches.local_date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('attendance_punches.status', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'attendance_punches.employee_id');
        $this->employeeFilters($q, $p, 'attendance_punches.employee_id');
        $q->leftJoin('devices as dv', 'dv.id', '=', 'attendance_punches.device_id')
            ->select([
                'attendance_punches.sequence', 'attendance_punches.server_time', 'attendance_punches.type', 'attendance_punches.source',
                'attendance_punches.auth_method', 'attendance_punches.status', 'attendance_punches.captured_offline', 'attendance_punches.latitude',
                'attendance_punches.longitude', 'attendance_punches.accuracy_meters', 'attendance_punches.anomalies', 'attendance_punches.hash',
                'attendance_punches.previous_hash', 'attendance_punches.device_time', 'attendance_punches.geofence_id', 'attendance_punches.local_date',
                'attendance_punches.employee_id', 'dv.name as device_name',
                ...$this->employeeSelect(),
            ])->orderBy('attendance_punches.sequence');

        return ['query' => $q->toBase(), 'map' => function (stdClass $r) {
            $anomalies = json_decode((string) $r->anomalies, true) ?: [];
            $punch = (new Punch)->newFromBuilder([
                'source' => $r->source, 'server_time' => $r->server_time, 'device_time' => $r->device_time, 'captured_offline' => $r->captured_offline,
                'anomalies' => $r->anomalies, 'geofence_id' => $r->geofence_id,
            ]);

            return [
                ...$this->employeeRow($r),
                'sequence' => (int) $r->sequence,
                'local_date' => substr((string) $r->local_date, 0, 10),
                'server_time' => ReportFormat::iso($r->server_time),
                'device_time' => ReportFormat::iso($r->device_time),
                'type_label' => PunchType::tryFrom((string) $r->type)?->label() ?? $r->type,
                'recognized_time' => ReportFormat::iso(EffectiveTimeline::punchTime($punch)),
                'origin_label' => PunchOrigin::LABELS[PunchOrigin::forPunch($punch)],
                'auth_label' => AuthMethod::tryFrom((string) $r->auth_method)?->label() ?? $r->auth_method,
                'device' => $r->device_name,
                'status_label' => PunchStatus::tryFrom((string) $r->status)?->label() ?? $r->status,
                'location' => $r->latitude !== null && $r->longitude !== null
                    ? sprintf('%s, %s%s', $r->latitude, $r->longitude, $r->accuracy_meters !== null ? " (±{$r->accuracy_meters} m)" : '')
                    : 'Sin ubicación',
                'anomalies' => implode('; ', array_map(fn ($a) => Anomaly::labelFor((string) $a), $anomalies)),
                'previous_hash' => $r->previous_hash,
                'hash' => $r->hash,
            ];
        }];
    }

    /**
     * Rectificaciones con evidencia y firma del colaborador (fecha, método,
     * dispositivo y sello de la firma electrónica encadenada).
     *
     * @return array{query: QueryBuilder, map: Closure(stdClass): array<string, mixed>}
     */
    private function rectifications(ReportParameters $p, User $user): array
    {
        $morph = (new RectificationRequest)->getMorphClass();
        $q = RectificationRequest::query()->whereBetween('rectification_requests.date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when($p->status, fn ($w) => $w->where('rectification_requests.stage', $p->status));
        $this->scope->scopeByEmployee($q, $user);
        $this->joinEmployee($q, 'rectification_requests.employee_id');
        $this->employeeFilters($q, $p, 'rectification_requests.employee_id');
        $q->leftJoin('users as au', 'au.id', '=', 'rectification_requests.authorized_by')
            ->leftJoin('users as vu', 'vu.id', '=', 'rectification_requests.validated_by')
            ->leftJoin('electronic_signatures as es', 'es.id', '=', 'rectification_requests.electronic_signature_id')
            ->leftJoin('devices as sd', 'sd.id', '=', 'es.device_id')
            ->leftJoin('attendance_punches as op', 'op.uuid', '=', 'rectification_requests.punch_uuid')
            ->select([
                'rectification_requests.id', 'rectification_requests.date', 'rectification_requests.kind', 'rectification_requests.proposed_type',
                'rectification_requests.proposed_time', 'rectification_requests.reason', 'rectification_requests.status', 'rectification_requests.stage',
                'rectification_requests.created_at', 'rectification_requests.authorized_at', 'rectification_requests.validated_at',
                'rectification_requests.rejection_reason', 'rectification_requests.legacy_without_signature', 'rectification_requests.is_late',
                'rectification_requests.employee_id', 'au.name as authorizer', 'vu.name as validator',
                'es.signed_at as signature_at', 'es.method as signature_method', 'es.hash as signature_hash', 'es.ip as signature_ip',
                'sd.name as signature_device', 'sd.platform as signature_platform',
                'op.type as original_type', 'op.server_time as original_time',
                ...$this->employeeSelect(),
            ])
            ->selectSub(fn ($s) => $s->from('attachments')->selectRaw('COUNT(*)')->where('attachable_type', $morph)->whereColumn('attachable_id', 'rectification_requests.id'), 'evidence_count')
            ->selectSub(fn ($s) => $s->from('attachments')->selectRaw("GROUP_CONCAT(CONCAT(original_name, ' (', LEFT(sha256, 12), ')') ORDER BY id SEPARATOR '; ')")->where('attachable_type', $morph)->whereColumn('attachable_id', 'rectification_requests.id'), 'evidence_files')
            ->orderBy('e.last_name')->orderBy('e.first_name')->orderBy('rectification_requests.employee_id')->orderBy('rectification_requests.date')->orderBy('rectification_requests.id');

        return ['query' => $q->toBase(), 'map' => function (stdClass $r) use ($p) {
            $local = fn ($v) => $v ? CarbonImmutable::parse((string) $v, 'UTC')->setTimezone($p->timezone)->format('H:i') : null;
            $proposed = array_filter([PunchType::tryFrom((string) $r->proposed_type)?->label(), $local($r->proposed_time)]);
            $original = array_filter([PunchType::tryFrom((string) $r->original_type)?->label(), $local($r->original_time)]);
            $stage = RectificationStage::tryFrom((string) $r->stage);
            $method = match ($r->signature_method) {
                'pin' => 'NIP de firma',
                'biometric' => 'Biometría del dispositivo',
                null => null,
                default => (string) $r->signature_method,
            };

            return [
                ...$this->employeeRow($r),
                'folio' => 'RECT-'.$r->id,
                'date' => substr((string) $r->date, 0, 10),
                'requested_at' => ReportFormat::iso($r->created_at),
                'kind_label' => RectificationKind::tryFrom((string) $r->kind)?->label() ?? $r->kind,
                'original' => $original ? implode(' ', $original) : 'Sin marcaje',
                'proposed' => $proposed ? implode(' ', $proposed) : '—',
                'reason' => $r->reason,
                'evidence' => (int) $r->evidence_count > 0 ? (string) $r->evidence_files : 'Sin evidencia',
                'evidence_count' => (int) $r->evidence_count,
                'authorized_by' => $r->authorizer,
                'stage' => $r->stage,
                'stage_label' => ($stage?->label() ?? (ApprovalStatus::tryFrom((string) $r->status)?->label() ?? (string) $r->status)).($r->is_late ? ' (extemporánea)' : ''),
                'signed_at' => ReportFormat::iso($r->signature_at),
                'signature_method' => $method ?? ($r->legacy_without_signature ? 'Previa al flujo de firma' : 'Sin firma'),
                'signature_device' => $r->signature_device ? trim($r->signature_device.' '.($r->signature_platform ? '('.$r->signature_platform.')' : '')) : ($r->signature_ip ? 'IP '.$r->signature_ip : null),
                'signature_seal' => $r->signature_hash,
                'validated_by' => $r->validator,
                'rejection_reason' => $r->rejection_reason,
                ...$this->employeeGroup($r),
            ];
        }];
    }

    // ── Utilidades ────────────────────────────────────────────────

    /**
     * Une el colaborador y su asignación vigente (sucursal, departamento y puesto actuales).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     */
    private function joinEmployee(Builder $q, string $employeeColumn, bool $left = false): void
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
            ->leftJoin('employment_assignments as cas', 'cas.id', '=', 'ca.assignment_id')
            ->leftJoin('branches as cb', 'cb.id', '=', 'cas.branch_id')
            ->leftJoin('departments as cd', 'cd.id', '=', 'cas.department_id')
            ->leftJoin('positions as cp', 'cp.id', '=', 'cas.position_id');
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
            ->when($p->departmentId, fn ($w) => $w->where('cas.department_id', $p->departmentId))
            ->when($p->positionId, fn ($w) => $w->where('cas.position_id', $p->positionId))
            ->when($p->region, fn ($w) => $w->where('cb.region', $p->region));
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
            DB::raw('cd.name as department'),
            DB::raw('cp.name as position'),
        ];
    }

    /**
     * @return array{employee_number:?string, employee_name:string, branch:?string, department:?string, position:?string}
     */
    private function employeeRow(stdClass $r): array
    {
        return [
            'employee_number' => $r->employee_number ?? null,
            'employee_name' => trim(implode(' ', array_filter([$r->first_name ?? null, $r->last_name ?? null, $r->second_last_name ?? null]))) ?: '—',
            'branch' => $r->branch ?? null,
            'department' => $r->department ?? null,
            'position' => $r->position ?? null,
        ];
    }

    /**
     * Llaves de agrupación por colaborador.
     *
     * @return array{_employee_key:int, _employee_label:string}
     */
    private function employeeGroup(stdClass $r): array
    {
        $name = trim(implode(' ', array_filter([$r->first_name ?? null, $r->last_name ?? null, $r->second_last_name ?? null]))) ?: '—';

        return [
            '_employee_key' => (int) ($r->employee_id ?? 0),
            '_employee_label' => $name.(! empty($r->employee_number) ? ' · Núm. '.$r->employee_number : ''),
        ];
    }
}
