<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incidence;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkdaySummary;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendario operativo: turnos (ScheduleResolver), descansos, festivos con
 * fundamento legal, vacaciones e incidencias. Vista mes / semana / día.
 */
final class CalendarController extends Controller
{
    public function __construct(
        private readonly OperacionScope $scope,
        private readonly ScheduleResolver $schedules,
    ) {}

    public function __invoke(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $view = in_array($request->query('view'), ['month', 'week', 'day'], true) ? (string) $request->query('view') : 'week';
        $anchor = is_string($request->query('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1
            ? CarbonImmutable::parse((string) $request->query('date'))
            : $this->scope->today();

        [$start, $end] = match ($view) {
            'month' => [$anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'day' => [$anchor->startOfDay(), $anchor->startOfDay()],
            default => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)],
        };
        [$prev, $next] = match ($view) {
            'month' => [$anchor->subMonthNoOverflow()->startOfMonth(), $anchor->addMonthNoOverflow()->startOfMonth()],
            'day' => [$anchor->subDay(), $anchor->addDay()],
            default => [$anchor->subWeek(), $anchor->addWeek()],
        };

        $employeesQuery = $this->scope->employees($user)->where('employees.status', 'active');
        $this->scope->apply($employeesQuery, $user, $filters, 'employees.id');
        $perPage = $view === 'month' ? (isset($filters['employee_id']) ? 1 : 0) : $request->perPage(15);

        $roster = null;
        $rosterIds = collect();
        if ($perPage > 0) {
            $page = (clone $employeesQuery)->orderBy('first_name')->orderBy('last_name')->paginate($perPage)->withQueryString();
            $rosterIds = $page->getCollection()->pluck('id');
            $cards = $this->scope->employeeCards($rosterIds);
            $page->through(function (Employee $e) use ($cards, $start, $end) {
                $days = $this->employeeDays($e, $start, $end);

                return [
                    'employee' => $cards[$e->id] ?? null,
                    'days' => $days,
                    // Descanso semanal del colaborador según su horario vigente (LFT art. 69).
                    'rest_days' => $this->restDays($e, $start),
                ];
            });
            $roster = $page;
        }

        $visibleIds = isset($filters['employee_id']) || isset($filters['branch_id']) || isset($filters['department_id']) || isset($filters['search'])
            ? (clone $employeesQuery)->pluck('employees.id')
            : null;

        return Inertia::render('calendario/Index', [
            'view' => $view,
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'anchor' => $anchor->toDateString(),
                'previous' => $prev->toDateString(),
                'next' => $next->toDateString(),
                'today' => $this->scope->today()->toDateString(),
                'days' => array_map(fn (CarbonInterface $d) => $d->toDateString(), CarbonPeriod::create($start, $end)->toArray()),
            ],
            'roster' => $roster,
            'events' => $this->events($user, $start, $end, $visibleIds),
            'holidays' => $this->holidays($start, $end),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'view' => $view,
                'date' => $anchor->toDateString(),
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
            ],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    /**
     * Día a día de un colaborador: turno, descanso, festivo, ausencias y estado.
     *
     * @return list<array<string, mixed>>
     */
    private function employeeDays(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vacations = VacationRequest::query()->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Pending])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)->get();
        $incidences = Incidence::query()->with('type:id,name,category')->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Pending])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)->get();
        $summaries = WorkdaySummary::query()->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->keyBy(fn ($s) => $s->date->toDateString());

        $out = [];
        foreach (CarbonPeriod::create($start, $end) as $d) {
            $date = $d->toDateString();
            $assignment = $employee->assignmentOn($date);
            $shift = $assignment ? $this->schedules->shiftFor($employee, $date, $assignment) : null;
            $holiday = $assignment ? $this->schedules->holidayOn($employee, $date, $assignment) : null;
            $vacation = $vacations->first(fn ($v) => $v->starts_on->lte($d) && $v->ends_on->gte($d));
            $incidence = $incidences->first(fn ($i) => $i->starts_on->lte($d) && $i->ends_on->gte($d));
            $summary = $summaries[$date] ?? null;

            $out[] = [
                'date' => $date,
                'employed' => $assignment !== null,
                'shift' => $shift ? [
                    'starts_at' => OperacionScope::iso($shift->startsAt),
                    'ends_at' => OperacionScope::iso($shift->endsAt),
                    'kind' => $shift->kind->value,
                    'kind_label' => $shift->kind->label(),
                    'break_minutes' => $shift->breakMinutes,
                    'schedule_name' => $shift->scheduleName,
                    'from_exception' => $shift->fromException,
                ] : null,
                'rest_day' => $assignment !== null && $shift === null && $assignment->schedule_id !== null,
                'no_schedule' => $assignment !== null && $assignment->schedule_id === null,
                'holiday' => $holiday ? ['name' => $holiday->name, 'legal_basis' => $holiday->legal_basis, 'is_mandatory_rest' => (bool) $holiday->is_mandatory_rest] : null,
                'vacation' => $vacation ? ['id' => $vacation->id, 'status' => $vacation->status->value, 'status_label' => $vacation->status->label()] : null,
                'incidence' => $incidence ? [
                    'id' => $incidence->id,
                    'name' => $incidence->type?->name,
                    'category' => $incidence->type?->category,
                    'status' => $incidence->status->value,
                    'status_label' => $incidence->status->label(),
                ] : null,
                'status' => $summary?->status->value,
                'status_label' => $summary?->status->label(),
                'worked_minutes' => (int) ($summary->worked_minutes ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Días de descanso semanal (nombres en español) de la semana que contiene `$from`.
     *
     * @return list<string>
     */
    private function restDays(Employee $employee, CarbonImmutable $from): array
    {
        $monday = $from->startOfWeek(CarbonImmutable::MONDAY);
        $names = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $rest = [];
        foreach (range(0, 6) as $i) {
            $date = $monday->addDays($i)->toDateString();
            $assignment = $employee->assignmentOn($date);
            if ($assignment?->schedule_id !== null && $this->schedules->shiftFor($employee, $date, $assignment) === null) {
                $rest[] = $names[$i];
            }
        }

        return $rest;
    }

    /**
     * Vacaciones e incidencias del periodo (todo el alcance o el filtro activo).
     *
     * @param  Collection<int, int>|null  $employeeIds
     * @return list<array<string, mixed>>
     */
    private function events(User $user, CarbonImmutable $start, CarbonImmutable $end, ?Collection $employeeIds): array
    {
        $vac = VacationRequest::query()->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Pending])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds->all() ?: [0]));
        $this->scope->apply($vac, $user, []);
        $inc = Incidence::query()->with('type:id,name,category')->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Pending])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds->all() ?: [0]));
        $this->scope->apply($inc, $user, []);

        $vacations = $vac->limit(500)->get();
        $incidences = $inc->limit(500)->get();
        $cards = $this->scope->employeeCards($vacations->pluck('employee_id')->merge($incidences->pluck('employee_id')));

        $events = [];
        foreach ($vacations as $v) {
            $events[] = [
                'id' => 'v'.$v->id,
                'kind' => 'vacation',
                'kind_label' => 'Vacaciones',
                'title' => $cards[$v->employee_id]['name'] ?? 'Colaborador',
                'subtitle' => $cards[$v->employee_id]['branch'] ?? null,
                'starts_on' => $v->starts_on->toDateString(),
                'ends_on' => $v->ends_on->toDateString(),
                'status' => $v->status->value,
                'status_label' => $v->status->label(),
                'href' => route('vacaciones.show', $v->id),
            ];
        }
        foreach ($incidences as $i) {
            $kind = match ($i->type?->category) {
                'disability' => 'disability',
                'leave' => 'leave',
                default => 'incidence',
            };
            $events[] = [
                'id' => 'i'.$i->id,
                'kind' => $kind,
                'kind_label' => $i->type->name ?? 'Incidencia',
                'title' => $cards[$i->employee_id]['name'] ?? 'Colaborador',
                'subtitle' => $i->type?->name,
                'starts_on' => $i->starts_on->toDateString(),
                'ends_on' => $i->ends_on->toDateString(),
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'href' => route('incidencias.show', $i->id),
            ];
        }

        return $events;
    }

    /**
     * Festivos oficiales y de la empresa (fuente legal incluida).
     *
     * @return list<array<string, mixed>>
     */
    private function holidays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $holidays = Holiday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', app(CurrentOrganization::class)->id()))
            ->orderBy('date')->get()
            ->map(fn (Holiday $h) => [
                'id' => $h->id,
                'date' => $h->date->toDateString(),
                'code' => $h->code,
                'name' => $h->name,
                'kind' => $h->kind,
                'kind_label' => match ($h->kind) {
                    'official' => 'Oficial',
                    'company' => 'Empresa',
                    'local' => 'Local',
                    default => $h->kind,
                },
                'is_mandatory_rest' => (bool) $h->is_mandatory_rest,
                'legal_basis' => $h->legal_basis,
            ]);

        return array_values($holidays->all());
    }
}
