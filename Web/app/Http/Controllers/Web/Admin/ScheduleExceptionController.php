<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Scheduling\ScheduleChangeService;
use App\Domain\Security\AccessScope;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Employee;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Cambios de horario programados» (antes «Excepciones», PDF RH pantalla 10):
 * sólo a futuro, con motivo de catálogo, autorización de RH, aviso al colaborador
 * y bitácora. La lógica vive en ScheduleChangeService.
 */
class ScheduleExceptionController extends AdminController
{
    use OrganizationRules;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly ScheduleChangeService $changes,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('schedules.view'), 403);
        $user = $this->actor($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([ScheduleException::STATUS_PENDING, ScheduleException::STATUS_APPROVED, ScheduleException::STATUS_REJECTED])],
        ]);
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from']) : CarbonImmutable::today()->subDays(7);
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to']) : CarbonImmutable::today()->addDays(60);

        $base = $this->scope->scopeByEmployee(ScheduleException::query(), $user);
        $changes = (clone $base)
            ->with(['employee:id,first_name,last_name,second_last_name,employee_number', 'createdBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name'])
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
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
                'reason_code' => $x->reason_code,
                'reason_label' => $x->reasonLabel(),
                'status' => $x->status,
                'created_by' => $x->createdBy?->name,
                'created_by_id' => $x->created_by,
                'approved_by' => $x->approvedBy?->name,
                'rejected_by' => $x->rejectedBy?->name,
                'rejection_reason' => $x->rejection_reason,
                'notified_at' => $x->notified_at?->toIso8601String(),
            ]);

        return Inertia::render('horarios/Exceptions', [
            'exceptions' => $changes,
            'filters' => [
                'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
            ],
            'pending_count' => (clone $base)->where('status', ScheduleException::STATUS_PENDING)->count(),
            'employees' => $this->scope->employees($user)->where('status', 'active')->orderBy('last_name')->limit(2000)
                ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])
                ->map(fn (Employee $e) => ['value' => (string) $e->id, 'label' => $e->fullName(), 'description' => $e->employee_number])->values(),
            'reasons' => collect(ScheduleException::REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'min_date' => CarbonImmutable::tomorrow()->toDateString(),
            'current_user_id' => $user->id,
            'can' => ['manage' => $user->can('schedules.assign'), 'approve' => $user->can('schedules.approve_changes')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('schedules.assign'), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', $this->existsInOrg('employees')],
            'date' => ['required', 'date'],
            'is_rest_day' => ['required', 'boolean'],
            'starts_at' => [Rule::requiredIf(! $request->boolean('is_rest_day')), 'nullable', 'date_format:H:i'],
            'ends_at' => [Rule::requiredIf(! $request->boolean('is_rest_day')), 'nullable', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'reason_code' => ['required', 'string', Rule::in(array_keys(ScheduleException::REASONS))],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], [
            'employee_id' => 'colaborador', 'date' => 'fecha', 'starts_at' => 'entrada', 'ends_at' => 'salida', 'break_minutes' => 'descanso', 'reason_code' => 'motivo', 'reason' => 'detalle',
        ]);

        $employee = Employee::query()->findOrFail((int) $data['employee_id']);
        $this->authorize('manageSchedule', $employee);

        $this->changes->request($this->actor($request), $employee, $data);
        $this->toast('Cambio registrado. Queda pendiente de autorización de RH.');

        return back();
    }

    public function approve(Request $request, ScheduleException $scheduleException): RedirectResponse
    {
        $this->ensureOwned($scheduleException);
        $this->assertApprover($request, $scheduleException);
        $this->changes->approve($this->actor($request), $scheduleException);
        $this->toast('Cambio autorizado y notificado al colaborador.');

        return back();
    }

    public function reject(Request $request, ScheduleException $scheduleException): RedirectResponse
    {
        $this->ensureOwned($scheduleException);
        $this->assertApprover($request, $scheduleException);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);
        $this->changes->reject($this->actor($request), $scheduleException, $data['reason']);
        $this->toast('Cambio rechazado.');

        return back();
    }

    public function deactivate(Request $request, ScheduleException $scheduleException): RedirectResponse
    {
        $this->ensureOwned($scheduleException);
        $employee = Employee::query()->findOrFail($scheduleException->employee_id);
        $user = $this->actor($request);
        abort_unless($user->can('manageSchedule', $employee) || ($user->can('schedules.approve_changes') && $this->scope->canAccessEmployee($user, $employee)), 403);

        $this->changes->cancel($user, $scheduleException);
        $this->toast('Cambio anulado: el colaborador conserva su horario asignado.');

        return back();
    }

    private function assertApprover(Request $request, ScheduleException $change): void
    {
        $user = $this->actor($request);
        abort_unless($user->can('schedules.approve_changes') && $this->scope->canAccessEmployee($user, $change->employee_id), 403);
    }
}
