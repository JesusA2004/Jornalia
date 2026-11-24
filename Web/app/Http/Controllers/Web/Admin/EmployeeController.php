<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Security\AccessScope;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Vacations\VacationService;
use App\Domain\Workforce\RegistrationStatus;
use App\Domain\Workforce\WorkforceService;
use App\Enums\ContractType;
use App\Enums\WorkModality;
use App\Http\Requests\Web\Admin\StoreEmployeeRequest;
use App\Http\Requests\Web\Admin\UpdateEmployeeRequest;
use App\Models\Attachment;
use App\Models\AttendancePolicy;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\EmploymentAssignment;
use App\Models\FaceEnrollment;
use App\Models\LegalEntity;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\WorkCenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends AdminController
{
    private const SORTS = ['name' => 'last_name', 'employee_number' => 'employee_number', 'status' => 'status', 'created_at' => 'created_at'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly WorkforceService $workforce,
        private readonly AuditLogger $audit,
        private readonly RegistrationStatus $registration,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);
        $user = $this->actor($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,terminated'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'account' => ['nullable', 'in:with,without'],
            'registration' => ['nullable', 'in:'.implode(',', RegistrationStatus::FILTERS)],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:15,25,50,100'],
        ]);

        $today = CarbonImmutable::today()->toDateString();
        $base = $this->scope->employees($user);

        $query = (clone $base)
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->where('employee_number', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name, second_last_name) like ?", [$like]));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when(($filters['account'] ?? null) === 'with', fn (Builder $q) => $q->whereNotNull('user_id'))
            ->when(($filters['account'] ?? null) === 'without', fn (Builder $q) => $q->whereNull('user_id'))
            ->when($filters['registration'] ?? null, fn (Builder $q, string $f) => $this->registration->applyFilter($q, $f));

        foreach (['branch_id', 'department_id', 'position_id'] as $field) {
            if (! empty($filters[$field])) {
                $value = (int) $filters[$field];
                $query->whereIn('employees.id', fn ($sub) => $sub->select('em.employee_id')
                    ->from('employment_assignments as ea')
                    ->join('employments as em', 'em.id', '=', 'ea.employment_id')
                    ->whereDate('ea.effective_from', '<=', $today)
                    ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
                    ->where("ea.{$field}", $value));
            }
        }

        $sort = self::SORTS[$filters['sort'] ?? 'name'];
        $direction = $filters['direction'] ?? 'asc';
        $query->orderBy($sort, $direction)->when($sort === 'last_name', fn ($q) => $q->orderBy('first_name', $direction));

        $paginator = $query
            ->with(['employments' => fn ($q) => $q->orderByDesc('starts_on'), 'employments.assignments.branch' => fn ($q) => $q->withTrashed()->select('id', 'name'), 'employments.assignments.position' => fn ($q) => $q->withTrashed()->select('id', 'name'), 'employments.assignments.department' => fn ($q) => $q->withTrashed()->select('id', 'name'), 'employments.assignments.schedule' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
        $registration = $this->registration->forMany(array_values(array_map(fn (Employee $e) => (int) $e->id, $paginator->items())));
        $paginator->through(fn (Employee $e) => $this->listRow($e, $today, $registration[$e->id] ?? null));

        $monthStart = CarbonImmutable::today()->startOfMonth()->toDateString();

        return Inertia::render('colaboradores/Index', [
            'employees' => $paginator,
            'filters' => Arr::only($filters, ['search', 'status', 'branch_id', 'department_id', 'position_id', 'account', 'registration', 'sort', 'direction']),
            'stats' => [
                'active' => (clone $base)->where('status', 'active')->count(),
                'terminated' => (clone $base)->where('status', 'terminated')->count(),
                'without_account' => (clone $base)->where('status', 'active')->whereNull('user_id')->count(),
                // Alta de registro incompleta: activos sin app vinculada o sin convenio firmado.
                'registration_incomplete' => $this->registration->whereIncomplete((clone $base)->where('status', 'active'))->count(),
                'hired_this_month' => Employment::query()->whereIn('employee_id', (clone $base)->select('employees.id'))->whereDate('starts_on', '>=', $monthStart)->count(),
            ],
            'options' => [
                'branches' => $this->options(Branch::query()->orderBy('name')->get(['id', 'name', 'code'])),
                'departments' => $this->options(Department::query()->orderBy('name')->get(['id', 'name', 'code'])),
                'positions' => $this->options(Position::query()->orderBy('name')->get(['id', 'name', 'code'])),
            ],
            'can' => [
                'create' => $user->can('create', Employee::class),
                'import' => $user->can('import', Employee::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('colaboradores/Create', [
            'options' => $this->formOptions($request),
            'suggested_number' => $this->suggestNumber(),
            'can' => ['sensitive' => $request->user()?->can('employees.view_sensitive') ?? false],
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $employee = $this->workforce->hire(
            $this->actor($request),
            Arr::only($data, ['employee_number', 'first_name', 'last_name', 'second_last_name', 'email', 'phone', 'birth_date', 'curp', 'rfc', 'nss']),
            Arr::only($data, ['starts_on', 'seniority_starts_on', 'contract_type', 'work_modality', 'legal_entity_id']),
            Arr::only($data, WorkforceService::ASSIGNMENT_FIELDS),
        );

        $this->toast("Se dio de alta a {$employee->fullName()}.");

        return to_route('colaboradores.show', $employee);
    }

    public function show(Request $request, Employee $employee, ScheduleResolver $resolver, VacationService $vacations): Response
    {
        $this->authorize('view', $employee);
        $user = $this->actor($request);
        $today = CarbonImmutable::today();

        $employee->load([
            'user:id,name,email,status,last_login_at,email_verified_at',
            'employments' => fn ($q) => $q->orderByDesc('starts_on'),
            'employments.legalEntity' => fn ($q) => $q->withTrashed()->select('id', 'legal_name'),
            'employments.assignments' => fn ($q) => $q->orderByDesc('effective_from'),
            'employments.assignments.branch' => fn ($q) => $q->withTrashed()->select('id', 'name', 'code'),
            'employments.assignments.workCenter' => fn ($q) => $q->withTrashed()->select('id', 'name', 'timezone'),
            'employments.assignments.department' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            'employments.assignments.position' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            'employments.assignments.schedule' => fn ($q) => $q->withTrashed()->select('id', 'name', 'code'),
            'employments.assignments.supervisor' => fn ($q) => $q->withTrashed()->select('id', 'first_name', 'last_name', 'second_last_name'),
            'employments.assignments.attendancePolicy' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            'employments.assignments.createdBy:id,name',
            'devices' => fn ($q) => $q->orderByDesc('created_at'),
        ]);

        $current = $this->currentAssignment($employee, $today->toDateString());
        $schedule = $current?->schedule_id ? Schedule::query()->with(['segments' => fn ($q) => $q->orderBy('day_index')->orderBy('ordinal')])->find($current->schedule_id) : null;

        $balance = null;
        $balanceError = null;
        if ($employee->employmentOn($today) !== null) {
            try {
                $balance = $vacations->balance($employee, $today);
            } catch (ValidationException $e) {
                $balanceError = collect($e->errors())->flatten()->first();
            } catch (\Throwable $e) {
                report($e);
                $balanceError = 'No fue posible calcular el saldo (verifica la normativa cargada).';
            }
        }

        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $today->addDays($i)->toDateString();
            $shift = $resolver->shiftFor($employee, $date);
            $holiday = $resolver->holidayOn($employee, $date);
            $week[] = [
                'date' => $date,
                'starts_at' => $shift?->startsAt->toIso8601String(),
                'ends_at' => $shift?->endsAt->toIso8601String(),
                'break_minutes' => $shift?->breakMinutes,
                'kind' => $shift?->kind->value,
                'from_exception' => $shift->fromException ?? false,
                'holiday' => $holiday?->name,
            ];
        }

        $roles = [];
        if ($employee->user !== null) {
            $roles = $employee->user->roles()->pluck('name')->all();
        }

        $history = AuditLog::query()
            ->where('subject_type', 'Employee')->where('subject_id', $employee->id)
            ->with('user:id,name')->orderByDesc('created_at')->limit(50)->get()
            ->map(fn (AuditLog $l) => ['id' => $l->id, 'action' => $l->action, 'description' => $l->description, 'at' => $l->created_at->toIso8601String(), 'actor' => $l->user?->name]);

        return Inertia::render('colaboradores/Show', [
            'employee' => [
                ...$this->baseData($employee),
                'birth_date' => $employee->birth_date?->toDateString(),
                'external_source' => $employee->external_source,
                'external_id' => $employee->external_id,
                'external_synced_at' => $employee->external_synced_at?->toIso8601String(),
                'has_identifiers' => ['curp' => filled($employee->getRawOriginal('curp')), 'rfc' => filled($employee->getRawOriginal('rfc')), 'nss' => filled($employee->getRawOriginal('nss'))],
                'created_at' => $employee->created_at?->toIso8601String(),
            ],
            'current' => $current ? $this->assignmentData($current) : null,
            'timezone' => $resolver->timezoneFor($employee, $current),
            'employments' => $employee->employments->map(fn (Employment $em) => [
                'id' => $em->id,
                'starts_on' => $em->starts_on->toDateString(),
                'ends_on' => $em->ends_on?->toDateString(),
                'seniority_starts_on' => $em->seniority_starts_on->toDateString(),
                'contract_type' => $em->contract_type->value,
                'work_modality' => $em->work_modality->value,
                'status' => $em->status,
                'termination_reason' => $em->termination_reason,
                'legal_entity' => $em->legalEntity?->legal_name,
                'assignments' => $em->assignments->map(fn (EmploymentAssignment $a) => $this->assignmentData($a))->values(),
            ])->values(),
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'code' => $schedule->code,
                'type' => $schedule->type->value,
                'shift_kind' => $schedule->shift_kind->value,
                'cycle_length_days' => (int) $schedule->cycle_length_days,
                'tolerance_minutes' => (int) $schedule->tolerance_minutes,
                'weekly_minutes' => $resolver->weeklyScheduledMinutes($schedule),
                'segments' => $schedule->segments->map(fn ($s) => ['day_index' => (int) $s->day_index, 'ordinal' => (int) $s->ordinal, 'starts_at' => substr((string) $s->starts_at, 0, 5), 'ends_at' => substr((string) $s->ends_at, 0, 5), 'break_minutes' => (int) $s->break_minutes])->values(),
            ] : null,
            'week' => $week,
            'exceptions' => ScheduleException::query()->where('employee_id', $employee->id)->whereDate('date', '>=', $today->subDays(30))->orderBy('date')->limit(60)->get()
                ->map(fn (ScheduleException $x) => ['id' => $x->id, 'date' => $x->date->toDateString(), 'is_rest_day' => $x->is_rest_day, 'starts_at' => $x->starts_at ? substr((string) $x->starts_at, 0, 5) : null, 'ends_at' => $x->ends_at ? substr((string) $x->ends_at, 0, 5) : null, 'break_minutes' => (int) $x->break_minutes, 'reason' => $x->reason, 'status' => $x->status]),
            'registration' => $this->registrationData($employee),
            'devices' => $employee->devices->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'platform' => $d->platform, 'model' => $d->model, 'app_version' => $d->app_version,
                'status' => $d->status->value, 'is_corporate' => $d->is_corporate, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'approved_at' => $d->approved_at?->toIso8601String(), 'revoked_at' => $d->revoked_at?->toIso8601String(), 'revoke_reason' => $d->revoke_reason,
            ])->values(),
            'vacations' => $balance,
            'vacations_error' => $balanceError,
            'account' => $employee->user ? [
                'id' => $employee->user->id,
                'name' => $employee->user->name,
                'email' => $employee->user->email,
                'status' => $employee->user->status,
                'last_login_at' => $employee->user->last_login_at?->toIso8601String(),
                'email_verified' => $employee->user->email_verified_at !== null,
                'roles' => $roles,
            ] : null,
            'history' => $history,
            'face' => $this->faceStatus($employee),
            'options' => $this->formOptions($request, $employee),
            'can' => [
                'update' => $user->can('update', $employee),
                'terminate' => $user->can('terminate', $employee),
                'rehire' => $user->can('rehire', $employee),
                'move' => $user->can('move', $employee),
                'sensitive' => $user->can('viewSensitive', $employee),
                'account' => $user->can('manageAccount', $employee),
                'schedule' => $user->can('manageSchedule', $employee),
                'devices' => $user->can('devices.approve') || $user->can('devices.revoke'),
            ],
            'flash_password' => $request->session()->get('temporary_password'),
        ]);
    }

    /**
     * Alta de registro electrónico: app vinculada, convenio firmado y convenios en papel.
     *
     * @return array<string, mixed>
     */
    private function registrationData(Employee $employee): array
    {
        $status = $this->registration->for($employee);
        $papers = Attachment::query()
            ->where('attachable_type', $employee->getMorphClass())
            ->where('attachable_id', $employee->id)
            ->where('category', RegistrationDocuments::PAPER_AGREEMENT_CATEGORY)
            ->with('uploadedBy:id,name')->orderByDesc('id')->get()
            ->map(fn (Attachment $a) => [
                'id' => $a->id, 'name' => $a->original_name, 'size' => (int) $a->size, 'sha256' => $a->sha256,
                'uploaded_at' => $a->created_at?->toIso8601String(), 'uploaded_by' => $a->uploadedBy?->name,
            ])->values();

        return $status + ['papers' => $papers, 'display_status' => self::displayStatus($employee, $status)];
    }

    /**
     * Estado del registro facial (sin plantilla): sólo si existe uno activo y desde cuándo.
     *
     * @return array{enrolled: bool, enrolled_at: string|null}
     */
    private function faceStatus(Employee $employee): array
    {
        $enrollment = FaceEnrollment::query()->where('employee_id', $employee->id)->active()->latest('id')->first(['id', 'created_at']);

        return ['enrolled' => $enrollment !== null, 'enrolled_at' => $enrollment?->created_at?->toIso8601String()];
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();
        $fields = Arr::only($data, ['employee_number', 'first_name', 'last_name', 'second_last_name', 'email', 'phone', 'birth_date']);
        $changedIdentifiers = [];
        if ($request->boolean('update_identifiers') && $request->user()?->can('viewSensitive', $employee)) {
            foreach (['curp', 'rfc', 'nss'] as $key) {
                $fields[$key] = $data[$key] ?? null;
                $changedIdentifiers[] = $key;
            }
        }

        $employee->fill($fields);
        $dirty = array_keys($employee->getDirty());
        $employee->save();

        $this->audit->log('employee.updated', $employee, 'Datos del colaborador actualizados: '.$employee->fullName(), [
            'fields' => array_values(array_diff($dirty, ['curp', 'rfc', 'nss'])),
            'identifiers_changed' => array_values(array_intersect($dirty, $changedIdentifiers)),
        ]);
        $this->toast('Datos actualizados.');

        return back();
    }

    /**
     * Revela identificadores oficiales bajo demanda (queda auditado).
     */
    public function revealIdentifiers(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('viewSensitive', $employee);
        $this->audit->log('employee.identifiers_viewed', $employee, 'Consulta de identificadores oficiales de '.$employee->fullName());

        return response()->json([
            'curp' => $employee->curp,
            'rfc' => $employee->rfc,
            'nss' => $employee->nss,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request, ?Employee $except = null): array
    {
        $supervisors = $this->scope->employees($this->actor($request))
            ->where('status', 'active')
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->orderBy('last_name')->limit(1000)
            ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])
            ->map(fn (Employee $e) => ['value' => (string) $e->id, 'label' => $e->fullName(), 'description' => $e->employee_number])
            ->values();

        return [
            'legal_entities' => $this->options(LegalEntity::query()->where('active', true)->orderBy('legal_name')->get(['id', 'legal_name', 'rfc']), 'legal_name', 'rfc'),
            'work_centers' => $this->options(WorkCenter::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'work_center_id', 'region'])
                ->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => $b->name, 'description' => $b->region ?: $b->code, 'work_center_id' => $b->work_center_id ? (string) $b->work_center_id : null])->values(),
            'departments' => $this->options(Department::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
            'positions' => Position::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'department_id'])
                ->map(fn (Position $p) => ['value' => (string) $p->id, 'label' => $p->name, 'description' => $p->code, 'department_id' => $p->department_id ? (string) $p->department_id : null])->values(),
            'schedules' => $this->options(Schedule::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
            'attendance_policies' => $this->options(AttendancePolicy::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']), 'name', null),
            'supervisors' => $supervisors,
            'contract_types' => array_map(fn (ContractType $c) => ['value' => $c->value, 'label' => self::contractLabel($c)], ContractType::cases()),
            'work_modalities' => array_map(fn (WorkModality $m) => ['value' => $m->value, 'label' => self::modalityLabel($m)], WorkModality::cases()),
        ];
    }

    public static function contractLabel(ContractType $c): string
    {
        return match ($c) {
            ContractType::Indefinite => 'Tiempo indeterminado',
            ContractType::FixedTerm => 'Tiempo u obra determinada',
            ContractType::Seasonal => 'Por temporada',
            ContractType::Trial => 'Periodo a prueba',
            ContractType::Training => 'Capacitación inicial',
        };
    }

    public static function modalityLabel(WorkModality $m): string
    {
        return match ($m) {
            WorkModality::Onsite => 'Presencial',
            WorkModality::Remote => 'Teletrabajo',
            WorkModality::Hybrid => 'Híbrida',
            WorkModality::Field => 'En campo',
        };
    }

    private function suggestNumber(): string
    {
        $numbers = Employee::query()->pluck('employee_number');
        $max = 0;
        foreach ($numbers as $n) {
            if (preg_match('/^\d+$/', (string) $n)) {
                $max = max($max, (int) $n);
            }
        }

        return str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function currentAssignment(Employee $employee, string $date): ?EmploymentAssignment
    {
        $day = CarbonImmutable::parse($date);
        foreach ($employee->employments as $em) {
            if ($em->starts_on->lte($day) && ($em->ends_on === null || $em->ends_on->gte($day))) {
                return $em->assignments->first(fn (EmploymentAssignment $a) => $a->effective_from->lte($day) && ($a->effective_to === null || $a->effective_to->gte($day)));
            }
        }

        // Sin relación vigente: última asignación conocida (alta futura o baja).
        return $employee->employments->first()?->assignments->first();
    }

    /**
     * Estado mostrado: un activo con alta de registro incompleta se presenta como
     * «Alta en proceso» (`onboarding`) hasta tener app vinculada y convenio firmado.
     *
     * @param  array{app: bool, agreement: bool, complete: bool}|null  $registration
     */
    public static function displayStatus(Employee $e, ?array $registration): string
    {
        $status = $e->status->value;

        return $status === 'active' && $registration !== null && ! $registration['complete'] ? 'onboarding' : $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseData(Employee $e): array
    {
        return [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'second_last_name' => $e->second_last_name,
            'full_name' => $e->fullName(),
            'email' => $e->email,
            'phone' => $e->phone,
            'status' => $e->status->value,
            'has_account' => $e->user_id !== null,
        ];
    }

    /**
     * Fila del listado de colaboradores (incluye el estado del alta de registro).
     *
     * @param  array{app: bool, agreement: bool, complete: bool}|null  $registration
     * @return array<string, mixed>
     */
    private function listRow(Employee $e, string $today, ?array $registration = null): array
    {
        $assignment = $this->currentAssignment($e, $today);
        $employment = $e->employments->first();

        return $this->baseData($e) + [
            'registration' => $registration,
            'display_status' => self::displayStatus($e, $registration),
            'branch' => $assignment?->branch?->name,
            'department' => $assignment?->department?->name,
            'position' => $assignment?->position?->name,
            'schedule' => $assignment?->schedule?->name,
            'starts_on' => $employment?->starts_on->toDateString(),
            'ends_on' => $employment?->ends_on?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentData(EmploymentAssignment $a): array
    {
        return [
            'id' => $a->id,
            'effective_from' => $a->effective_from->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(),
            'work_center' => $a->workCenter?->name,
            'work_center_id' => $a->work_center_id,
            'branch' => $a->branch?->name,
            'branch_id' => $a->branch_id,
            'department' => $a->department?->name,
            'department_id' => $a->department_id,
            'position' => $a->position?->name,
            'position_id' => $a->position_id,
            'schedule' => $a->schedule?->name,
            'schedule_id' => $a->schedule_id,
            'supervisor' => $a->supervisor?->fullName(),
            'supervisor_employee_id' => $a->supervisor_employee_id,
            'attendance_policy' => $a->attendancePolicy?->name,
            'attendance_policy_id' => $a->attendance_policy_id,
            'reason' => $a->reason,
            'created_by' => $a->createdBy?->name,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
