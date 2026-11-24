<?php

namespace App\Domain\Reports\Builders;

use App\Domain\Attendance\Anomaly;
use App\Domain\Attendance\EffectiveEvent;
use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\PunchOrigin;
use App\Domain\Attendance\WorkdayCalculator;
use App\Domain\Reports\MealPause;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\Support\ReportFormat;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\WorkdayStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\EmploymentAssignment;
use App\Models\Holiday;
use App\Models\Incidence;
use App\Models\Position;
use App\Models\RectificationRequest;
use App\Models\Schedule;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Filas día por día de asistencia, una por colaborador y fecha civil del
 * periodo en que tuvo relación laboral. Los días sin marcaje, de descanso,
 * vacaciones, permisos y festivos NO desaparecen.
 *
 * Fuente: la proyección `workday_summaries` (WorkdayCalculator). Si un día del
 * pasado aún no tiene proyección persistida, se calcula al vuelo con el mismo
 * WorkdayCalculator sin guardarla (el reporte no modifica datos). No se
 * recalcula ninguna regla laboral aquí: ordinario = efectivo − tiempo extra,
 * igual que en el resto del sistema.
 */
final class AttendanceDays
{
    /** @var array{branches?: array<int, Branch>, departments?: array<int, Department>, positions?: array<int, Position>, schedules?: array<int, Schedule>} */
    private array $catalogs = [];

    public function __construct(
        private readonly ScopedEmployees $employees,
        private readonly WorkdayCalculator $calculator,
        private readonly EffectiveTimeline $timeline,
    ) {}

    public function estimate(ReportParameters $p, User $user): int
    {
        return $this->employees->count($p, $user) * $p->days();
    }

    /**
     * Recorre colaborador por colaborador (orden alfabético) y, dentro, fecha por fecha.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function byEmployee(ReportParameters $p, User $user, bool $withEvents): Generator
    {
        $this->loadCatalogs();
        foreach ($this->employees->chunks($p, $user) as $chunk) {
            $context = $this->context($chunk, $p, $p->from, $p->to);
            foreach ($chunk as $employee) {
                $events = $withEvents ? $this->events($employee, $p, $p->from, $p->to) : null;
                for ($d = $p->from; $d->lte($p->to); $d = $d->addDay()) {
                    $row = $this->row($employee, $d->toDateString(), $p, $context, $events);
                    if ($row !== null) {
                        yield $row;
                    }
                }
            }
        }
    }

    /**
     * Recorre fecha por fecha y, dentro, colaborador por colaborador.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function byDate(ReportParameters $p, User $user, bool $withEvents): Generator
    {
        $this->loadCatalogs();
        for ($d = $p->from; $d->lte($p->to); $d = $d->addDay()) {
            foreach ($this->employees->chunks($p, $user) as $chunk) {
                $context = $this->context($chunk, $p, $d, $d);
                foreach ($chunk as $employee) {
                    $events = $withEvents ? $this->events($employee, $p, $d, $d) : null;
                    $row = $this->row($employee, $d->toDateString(), $p, $context, $events);
                    if ($row !== null) {
                        yield $row;
                    }
                }
            }
        }
    }

    // ── Contexto precargado por bloque ───────────────────────────────

    private function loadCatalogs(): void
    {
        if ($this->catalogs !== []) {
            return;
        }
        $this->catalogs = [
            'branches' => Branch::query()->withTrashed()->get()->keyBy('id')->all(),
            'departments' => Department::query()->withTrashed()->get()->keyBy('id')->all(),
            'positions' => Position::query()->withTrashed()->get()->keyBy('id')->all(),
            'schedules' => Schedule::query()->withTrashed()->get()->keyBy('id')->all(),
        ];
    }

    /**
     * @param  Collection<int, Employee>  $chunk
     * @return array<string, mixed>
     */
    private function context(Collection $chunk, ReportParameters $p, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $chunk->modelKeys();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $summaries = WorkdaySummary::query()->whereIn('employee_id', $ids)
            ->whereBetween('date', [$fromDate, $toDate])->get()
            ->keyBy(fn (WorkdaySummary $s) => $s->employee_id.'|'.$s->date->toDateString());

        $employments = Employment::query()->whereIn('employee_id', $ids)
            ->where('starts_on', '<=', $toDate)
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $fromDate))
            ->with(['assignments' => fn ($q) => $q->orderByDesc('effective_from')])
            ->orderByDesc('starts_on')->get()->groupBy('employee_id');

        $incidences = Incidence::query()->with('type')->whereIn('employee_id', $ids)
            ->where('status', ApprovalStatus::Approved)
            ->whereDate('starts_on', '<=', $toDate)->whereDate('ends_on', '>=', $fromDate)
            ->get()->groupBy('employee_id');

        $vacations = VacationRequest::query()->whereIn('employee_id', $ids)
            ->where('status', ApprovalStatus::Approved)
            ->whereDate('starts_on', '<=', $toDate)->whereDate('ends_on', '>=', $fromDate)
            ->get()->groupBy('employee_id');

        $rectifications = RectificationRequest::query()->whereIn('employee_id', $ids)
            ->whereBetween('date', [$fromDate, $toDate])->orderBy('id')->get()
            ->groupBy(fn (RectificationRequest $r) => $r->employee_id.'|'.$r->date->toDateString());

        $holidays = Holiday::query()->withoutGlobalScopes()->whereBetween('date', [$fromDate, $toDate])
            ->where('is_mandatory_rest', true)
            ->effectiveFor($chunk->first()?->organization_id)
            ->orderByRaw('organization_id IS NULL')->get()
            ->groupBy(fn (Holiday $h) => $h->date->toDateString());

        // Pausa de comida definida por RH para la organización (fuente única, MealPause).
        $mealPause = MealPause::current($chunk->first()?->organization_id);

        return compact('summaries', 'employments', 'incidences', 'vacations', 'rectifications', 'holidays', 'mealPause');
    }

    /**
     * Eventos reconocidos (marcajes originales + efecto de rectificaciones aplicadas).
     *
     * @return list<EffectiveEvent>
     */
    private function events(Employee $employee, ReportParameters $p, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromUtc = CarbonImmutable::parse($from->toDateString(), $p->timezone)->subDay()->utc();
        $toUtc = CarbonImmutable::parse($to->toDateString(), $p->timezone)->addDays(2)->utc();

        return $this->timeline->between($employee->id, $fromUtc, $toUtc);
    }

    // ── Construcción de la fila ─────────────────────────────────────

    /**
     * @param  array<string, mixed>  $ctx
     * @param  list<EffectiveEvent>|null  $events
     * @return array<string, mixed>|null
     */
    private function row(Employee $employee, string $date, ReportParameters $p, array $ctx, ?array $events): ?array
    {
        /** @var SupportCollection<int, Employment> $employments */
        $employments = $ctx['employments']->get($employee->id, collect());
        $employment = $employments->first(fn (Employment $e) => $e->starts_on->toDateString() <= $date && ($e->ends_on === null || $e->ends_on->toDateString() >= $date));
        if ($employment === null) {
            return null; // sin relación laboral ese día: no hay jornada que reportar
        }
        /** @var EmploymentAssignment|null $assignment */
        $assignment = $employment->assignments->first(fn (EmploymentAssignment $a) => $a->effective_from->toDateString() <= $date && ($a->effective_to === null || $a->effective_to->toDateString() >= $date));

        /** @var WorkdaySummary|null $summary */
        $summary = $ctx['summaries']->get($employee->id.'|'.$date);
        if ($summary === null) {
            // Proyección faltante: mismo cálculo de dominio, sin persistir.
            $summary = $this->calculator->compute($employee, $date, persist: false);
        }

        $branchId = $summary->branch_id ?? $assignment?->branch_id;
        $departmentId = $summary->department_id ?? $assignment?->department_id;
        $positionId = $assignment?->position_id;
        $branch = $branchId ? ($this->catalogs['branches'][$branchId] ?? null) : null;
        $region = $branch?->region;

        // Filtros por fila: el colaborador pudo cambiar de sucursal dentro del periodo.
        if (($p->branchId && $branchId !== $p->branchId)
            || ($p->departmentId && $departmentId !== $p->departmentId)
            || ($p->positionId && $positionId !== $p->positionId)
            || ($p->region && $region !== $p->region)) {
            return null;
        }
        $status = $summary->status;
        if ($p->status && $status->value !== $p->status) {
            return null;
        }

        $tz = $p->timezone;
        $day = CarbonImmutable::parse($date);
        $weekStart = $day->startOfWeek(CarbonImmutable::MONDAY);
        $worked = (int) $summary->worked_minutes;
        $excess = (int) $summary->excess_minutes;
        $late = (int) $summary->late_minutes;
        $early = (int) $summary->early_leave_minutes;
        $anomalies = array_values((array) $summary->anomalies);

        $omissions = [];
        if (in_array('missing_clock_in', $anomalies, true)) {
            $omissions[] = 'Sin entrada';
        }
        if (in_array(Anomaly::MissingClockOut->value, $anomalies, true) || ($status === WorkdayStatus::Incomplete && ! in_array('missing_clock_in', $anomalies, true))) {
            $omissions[] = 'Sin salida';
        }
        if (in_array(Anomaly::BreakNotClosed->value, $anomalies, true)) {
            $omissions[] = 'Descanso sin cerrar';
        }

        // Incidencias y vacaciones aprobadas que cubren el día.
        $incidenceItems = [];
        foreach ($ctx['incidences']->get($employee->id, collect()) as $inc) {
            if ($inc->starts_on->toDateString() <= $date && $inc->ends_on->toDateString() >= $date) {
                $incidenceItems[] = ['code' => (string) $inc->type?->code, 'name' => (string) ($inc->type->name ?? 'Incidencia'), 'minutes' => $inc->minutes, 'affects_payroll' => (bool) $inc->type?->affects_payroll, 'id' => $inc->id];
            }
        }
        $onVacation = false;
        foreach ($ctx['vacations']->get($employee->id, collect()) as $vac) {
            if ($vac->starts_on->toDateString() <= $date && $vac->ends_on->toDateString() >= $date) {
                $onVacation = true;
            }
        }
        $incidenceLabels = array_map(fn ($i) => $i['name'], $incidenceItems);
        if ($onVacation) {
            array_unshift($incidenceLabels, 'Vacaciones');
        }

        // Rectificaciones del día (cualquier estado) con su folio interno.
        $rects = $ctx['rectifications']->get($employee->id.'|'.$date, collect());
        $rectLabels = $rects->map(fn (RectificationRequest $r) => 'Núm. '.$r->id.' · '.ReportFormat::rectificationStage($r))->all();
        if ($rectLabels === [] && $summary->has_rectifications) {
            $rectLabels[] = 'Rectificada';
        }

        // Pausas y origen a partir de los eventos reconocidos del día. El sistema
        // registra pausas (inicio/fin de descanso); cuál pausa del día es la comida
        // lo define RH por organización (MealPause, ajuste `reports.meal_pause`; p. ej. 2 = segunda pausa).
        // Sin esa definición, las pausas se reportan como descanso y comida queda vacía.
        $pauses = [];
        $origins = [];
        if ($events !== null && $summary->first_in !== null) {
            $start = CarbonImmutable::instance($summary->first_in);
            $end = $summary->last_out !== null ? CarbonImmutable::instance($summary->last_out) : $start->addHours(20);
            $open = null;
            foreach ($events as $event) {
                if ($event->time->lt($start) || $event->time->gt($end)) {
                    continue;
                }
                // Origen con el vocabulario único de Asistencias (App\Domain\Attendance\PunchOrigin).
                $origins[] = PunchOrigin::LABELS[$event->isRectified() || $event->punch === null ? PunchOrigin::RECTIFIED : PunchOrigin::forPunch($event->punch)];
                if ($event->type === PunchType::BreakStart && $open === null) {
                    $open = $event->time;
                } elseif ($event->type === PunchType::BreakEnd && $open !== null) {
                    $pauses[] = [$open, $event->time];
                    $open = null;
                }
            }
            if ($open !== null) {
                $pauses[] = [$open, null];
            }
        }
        $mealPause = (int) ($ctx['mealPause'] ?? 0);
        $meal = $mealPause > 0 ? ($pauses[$mealPause - 1] ?? null) : null;
        $rest = array_values(array_filter($pauses, fn ($pause, $i) => $i !== $mealPause - 1, ARRAY_FILTER_USE_BOTH));
        [$breakStart, $breakEnd] = $rest[0] ?? [null, null];
        [$mealStart, $mealEnd] = $meal ?? [null, null];

        $observations = array_map(fn ($a) => Anomaly::labelFor((string) $a), array_diff($anomalies, [Anomaly::MissingClockOut->value, 'missing_clock_in', Anomaly::BreakNotClosed->value]));
        if (in_array($status, [WorkdayStatus::Holiday, WorkdayStatus::HolidayWorked], true)) {
            $holiday = $ctx['holidays']->get($date)?->first();
            if ($holiday !== null) {
                array_unshift($observations, 'Festivo: '.$holiday->name);
            }
        }

        $schedule = $summary->schedule_id ? ($this->catalogs['schedules'][$summary->schedule_id] ?? null) : null;
        $times = $summary->scheduled_start !== null && $summary->scheduled_end !== null
            ? CarbonImmutable::instance($summary->scheduled_start)->setTimezone($tz)->format('H:i').'–'.CarbonImmutable::instance($summary->scheduled_end)->setTimezone($tz)->format('H:i')
            : null;
        // El nombre del horario sólo se antepone si no repite ya las horas.
        $scheduleName = (string) ($schedule->name ?? '');
        $scheduleLabel = $times !== null
            ? ($scheduleName !== '' && ! str_contains(str_replace('-', '–', $scheduleName), $times) ? $scheduleName.' '.$times : $times)
            : (in_array($status, [WorkdayStatus::RestDay, WorkdayStatus::RestDayWorked], true) ? 'Descanso' : ($status === WorkdayStatus::Unscheduled ? 'Sin horario' : '—'));

        $attendedStatuses = [WorkdayStatus::Present, WorkdayStatus::Late, WorkdayStatus::Incomplete, WorkdayStatus::RestDayWorked, WorkdayStatus::HolidayWorked];
        $scheduledStatuses = [WorkdayStatus::Present, WorkdayStatus::Late, WorkdayStatus::Incomplete, WorkdayStatus::Absent];
        $attended = in_array($status, $attendedStatuses, true);
        $name = $employee->fullName() ?: '—';
        $department = $departmentId ? ($this->catalogs['departments'][$departmentId] ?? null) : null;
        $position = $positionId ? ($this->catalogs['positions'][$positionId] ?? null) : null;

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $name,
            'branch_id' => $branchId,
            'branch' => $branch?->name,
            'region' => $region,
            'department_id' => $departmentId,
            'department' => $department?->name,
            'position_id' => $positionId,
            'position' => $position?->name,
            'date' => $date,
            'weekday' => ReportFormat::weekday($day),
            'schedule' => $scheduleLabel,
            'shift_kind' => $summary->shift_kind?->label(),
            'scheduled_start' => ReportFormat::iso($summary->scheduled_start),
            'scheduled_end' => ReportFormat::iso($summary->scheduled_end),
            'scheduled_minutes' => (int) $summary->scheduled_minutes,
            'first_in' => ReportFormat::iso($summary->first_in),
            'last_out' => ReportFormat::iso($summary->last_out),
            'break_start' => ReportFormat::iso($breakStart),
            'break_end' => ReportFormat::iso($breakEnd),
            'meal_start' => ReportFormat::iso($mealStart),
            'meal_end' => ReportFormat::iso($mealEnd),
            'break_minutes' => (int) $summary->break_minutes,
            'worked_minutes' => $worked,
            'ordinary_minutes' => max(0, $worked - $excess),
            'extra_minutes' => $excess,
            'night_minutes' => (int) $summary->night_minutes,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'incidence' => implode(', ', $incidenceLabels),
            'omission' => implode(', ', $omissions),
            'status' => $status->value,
            'status_label' => $status->label(),
            'punch_origin' => implode(', ', array_values(array_unique($origins))),
            'rectification' => implode('; ', $rectLabels),
            'observations' => implode('; ', $observations),
            // Indicadores para concentrados (no son columnas visibles).
            '_scheduled' => in_array($status, $scheduledStatuses, true) ? 1 : 0,
            '_attended' => $attended ? 1 : 0,
            '_punctual' => $attended && $late === 0 ? 1 : 0,
            '_absent' => $status === WorkdayStatus::Absent ? 1 : 0,
            '_late_day' => $late > 0 ? 1 : 0,
            '_early_day' => $early > 0 ? 1 : 0,
            '_omission_day' => $omissions !== [] ? 1 : 0,
            '_rest' => in_array($status, [WorkdayStatus::RestDay, WorkdayStatus::RestDayWorked], true) ? 1 : 0,
            '_vacation' => $status === WorkdayStatus::Vacation ? 1 : 0,
            '_leave' => $status === WorkdayStatus::Leave ? 1 : 0,
            '_holiday' => in_array($status, [WorkdayStatus::Holiday, WorkdayStatus::HolidayWorked], true) ? 1 : 0,
            '_incidences' => $incidenceItems,
            '_employee_key' => $employee->id,
            '_employee_label' => $name.($employee->employee_number ? ' · Núm. '.$employee->employee_number : ''),
            '_week_key' => $weekStart->toDateString(),
            '_week_label' => 'Semana del '.$weekStart->format('d/m/Y').' al '.$weekStart->addDays(6)->format('d/m/Y'),
            '_date_key' => $date,
            '_date_label' => ReportFormat::weekday($day).' '.$day->format('d/m/Y'),
            '_branch_key' => $branchId ?? 0,
            '_branch_label' => $branch === null ? 'Sin sucursal asignada' : (str_starts_with(mb_strtolower((string) $branch->name), 'sucursal') ? (string) $branch->name : 'Sucursal '.$branch->name),
        ];
    }
}
