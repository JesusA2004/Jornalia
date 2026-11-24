<?php

namespace App\Domain\Dashboard;

use App\Domain\Attendance\Omissions;
use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\WeeklyOvertime;
use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Security\AccessScope;
use App\Enums\ApprovalStatus;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Enums\RectificationStage;
use App\Enums\WorkdayStatus;
use App\Models\Branch;
use App\Models\ComplianceAlert;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkdaySummary;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * Indicadores del panel ejecutivo calculados con consultas agregadas sobre las
 * proyecciones reales (workday_summaries, overtime_records, punches…).
 *
 * Todo se restringe al alcance del usuario (AccessScope) y, opcionalmente, a
 * una sucursal. Las cifras "de hoy" usan la fecha civil de la organización.
 */
final class DashboardMetrics
{
    /** Estados que cuentan como jornada programada. */
    private const SCHEDULED = [WorkdayStatus::Present, WorkdayStatus::Late, WorkdayStatus::Incomplete, WorkdayStatus::Absent];

    /** Estados con asistencia registrada. */
    private const ATTENDED = [WorkdayStatus::Present, WorkdayStatus::Late, WorkdayStatus::Incomplete, WorkdayStatus::RestDayWorked, WorkdayStatus::HolidayWorked];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly CurrentOrganization $current,
        private readonly ScheduleResolver $schedules,
        private readonly WeeklyOvertimeCalculator $weekly,
        private readonly LegalFramework $law,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        $current = $this->aggregate($user, $from, $to, $branchId);
        $previous = $this->aggregate($user, $prevFrom, $prevTo, $branchId);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'today' => $this->today($user, $branchId),
            'totals' => $current,
            'previous' => $previous,
            'deltas' => $this->deltas($current, $previous),
            'overtime' => $this->overtime($user, $to, $branchId),
            'pending' => $this->pending($user, $branchId),
            'compliance' => $this->compliance($user, $branchId),
            'branches' => $this->branches($user, $from, $to, $branchId),
            'trend' => $this->trend($user, $from, $to, $branchId),
        ];
    }

    /**
     * @return array{scheduled_days:int, attended_days:int, punctual_days:int, late_days:int, absent_days:int, incomplete_days:int, omission_days:int, worked_minutes:int, excess_minutes:int, late_minutes:int, night_minutes:int, punctuality_rate:float|null, attendance_rate:float|null, flagged_punches:int, offline_punches:int, punches:int, to_sign:int}
     */
    public function aggregate(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $scheduled = $this->values(self::SCHEDULED);
        $attended = $this->values(self::ATTENDED);
        $in = fn (array $v) => implode(',', array_map(fn ($s) => "'".$s."'", $v));

        $row = $this->summaries($user, $from, $to, $branchId)->toBase()
            ->selectRaw('SUM(CASE WHEN status IN ('.$in($scheduled).') THEN 1 ELSE 0 END) as scheduled_days')
            ->selectRaw('SUM(CASE WHEN status IN ('.$in($attended).') THEN 1 ELSE 0 END) as attended_days')
            ->selectRaw('SUM(CASE WHEN status IN ('.$in($attended).') AND late_minutes = 0 THEN 1 ELSE 0 END) as punctual_days')
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days')
            ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days")
            ->selectRaw("SUM(CASE WHEN status = 'incomplete' THEN 1 ELSE 0 END) as incomplete_days")
            ->selectRaw('COALESCE(SUM(worked_minutes),0) as worked_minutes')
            ->selectRaw('COALESCE(SUM(excess_minutes),0) as excess_minutes')
            ->selectRaw('COALESCE(SUM(late_minutes),0) as late_minutes')
            ->selectRaw('COALESCE(SUM(night_minutes),0) as night_minutes')
            ->first();
        $omissionDays = Omissions::only($this->summaries($user, $from, $to, $branchId))->count();

        $punches = $this->punches($user, $from, $to, $branchId)->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = '".PunchStatus::Flagged->value."' THEN 1 ELSE 0 END) as flagged")
            ->selectRaw('SUM(CASE WHEN captured_offline = 1 THEN 1 ELSE 0 END) as offline')
            ->first();

        $attendedDays = (int) ($row->attended_days ?? 0);
        $scheduledDays = (int) ($row->scheduled_days ?? 0);
        $punctual = (int) ($row->punctual_days ?? 0);
        $absent = (int) ($row->absent_days ?? 0);

        return [
            'scheduled_days' => $scheduledDays,
            'attended_days' => $attendedDays,
            'punctual_days' => $punctual,
            'late_days' => (int) ($row->late_days ?? 0),
            'absent_days' => $absent,
            'incomplete_days' => (int) ($row->incomplete_days ?? 0),
            'omission_days' => $omissionDays,
            'worked_minutes' => (int) ($row->worked_minutes ?? 0),
            'excess_minutes' => (int) ($row->excess_minutes ?? 0),
            'late_minutes' => (int) ($row->late_minutes ?? 0),
            'night_minutes' => (int) ($row->night_minutes ?? 0),
            'punctuality_rate' => $attendedDays > 0 ? round($punctual * 100 / $attendedDays, 1) : null,
            'attendance_rate' => $scheduledDays > 0 ? round(($scheduledDays - $absent) * 100 / $scheduledDays, 1) : null,
            'punches' => (int) ($punches->total ?? 0),
            'flagged_punches' => (int) ($punches->flagged ?? 0),
            // «Marcajes sin conexión»: capturados offline y sincronizados con hora del dispositivo y del servidor.
            'offline_punches' => (int) ($punches->offline ?? 0),
            // «Rectificaciones por firmar»: autorizadas por el jefe, esperan la firma del colaborador.
            'to_sign' => $this->rectifications($user, $branchId, RectificationStage::ToSign),
        ];
    }

    /**
     * Situación del día en curso. Los programados se resuelven con ScheduleResolver
     * (incluye excepciones y festivos) y se cachean brevemente.
     *
     * @return array<string, mixed>
     */
    public function today(User $user, ?int $branchId): array
    {
        $tz = $this->timezone();
        $now = CarbonImmutable::now($tz);
        $date = $now->toDateString();
        $employees = $this->activeEmployees($user, $branchId);
        $employeeIds = (clone $employees)->pluck('employees.id')->all();

        $key = sprintf('dashboard:today:v2:%d:%d:%s:%s:%s', $this->current->id(), $user->id, $branchId ?? 'all', $date, md5(implode(',', $employeeIds)));
        $scheduled = Cache::remember($key, 300, function () use ($employeeIds, $date) {
            $rows = [];
            Employee::query()->whereIn('id', $employeeIds)->chunkById(200, function ($chunk) use (&$rows, $date) {
                foreach ($chunk as $employee) {
                    $assignment = $employee->assignmentOn($date);
                    if ($assignment === null || $this->schedules->holidayOn($employee, $date, $assignment) !== null) {
                        continue;
                    }
                    $shift = $this->schedules->shiftFor($employee, $date, $assignment);
                    if ($shift !== null) {
                        $rows[$employee->id] = [
                            'start' => $shift->startsAt->toIso8601String(),
                            'end' => $shift->endsAt->toIso8601String(),
                            'tolerance' => $shift->toleranceMinutes,
                            'branch_id' => $assignment->branch_id,
                        ];
                    }
                }
            });

            return $rows;
        });

        $punchesToday = Punch::query()
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereDate('local_date', $date)
            ->orderBy('server_time')
            ->get(['employee_id', 'type', 'status', 'server_time']);

        $last = [];
        $present = [];
        foreach ($punchesToday as $p) {
            $last[$p->employee_id] = $p->type;
            if ($p->type === PunchType::ClockIn) {
                $present[$p->employee_id] = true;
            }
        }
        $openSessions = count(array_filter($last, fn (PunchType $t) => $t !== PunchType::ClockOut));
        $onBreak = count(array_filter($last, fn (PunchType $t) => $t === PunchType::BreakStart));

        $absentNow = 0;
        $pendingArrival = 0;
        $missingOut = 0;
        $byBranch = [];
        foreach ($scheduled as $employeeId => $s) {
            $branch = (int) ($s['branch_id'] ?? 0);
            $byBranch[$branch] ??= ['scheduled' => 0, 'present' => 0];
            $byBranch[$branch]['scheduled']++;
            if (isset($present[$employeeId])) {
                $byBranch[$branch]['present']++;
                // «Sin salida»: jornada abierta cuyo horario ya terminó (omisión en curso).
                if (($last[$employeeId] ?? null) !== PunchType::ClockOut && $now->greaterThan(CarbonImmutable::parse($s['end']))) {
                    $missingOut++;
                }

                continue;
            }
            $deadline = CarbonImmutable::parse($s['start'])->addMinutes((int) $s['tolerance']);
            $now->greaterThan($deadline) ? $absentNow++ : $pendingArrival++;
        }

        $lateToday = WorkdaySummary::query()->whereIn('employee_id', $employeeIds ?: [0])
            ->whereDate('date', $date)->where('late_minutes', '>', 0)->count();

        return [
            'date' => $date,
            'active_employees' => count($employeeIds),
            'scheduled' => count($scheduled),
            'present' => count($present),
            'absent' => $absentNow,
            'pending_arrival' => $pendingArrival,
            'late' => $lateToday,
            'open_sessions' => $openSessions,
            'missing_out' => $missingOut,
            'on_break' => $onBreak,
            'flagged_punches' => $punchesToday->where('status', PunchStatus::Flagged)->count(),
            'punches' => $punchesToday->count(),
            'branches' => $this->branchesToday($byBranch),
        ];
    }

    /**
     * «Asistencia por sucursal»: presentes contra programados de hoy (datos reales).
     *
     * @param  array<int, array{scheduled:int, present:int}>  $byBranch
     * @return list<array{branch_id:int|null, name:string, scheduled:int, present:int, rate:float|null}>
     */
    private function branchesToday(array $byBranch): array
    {
        $names = Branch::query()->whereIn('id', array_filter(array_keys($byBranch)))->pluck('name', 'id');
        $rows = [];
        foreach ($byBranch as $branchId => $c) {
            $rows[] = [
                'branch_id' => $branchId ?: null,
                'name' => $branchId ? (string) ($names[$branchId] ?? 'Sucursal') : 'Sin sucursal',
                'scheduled' => $c['scheduled'],
                'present' => $c['present'],
                'rate' => $c['scheduled'] > 0 ? round($c['present'] * 100 / $c['scheduled'], 1) : null,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Horas extra de la semana que contiene `$day`, calculadas por colaborador y
     * por semana con `WeeklyOvertimeCalculator` (no por suma global de la empresa).
     *
     * @return array{week_start:string, week_end:string, recorded_minutes:int, authorized_minutes:int, justified_minutes:int, pending_minutes:int, double_minutes:int, triple_minutes:int, over_limit_minutes:int, pending:int, employees:int, minors:int, legal_label:string, legal_verified:bool}
     */
    private function overtime(User $user, CarbonImmutable $day, ?int $branchId): array
    {
        $start = WeeklyOvertimeCalculator::weekStart($day);
        $end = $start->addDays(6);

        $q = OvertimeRecord::query()->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        $this->scope->scopeByEmployee($q, $user);
        $this->branch($q, $branchId);
        $ids = (clone $q)->distinct()->pluck('employee_id');
        $pending = (clone $q)->where('status', ApprovalStatus::Pending)->where('recorded_excess_minutes', '>', 0)->count();

        $weeks = $this->weekly->forEmployees(Employee::query()->whereIn('id', $ids->all() ?: [0])->get(), $start);
        $sum = fn (string $key): int => (int) array_sum(array_map(fn (WeeklyOvertime $w) => $w->totals()[$key], $weeks));
        $legal = $this->law->verificationFor($start);

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'recorded_minutes' => $sum('recorded_minutes'),
            'authorized_minutes' => $sum('authorized_minutes'),
            'justified_minutes' => $sum('justified_minutes'),
            'pending_minutes' => $sum('pending_minutes'),
            'double_minutes' => $sum('double_minutes'),
            'triple_minutes' => $sum('triple_minutes'),
            'over_limit_minutes' => $sum('over_limit_minutes'),
            'pending' => $pending,
            'employees' => count(array_filter($weeks, fn (WeeklyOvertime $w) => $w->totals()['recorded_minutes'] > 0)),
            'minors' => count(array_filter($weeks, fn (WeeklyOvertime $w) => $w->minor === true && $w->totals()['recorded_minutes'] > 0)),
            'legal_label' => (string) $legal['label'],
            'legal_verified' => (bool) $legal['verified'],
        ];
    }

    /**
     * Bandeja «Por resolver» del alcance del usuario.
     *
     * @return array{rectifications:int, signatures:int, to_validate:int, omissions:int, incidences:int, vacations:int, overtime:int}
     */
    private function pending(User $user, ?int $branchId): array
    {
        $count = function (string $model) use ($user, $branchId): int {
            /** @var Builder<Model> $q */
            $q = $model::query()->where('status', ApprovalStatus::Pending);
            $this->scope->scopeByEmployee($q, $user);
            $this->branch($q, $branchId);

            return $q->count();
        };

        $omissions = WorkdaySummary::query();
        $this->scope->scopeByEmployee($omissions, $user);
        $this->branch($omissions, $branchId);

        $overtime = OvertimeRecord::query()->where('status', ApprovalStatus::Pending)->where('recorded_excess_minutes', '>', 0);
        $this->scope->scopeByEmployee($overtime, $user);
        $this->branch($overtime, $branchId);

        return [
            'rectifications' => $this->rectifications($user, $branchId, RectificationStage::ToAuthorize),
            // Firmas del colaborador pendientes (rectificaciones autorizadas sin firma).
            'signatures' => $this->rectifications($user, $branchId, RectificationStage::ToSign),
            'to_validate' => $this->rectifications($user, $branchId, RectificationStage::ToValidate),
            'omissions' => Omissions::unrectified($omissions)->count(),
            'incidences' => $count(Incidence::class),
            'vacations' => $count(VacationRequest::class),
            'overtime' => $overtime->count(),
        ];
    }

    private function rectifications(User $user, ?int $branchId, RectificationStage $stage): int
    {
        $q = RectificationRequest::query()->where('stage', $stage);
        $this->scope->scopeByEmployee($q, $user);
        $this->branch($q, $branchId);

        return $q->count();
    }

    /**
     * @return array{open:int, by_severity: array<string,int>, score: int|null, recent: list<array<string,mixed>>}
     */
    private function compliance(User $user, ?int $branchId): array
    {
        $base = ComplianceAlert::query()->where('status', '!=', 'resolved');
        $this->scope->scopeByEmployee($base, $user);
        $this->branch($base, $branchId);

        $bySeverity = (clone $base)->toBase()->selectRaw('severity, COUNT(*) as total')->groupBy('severity')->pluck('total', 'severity')
            ->map(fn ($v) => (int) $v)->all();
        $bySeverity = ['critical' => $bySeverity['critical'] ?? 0, 'warning' => $bySeverity['warning'] ?? 0, 'info' => $bySeverity['info'] ?? 0];

        $recent = (clone $base)->with('employee:id,first_name,last_name,second_last_name,employee_number')
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('date')->limit(6)->get()
            ->map(fn (ComplianceAlert $a) => [
                'id' => $a->id,
                'date' => $a->date?->toDateString(),
                'rule_code' => $a->rule_code,
                'severity' => $a->severity->value,
                'severity_label' => $a->severity->label(),
                'message' => $a->message,
                'legal_basis' => $a->legal_basis,
                'status' => $a->status,
                'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->fullName(), 'number' => $a->employee->employee_number] : null,
            ])->values()->all();

        // Índice simple 0–100: penaliza alertas abiertas ponderadas por severidad
        // respecto al personal activo. Es un indicador operativo, no un dictamen legal.
        $active = max(1, $this->activeEmployees($user, $branchId)->count());
        $weighted = $bySeverity['critical'] * 5 + $bySeverity['warning'] * 2 + $bySeverity['info'] * 0.5;
        $score = (int) max(0, round(100 - min(100, $weighted * 100 / ($active * 5))));

        return [
            'open' => array_sum($bySeverity),
            'by_severity' => $bySeverity,
            'score' => $score,
            'recent' => $recent,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function branches(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $attended = implode(',', array_map(fn ($s) => "'".$s."'", $this->values(self::ATTENDED)));
        $rows = $this->summaries($user, $from, $to, $branchId)->toBase()
            ->select('branch_id')
            ->selectRaw('COUNT(DISTINCT employee_id) as employees')
            ->selectRaw('SUM(CASE WHEN status IN ('.$attended.') THEN 1 ELSE 0 END) as attended')
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late')
            ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent")
            ->selectRaw('COALESCE(SUM(worked_minutes),0) as worked_minutes')
            ->selectRaw('COALESCE(SUM(excess_minutes),0) as excess_minutes')
            ->groupBy('branch_id')
            ->get();

        $names = Branch::query()->whereIn('id', $rows->pluck('branch_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'branch_id' => $r->branch_id,
            'name' => $r->branch_id ? ($names[$r->branch_id] ?? 'Sucursal') : 'Sin sucursal',
            'employees' => (int) $r->employees,
            'attended' => (int) $r->attended,
            'late' => (int) $r->late,
            'absent' => (int) $r->absent,
            'worked_minutes' => (int) $r->worked_minutes,
            'excess_minutes' => (int) $r->excess_minutes,
            'punctuality_rate' => (int) $r->attended > 0 ? round(((int) $r->attended - (int) $r->late) * 100 / (int) $r->attended, 1) : null,
        ])->sortByDesc('worked_minutes')->values()->all();
    }

    /**
     * Serie diaria completa (rellena días sin datos con ceros).
     *
     * @return list<array<string, mixed>>
     */
    private function trend(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $attended = implode(',', array_map(fn ($s) => "'".$s."'", $this->values(self::ATTENDED)));
        $rows = $this->summaries($user, $from, $to, $branchId)->toBase()
            ->selectRaw('DATE(date) as d')
            ->selectRaw('SUM(CASE WHEN status IN ('.$attended.') THEN 1 ELSE 0 END) as attended')
            ->selectRaw('SUM(CASE WHEN status IN ('.$attended.') AND late_minutes = 0 THEN 1 ELSE 0 END) as punctual')
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late')
            ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent")
            ->selectRaw('COALESCE(SUM(worked_minutes),0) as worked_minutes')
            ->selectRaw('COALESCE(SUM(excess_minutes),0) as excess_minutes')
            ->groupByRaw('DATE(date)')
            ->get()->keyBy(fn ($r) => (string) $r->d);

        $series = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $d = $day->toDateString();
            $r = $rows[$d] ?? null;
            $att = (int) ($r->attended ?? 0);
            $series[] = [
                'date' => $d,
                'attended' => $att,
                'punctual' => (int) ($r->punctual ?? 0),
                'late' => (int) ($r->late ?? 0),
                'absent' => (int) ($r->absent ?? 0),
                'worked_hours' => round(((int) ($r->worked_minutes ?? 0)) / 60, 1),
                'excess_hours' => round(((int) ($r->excess_minutes ?? 0)) / 60, 1),
                'punctuality_rate' => $att > 0 ? round(((int) $r->punctual) * 100 / $att, 1) : null,
            ];
        }

        return $series;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, float|null>
     */
    private function deltas(array $current, array $previous): array
    {
        $pct = function (string $key) use ($current, $previous): ?float {
            $prev = (float) ($previous[$key] ?? 0);
            if ($prev == 0.0) {
                return null;
            }

            return round(((float) $current[$key] - $prev) * 100 / $prev, 1);
        };
        $points = fn (string $key) => ($current[$key] !== null && $previous[$key] !== null)
            ? round((float) $current[$key] - (float) $previous[$key], 1) : null;

        return [
            'worked_minutes' => $pct('worked_minutes'),
            'excess_minutes' => $pct('excess_minutes'),
            'late_days' => $pct('late_days'),
            'absent_days' => $pct('absent_days'),
            'flagged_punches' => $pct('flagged_punches'),
            'attended_days' => $pct('attended_days'),
            'punctuality_rate' => $points('punctuality_rate'),
            'attendance_rate' => $points('attendance_rate'),
        ];
    }

    /**
     * @return Builder<WorkdaySummary>
     */
    private function summaries(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): Builder
    {
        $q = WorkdaySummary::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $this->scope->scopeByEmployee($q, $user);

        return $q->when($branchId, fn ($w) => $w->where('branch_id', $branchId));
    }

    /**
     * @return Builder<Punch>
     */
    private function punches(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): Builder
    {
        $q = Punch::query()->whereBetween('local_date', [$from->toDateString(), $to->toDateString()]);
        $this->scope->scopeByEmployee($q, $user);
        $this->branch($q, $branchId);

        return $q;
    }

    /**
     * @return Builder<Employee>
     */
    private function activeEmployees(User $user, ?int $branchId): Builder
    {
        $q = $this->scope->employees($user)->where('employees.status', 'active');
        $this->branch($q, $branchId, 'employees.id');

        return $q;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     */
    private function branch(Builder $q, ?int $branchId, ?string $column = null): void
    {
        if ($branchId === null) {
            return;
        }
        $today = CarbonImmutable::now($this->timezone())->toDateString();
        $column ??= $q->getModel()->qualifyColumn('employee_id');
        $q->whereIn($column, function (QueryBuilder $sub) use ($branchId, $today) {
            $sub->select('em.employee_id')->from('employment_assignments as ea')
                ->join('employments as em', 'em.id', '=', 'ea.employment_id')
                ->where('ea.branch_id', $branchId)
                ->whereDate('ea.effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today));
        });
    }

    private function timezone(): string
    {
        return (string) ($this->current->get()?->timezone ?? config('app.timezone', 'UTC'));
    }

    /**
     * @param  list<WorkdayStatus>  $statuses
     * @return list<string>
     */
    private function values(array $statuses): array
    {
        return array_map(fn (WorkdayStatus $s) => $s->value, $statuses);
    }
}
