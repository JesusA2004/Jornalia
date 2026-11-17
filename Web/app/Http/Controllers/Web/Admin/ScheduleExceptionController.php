<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\AccessScope;
use App\Domain\Workforce\ScheduleLegalValidator;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Jobs\RecomputeWorkday;
use App\Models\Employee;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Excepciones de horario por colaborador y día (descanso o jornada especial).
 */
class ScheduleExceptionController extends AdminController
{
    use OrganizationRules;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly ScheduleLegalValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('schedules.view'), 403);
        $user = $this->actor($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from']) : CarbonImmutable::today()->subDays(7);
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to']) : CarbonImmutable::today()->addDays(60);

        $exceptions = $this->scope->scopeByEmployee(ScheduleException::query(), $user)
            ->with(['employee:id,first_name,last_name,second_last_name,employee_number', 'createdBy:id,name'])
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->orderBy('date')->paginate(25)->withQueryString()
            ->through(fn (ScheduleException $x) => [
                'id' => $x->id,
                'date' => $x->date->toDateString(),
                'employee' => ['id' => $x->employee_id, 'name' => $x->employee?->fullName(), 'employee_number' => $x->employee?->employee_number],
                'is_rest_day' => $x->is_rest_day,
                'starts_at' => $x->starts_at ? substr((string) $x->starts_at, 0, 5) : null,
                'ends_at' => $x->ends_at ? substr((string) $x->ends_at, 0, 5) : null,
                'break_minutes' => (int) $x->break_minutes,
                'reason' => $x->reason,
                'created_by' => $x->createdBy?->name,
            ]);

        return Inertia::render('horarios/Exceptions', [
            'exceptions' => $exceptions,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null],
            'employees' => $this->scope->employees($user)->where('status', 'active')->orderBy('last_name')->limit(2000)
                ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])
                ->map(fn (Employee $e) => ['value' => (string) $e->id, 'label' => $e->fullName(), 'description' => $e->employee_number])->values(),
            'can' => ['manage' => $user->can('schedules.assign')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('schedules.assign'), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', $this->existsInOrg('employees')],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'is_rest_day' => ['required', 'boolean'],
            'starts_at' => [Rule::requiredIf(! $request->boolean('is_rest_day')), 'nullable', 'date_format:H:i'],
            'ends_at' => [Rule::requiredIf(! $request->boolean('is_rest_day')), 'nullable', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], ['date.after_or_equal' => 'Las excepciones no pueden registrarse en fechas pasadas; usa una rectificación.'], [
            'employee_id' => 'colaborador', 'date' => 'fecha', 'starts_at' => 'entrada', 'ends_at' => 'salida', 'break_minutes' => 'descanso', 'reason' => 'motivo',
        ]);

        $employee = Employee::query()->findOrFail((int) $data['employee_id']);
        $this->authorize('manageSchedule', $employee);

        if ($employee->employmentOn($data['date']) === null) {
            throw ValidationException::withMessages(['date' => 'El colaborador no tiene relación laboral vigente en esa fecha.']);
        }
        if (! $data['is_rest_day']) {
            $this->validator->assertSingleShift($data['date'], $data['starts_at'], $data['ends_at'], (int) ($data['break_minutes'] ?? 0));
        }

        $exception = ScheduleException::query()->withTrashed()->updateOrCreate(
            ['employee_id' => $employee->id, 'date' => CarbonImmutable::parse($data['date'])->toDateString()],
            [
                'is_rest_day' => (bool) $data['is_rest_day'],
                'starts_at' => $data['is_rest_day'] ? null : $data['starts_at'],
                'ends_at' => $data['is_rest_day'] ? null : $data['ends_at'],
                'break_minutes' => $data['is_rest_day'] ? 0 : (int) ($data['break_minutes'] ?? 0),
                'reason' => $data['reason'],
                'created_by' => $request->user()?->id,
                'deleted_at' => null,
            ],
        );

        RecomputeWorkday::dispatch($employee->organization_id, $employee->id, $exception->date->toDateString())->afterCommit();
        $this->audit->log('schedule_exception.saved', $exception, 'Excepción de horario para '.$employee->fullName().' el '.$exception->date->format('d/m/Y'), [
            'employee_id' => $employee->id, 'is_rest_day' => $exception->is_rest_day, 'reason' => $exception->reason,
        ]);
        $this->toast('Excepción de horario registrada.');

        return back();
    }

    public function deactivate(Request $request, ScheduleException $scheduleException): RedirectResponse
    {
        $this->ensureOwned($scheduleException);
        $employee = Employee::query()->findOrFail($scheduleException->employee_id);
        $this->authorize('manageSchedule', $employee);

        if ($scheduleException->date->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => 'No se pueden anular excepciones de días pasados.']);
        }

        $this->audit->log('schedule_exception.cancelled', $scheduleException, 'Excepción de horario anulada para '.$employee->fullName(), ['date' => $scheduleException->date->toDateString()]);
        $date = $scheduleException->date->toDateString();
        $scheduleException->delete();
        RecomputeWorkday::dispatch($employee->organization_id, $employee->id, $date)->afterCommit();
        $this->toast('Excepción anulada: el colaborador vuelve a su horario asignado.');

        return back();
    }
}
