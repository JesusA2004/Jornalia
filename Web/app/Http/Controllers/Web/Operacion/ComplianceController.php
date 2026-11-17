<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Audit\AuditLogger;
use App\Domain\Legal\Exceptions\LegalVersionNotFound;
use App\Domain\Legal\LegalFramework;
use App\Enums\AlertSeverity;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Http\Requests\Web\Operacion\ResolveAlertRequest;
use App\Models\ComplianceAlert;
use App\Models\LegalVersion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cumplimiento: alertas laborales calculadas por el dominio y resumen de la
 * normativa vigente (versión, parámetros y estado de verificación).
 */
final class ComplianceController extends Controller
{
    public const RULES = [
        'missing_clock_out' => 'Jornada sin registro de salida',
        'daily_total_exceeded' => 'Jornada diaria total excedida',
        'rest_day_worked' => 'Trabajo en día de descanso',
        'holiday_worked' => 'Trabajo en día festivo',
        'sunday_worked' => 'Trabajo en domingo (prima dominical)',
        'minor_night_work' => 'Menor en jornada nocturna',
        'minor_overtime' => 'Menor con tiempo extraordinario',
        'minor_daily_limit' => 'Menor sobre límite diario',
        'overtime_over_limit' => 'Tiempo extra sobre límite legal',
        'weekly_ordinary_exceeded' => 'Jornada semanal ordinaria excedida',
        'no_weekly_rest' => 'Sin descanso semanal',
        'device_in_use' => 'Intento de uso de teléfono ajeno',
    ];

    public const STATUSES = ['open' => 'Abierta', 'acknowledged' => 'Atendida', 'resolved' => 'Resuelta'];

    public function __construct(private readonly OperacionScope $scope) {}

    public function index(ListFiltersRequest $request, LegalFramework $law): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $severity = in_array($request->query('severity'), ['info', 'warning', 'critical'], true) ? (string) $request->query('severity') : null;
        $rule = array_key_exists((string) $request->query('rule'), self::RULES) ? (string) $request->query('rule') : null;
        $status = $filters['status'] ?? 'open';
        $status = in_array($status, ['open', 'acknowledged', 'resolved', 'all'], true) ? $status : 'open';

        $query = ComplianceAlert::query()->with('resolvedBy:id,name')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->when($rule, fn ($q) => $q->where('rule_code', $rule))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']));
        $this->scope->apply($query, $user, $filters);

        $summaryBase = ComplianceAlert::query()->where('status', '!=', 'resolved');
        $this->scope->apply($summaryBase, $user, []);
        $bySeverity = (clone $summaryBase)->toBase()->selectRaw('severity, COUNT(*) as total')->groupBy('severity')->pluck('total', 'severity');
        $byRule = (clone $summaryBase)->toBase()->selectRaw('rule_code, COUNT(*) as total')->groupBy('rule_code')->orderByDesc('total')->get()
            ->map(fn ($r) => ['rule' => $r->rule_code, 'label' => self::RULES[$r->rule_code] ?? $r->rule_code, 'total' => (int) $r->total])->values();

        $page = $query->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")->orderByDesc('date')->orderByDesc('id')
            ->paginate($request->perPage(15))->withQueryString();
        $cards = $this->scope->employeeCards($page->getCollection()->pluck('employee_id'));
        $canResolve = $user->can('compliance.resolve');
        $page->through(fn (ComplianceAlert $a) => [
            'id' => $a->id,
            'employee' => $a->employee_id ? ($cards[$a->employee_id] ?? null) : null,
            'date' => $a->date->toDateString(),
            'rule_code' => $a->rule_code,
            'rule_label' => self::RULES[$a->rule_code] ?? $a->rule_code,
            'severity' => $a->severity->value,
            'severity_label' => $a->severity->label(),
            'message' => $a->message,
            'legal_basis' => $a->legal_basis,
            'data' => $a->data,
            'status' => $a->status,
            'status_label' => self::STATUSES[$a->status] ?? $a->status,
            'resolved_by' => $a->resolvedBy?->name,
            'resolved_at' => OperacionScope::iso($a->resolved_at),
            'resolution_note' => $a->resolution_note,
            'created_at' => OperacionScope::iso($a->created_at),
            'can_resolve' => $canResolve && $a->status !== 'resolved',
        ]);

        return Inertia::render('cumplimiento/Index', [
            'alerts' => $page,
            'summary' => [
                'open' => (int) $bySeverity->sum(),
                'by_severity' => [
                    'critical' => (int) ($bySeverity['critical'] ?? 0),
                    'warning' => (int) ($bySeverity['warning'] ?? 0),
                    'info' => (int) ($bySeverity['info'] ?? 0),
                ],
                'by_rule' => $byRule,
            ],
            'legal' => $this->legal($law),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $status,
                'severity' => $severity,
                'rule' => $rule,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'employees' => $this->scope->employeeOptions($user),
                'severities' => AlertSeverity::options(),
                'rules' => collect(self::RULES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'statuses' => [...collect(self::STATUSES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(), ['value' => 'all', 'label' => 'Todas']],
            ],
            'can' => ['resolve' => $canResolve],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function resolve(ResolveAlertRequest $request, AuditLogger $audit, int $alert): RedirectResponse
    {
        $user = $request->user();
        $model = ComplianceAlert::query()->findOrFail($alert);
        abort_unless($model->employee_id === null ? $this->scope->hasFullAccess($user) : $this->scope->canAccessEmployee($user, $model->employee_id), 404);
        abort_if($model->status === 'resolved', 422, 'La alerta ya está resuelta.');

        $data = $request->validated();
        $model->update([
            'status' => $data['status'],
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_note' => $data['note'],
        ]);
        $audit->log('compliance.'.$data['status'], $model, ($data['status'] === 'resolved' ? 'Resolvió' : 'Atendió').' alerta de cumplimiento: '.$model->rule_code, ['note' => $data['note']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['status'] === 'resolved' ? 'Alerta resuelta.' : 'Alerta marcada como atendida.']);

        return back();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legal(LegalFramework $law): ?array
    {
        $today = $this->scope->today();
        try {
            $p = $law->for($today);
        } catch (LegalVersionNotFound) {
            return null;
        }
        $version = LegalVersion::query()->where('code', $p->versionCode)->first();

        return [
            'code' => $p->versionCode,
            'name' => $version?->name,
            'valid_from' => $version?->valid_from?->toDateString(),
            'valid_to' => $version?->valid_to?->toDateString(),
            'verification_status' => $p->verificationStatus,
            'verified' => $p->isVerified(),
            'notes' => $version?->notes,
            'sources' => array_values((array) ($version?->sources ?? [])),
            'parameters' => [
                'weekly_ordinary_max_minutes' => $p->weeklyOrdinaryMaxMinutes,
                'daily_max_day' => $p->dailyMaxDay,
                'daily_max_night' => $p->dailyMaxNight,
                'daily_max_mixed' => $p->dailyMaxMixed,
                'night_start' => $p->nightStart,
                'night_end' => $p->nightEnd,
                'min_break_minutes' => $p->minBreakMinutes,
                'overtime_daily_max' => $p->overtimeDailyMax,
                'overtime_weekly_max' => $p->overtimeWeeklyMax,
                'overtime_max_days_per_week' => $p->overtimeMaxDaysPerWeek,
                'overtime_double_multiplier' => $p->overtimeDoubleMultiplier,
                'overtime_excess_multiplier' => $p->overtimeExcessMultiplier,
                'rest_days_per_week' => $p->restDaysPerWeek,
                'sunday_premium_min_percent' => $p->sundayPremiumMinPercent,
                'vacation_premium_min_percent' => $p->vacationPremiumMinPercent,
            ],
        ];
    }
}
