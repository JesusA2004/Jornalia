<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Legal\LegalFramework;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Controllers\Web\Operacion\Support\OvertimeAuthorizer;
use App\Http\Requests\Web\Operacion\AuthorizeOvertimeRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Horas extraordinarias por semana: ordinarias, excedente registrado, dobles,
 * triples y sobre límite legal. Los excedentes nunca se ocultan.
 */
final class OvertimeController extends Controller
{
    public function __construct(private readonly OperacionScope $scope) {}

    public function index(ListFiltersRequest $request, ApprovalWorkflow $workflow, LegalFramework $law): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $week = $this->week($request->query('week'));
        $weekEnd = $week->addDays(6);
        $onlyExcess = $request->query('all') !== '1';

        $summaries = WorkdaySummary::query()->whereBetween('date', [$week->toDateString(), $weekEnd->toDateString()]);
        $this->scope->apply($summaries, $user, $filters, useSummaryColumns: true);
        $records = OvertimeRecord::query()->whereBetween('date', [$week->toDateString(), $weekEnd->toDateString()])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
        $this->scope->apply($records, $user, $filters);

        $employeeIds = isset($filters['status'])
            ? (clone $records)->distinct()->pluck('employee_id')
            : ($onlyExcess
                ? (clone $records)->distinct()->pluck('employee_id')
                    ->merge((clone $summaries)->where('after_cutoff_minutes', '>', 0)->distinct()->pluck('employee_id'))->unique()->values()
                : null);
        $employeeIds ??= (clone $summaries)->distinct()->pluck('employee_id')->merge((clone $records)->distinct()->pluck('employee_id'))->unique();

        $cards = $this->scope->employeeCards($employeeIds);
        $sorted = $cards->sortBy('name')->keys()->values();
        $perPage = $request->perPage(15);
        $pageNumber = max(1, (int) $request->validated('page', 1));
        $pageIds = $sorted->forPage($pageNumber, $perPage)->values();

        $sumRows = (clone $summaries)->whereIn('employee_id', $pageIds)->get()->groupBy('employee_id');
        $recRows = (clone $records)->whereIn('employee_id', $pageIds)->with('authorizedBy:id,name')->get()->groupBy('employee_id');

        $rows = $pageIds->map(function ($id) use ($cards, $sumRows, $recRows, $week, $weekEnd, $user, $workflow) {
            $days = [];
            $byDateSum = ($sumRows[$id] ?? collect())->keyBy(fn ($s) => $s->date->toDateString());
            $byDateRec = ($recRows[$id] ?? collect())->keyBy(fn ($r) => $r->date->toDateString());
            foreach (CarbonPeriod::create($week, $weekEnd) as $d) {
                $k = $d->toDateString();
                $s = $byDateSum[$k] ?? null;
                $r = $byDateRec[$k] ?? null;
                $days[] = [
                    'date' => $k,
                    'worked_minutes' => (int) ($s->worked_minutes ?? 0),
                    'ordinary_minutes' => $s ? max(0, (int) $s->worked_minutes - (int) $s->excess_minutes) : 0,
                    // Tiempo posterior a la salida automática: se conserva, no es extra.
                    'after_cutoff_minutes' => (int) ($s->after_cutoff_minutes ?? 0),
                    'auto_clock_out_at' => OperacionScope::iso($s?->auto_clock_out_at),
                    'status_label' => $s?->status->label(),
                    'record' => $r ? $this->record($r, $user, $workflow) : null,
                ];
            }
            $recs = $recRows[$id] ?? collect();
            $sums = $sumRows[$id] ?? collect();

            return [
                'employee' => $cards[$id],
                'days' => $days,
                'totals' => [
                    'worked_minutes' => (int) $sums->sum('worked_minutes'),
                    'ordinary_minutes' => (int) $sums->sum(fn ($s) => max(0, (int) $s->worked_minutes - (int) $s->excess_minutes)),
                    'recorded_excess_minutes' => (int) $recs->sum('recorded_excess_minutes'),
                    'authorized_minutes' => (int) $recs->sum('authorized_minutes'),
                    'double_minutes' => (int) $recs->sum('double_minutes'),
                    'triple_minutes' => (int) $recs->sum('triple_minutes'),
                    'over_limit_minutes' => (int) $recs->sum('over_limit_minutes'),
                    'pending' => $recs->where('status', ApprovalStatus::Pending)->count(),
                    'excess_days' => $recs->where('recorded_excess_minutes', '>', 0)->count(),
                    'after_cutoff_minutes' => (int) $sums->sum('after_cutoff_minutes'),
                ],
            ];
        })->values();

        $paginator = new LengthAwarePaginator($rows, $sorted->count(), $perPage, $pageNumber, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        $totals = (clone $records)->toBase()->reorder()
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('COALESCE(SUM(recorded_excess_minutes),0) as recorded')
            ->selectRaw('COALESCE(SUM(authorized_minutes),0) as authorized')
            ->selectRaw('COALESCE(SUM(double_minutes),0) as dbl')
            ->selectRaw('COALESCE(SUM(triple_minutes),0) as tpl')
            ->selectRaw('COALESCE(SUM(over_limit_minutes),0) as over_limit')
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->first();

        $afterCutoff = (int) (clone $summaries)->sum('after_cutoff_minutes');
        $p = $law->for($week);

        return Inertia::render('horas-extra/Index', [
            'week' => [
                'start' => $week->toDateString(),
                'end' => $weekEnd->toDateString(),
                'previous' => $week->subWeek()->toDateString(),
                'next' => $week->addWeek()->toDateString(),
                'days' => collect(CarbonPeriod::create($week, $weekEnd))->map(fn ($d) => $d->toDateString())->values(),
            ],
            'rows' => $paginator,
            'totals' => [
                'records' => (int) ($totals->records ?? 0),
                'recorded_excess_minutes' => (int) ($totals->recorded ?? 0),
                'authorized_minutes' => (int) ($totals->authorized ?? 0),
                'double_minutes' => (int) ($totals->dbl ?? 0),
                'triple_minutes' => (int) ($totals->tpl ?? 0),
                'over_limit_minutes' => (int) ($totals->over_limit ?? 0),
                'pending' => (int) ($totals->pending ?? 0),
                'after_cutoff_minutes' => $afterCutoff,
            ],
            'limits' => [
                'version' => $p->versionCode,
                'verification_status' => $p->verificationStatus,
                'daily_max_minutes' => $p->overtimeDailyMax,
                'weekly_max_minutes' => $p->overtimeWeeklyMax,
                'max_days_per_week' => $p->overtimeMaxDaysPerWeek,
                'double_multiplier' => $p->overtimeDoubleMultiplier,
                'excess_multiplier' => $p->overtimeExcessMultiplier,
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
                'week' => $week->toDateString(),
                'all' => ! $onlyExcess,
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
            ApprovalStatus::Rejected => 'Tiempo extraordinario no autorizado; el excedente permanece registrado.',
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
    private function record(OvertimeRecord $r, User $user, ApprovalWorkflow $workflow): array
    {
        return [
            'id' => $r->id,
            'date' => $r->date->toDateString(),
            'recorded_excess_minutes' => (int) $r->recorded_excess_minutes,
            'authorized_minutes' => (int) $r->authorized_minutes,
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
        ];
    }
}
