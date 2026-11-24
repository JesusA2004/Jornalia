<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\WeeklyOvertime;
use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Domain\Legal\WeeklyOvertimeDay;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Controllers\Web\Operacion\Support\OvertimeAuthorizer;
use App\Http\Requests\Web\Operacion\AuthorizeOvertimeRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Horas extraordinarias por colaborador y semana: ordinario, tiempo extra
 * registrado, pendiente, autorizado, justificado, dobles, triples y sobre límite
 * legal. Todo tiempo posterior al horario queda pendiente; nada se descarta.
 */
final class OvertimeController extends Controller
{
    public function __construct(private readonly OperacionScope $scope) {}

    public function index(ListFiltersRequest $request, ApprovalWorkflow $workflow, LegalFramework $law, WeeklyOvertimeCalculator $calculator): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $week = $this->week($request->query('week'));
        $weekEnd = $week->addDays(6);
        $onlyOvertime = $request->query('all') !== '1';

        $summaries = WorkdaySummary::query()->whereBetween('date', [$week->toDateString(), $weekEnd->toDateString()]);
        $this->scope->apply($summaries, $user, $filters, useSummaryColumns: true);
        $records = OvertimeRecord::query()->whereBetween('date', [$week->toDateString(), $weekEnd->toDateString()])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
        $this->scope->apply($records, $user, $filters);

        // Todo tiempo registrado después del horario aparece aquí (nada se descarta).
        $employeeIds = isset($filters['status']) || $onlyOvertime
            ? (clone $records)->distinct()->pluck('employee_id')
            : (clone $summaries)->distinct()->pluck('employee_id')->merge((clone $records)->distinct()->pluck('employee_id'))->unique();

        $cards = $this->scope->employeeCards($employeeIds);
        $sorted = $cards->sortBy('name')->keys()->values();
        $perPage = $request->perPage(15);
        $pageNumber = max(1, (int) $request->validated('page', 1));
        $pageIds = $sorted->forPage($pageNumber, $perPage)->values();

        $employees = Employee::query()->whereIn('id', $pageIds->all() ?: [0])->get();
        $weekly = $calculator->forEmployees($employees, $week);
        $sumRows = (clone $summaries)->whereIn('employee_id', $pageIds)->get()->groupBy('employee_id');
        $recRows = (clone $records)->whereIn('employee_id', $pageIds)->with('authorizedBy:id,name')->get()->groupBy('employee_id');

        $rows = $pageIds->map(function ($id) use ($cards, $sumRows, $recRows, $weekly, $user, $workflow) {
            $result = $weekly[$id] ?? null;
            $byDateSum = ($sumRows[$id] ?? collect())->keyBy(fn (WorkdaySummary $s): string => $s->date->toDateString());
            $byDateRec = ($recRows[$id] ?? collect())->keyBy(fn (OvertimeRecord $r): string => $r->date->toDateString());

            $days = array_map(function (WeeklyOvertimeDay $d) use ($byDateSum, $byDateRec, $user, $workflow) {
                $s = $byDateSum[$d->date] ?? null;
                $r = $byDateRec[$d->date] ?? null;

                return $d->toArray() + [
                    'status_label' => $s?->status->label(),
                    'record' => $r ? $this->record($r, $user, $workflow, $d->minor) : null,
                ];
            }, $result->days ?? []);

            return [
                'employee' => $cards[$id],
                'minor' => $result?->minor,
                'days' => $days,
                'totals' => ($result?->totals() ?? []) + [
                    'pending' => ($recRows[$id] ?? collect())->where('status', ApprovalStatus::Pending)->where('recorded_excess_minutes', '>', 0)->count(),
                ],
            ];
        })->values();

        $paginator = new LengthAwarePaginator($rows, $sorted->count(), $perPage, $pageNumber, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        // Totales del alcance: suma de cálculos por colaborador y por semana.
        $allWeekly = $calculator->forEmployees(Employee::query()->whereIn('id', $sorted->all() ?: [0])->get(), $week);
        $sum = fn (string $key): int => (int) array_sum(array_map(fn (WeeklyOvertime $w) => $w->totals()[$key], $allWeekly));
        $p = $law->for($week);
        $legal = $law->verificationFor($week);

        return Inertia::render('horas-extra/Index', [
            'week' => [
                'start' => $week->toDateString(),
                'end' => $weekEnd->toDateString(),
                'previous' => $week->subWeek()->toDateString(),
                'next' => $week->addWeek()->toDateString(),
                'days' => array_map(fn (CarbonInterface $d) => $d->toDateString(), CarbonPeriod::create($week, $weekEnd)->toArray()),
            ],
            'rows' => $paginator,
            'totals' => [
                'employees' => count(array_filter($allWeekly, fn (WeeklyOvertime $w) => $w->totals()['recorded_minutes'] > 0)),
                'overtime_days' => $sum('overtime_days'),
                'recorded_minutes' => $sum('recorded_minutes'),
                'authorized_minutes' => $sum('authorized_minutes'),
                'justified_minutes' => $sum('justified_minutes'),
                'pending_minutes' => $sum('pending_minutes'),
                'double_minutes' => $sum('double_minutes'),
                'triple_minutes' => $sum('triple_minutes'),
                'over_limit_minutes' => $sum('over_limit_minutes'),
                'pending' => (clone $records)->where('status', ApprovalStatus::Pending)->where('recorded_excess_minutes', '>', 0)->count(),
                'minors' => count(array_filter($allWeekly, fn (WeeklyOvertime $w) => $w->minor === true && $w->totals()['recorded_minutes'] > 0)),
            ],
            // Topes de la tabla normativa del año (fuente única: LegalFramework / Configuración › Normativa).
            'limits' => [
                'version' => $p->versionCode,
                'name' => $legal['name'],
                'verified' => (bool) $legal['verified'],
                'verification_label' => $legal['label'],
                'verified_at' => $legal['verified_at'],
                'weekly_ordinary_max_minutes' => $p->weeklyOrdinaryMaxMinutes,
                'daily_max_minutes' => $p->overtimeDailyMax,
                'weekly_max_minutes' => $p->overtimeWeeklyMax,
                'max_days_per_week' => $p->overtimeMaxDaysPerWeek,
                'excess_weekly_max_minutes' => $p->overtimeExcessWeeklyMax,
                'daily_total_max_minutes' => $p->dailyTotalMax,
                'double_multiplier' => $p->overtimeDoubleMultiplier,
                'excess_multiplier' => $p->overtimeExcessMultiplier,
                'minors_allowed' => $p->minorsOvertimeAllowed,
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
                'week' => $week->toDateString(),
                'all' => ! $onlyOvertime,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'statuses' => ApprovalStatus::options(),
            ],
            'can' => ['approve' => $user->can('overtime.approve')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function decide(AuthorizeOvertimeRequest $request, OvertimeAuthorizer $authorizer, int $overtime): RedirectResponse
    {
        $user = $request->user();
        $record = OvertimeRecord::query()->with('employee')->findOrFail($overtime);
        abort_unless($this->scope->canAccessEmployee($user, $record->employee_id), 404);

        $data = $request->validated();
        $result = $authorizer->decide($user, $record, $data['decision'] === 'approve', isset($data['authorized_minutes']) ? (int) $data['authorized_minutes'] : null, $data['justification']);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($result->status) {
            ApprovalStatus::Approved => "Se autorizaron {$result->authorized_minutes} min de tiempo extraordinario.",
            ApprovalStatus::Rejected => 'Tiempo justificado; permanece registrado como tiempo trabajado.',
            default => 'Decisión registrada; la autorización pasa al siguiente nivel.',
        }]);

        return back();
    }

    private function week(mixed $value): CarbonImmutable
    {
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::parse($value)
            : $this->scope->today();

        return $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function record(OvertimeRecord $r, User $user, ApprovalWorkflow $workflow, bool $minor = false): array
    {
        [$authorized, $justified, $pending] = WeeklyOvertimeCalculator::authorization($r, (int) $r->recorded_excess_minutes);

        return [
            'id' => $r->id,
            'date' => $r->date->toDateString(),
            'recorded_excess_minutes' => (int) $r->recorded_excess_minutes,
            'authorized_minutes' => $authorized,
            'justified_minutes' => $justified,
            'pending_minutes' => $pending,
            'double_minutes' => (int) $r->double_minutes,
            'triple_minutes' => (int) $r->triple_minutes,
            'over_limit_minutes' => (int) $r->over_limit_minutes,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'justification' => $r->justification,
            'authorized_by' => $r->authorizedBy?->name,
            'authorized_at' => OperacionScope::iso($r->authorized_at),
            'legal_version_code' => $r->legal_version_code,
            'current_level_label' => $r->status === ApprovalStatus::Pending ? ($workflow->currentLevel($r, 'overtime')['label'] ?? null) : null,
            'can_decide' => $r->status === ApprovalStatus::Pending && $workflow->canDecide($user, $r, 'overtime'),
            // Menores de 18: el tiempo extra no puede autorizarse, sólo justificarse.
            'can_authorize' => ! $minor,
        ];
    }
}
