<?php

namespace App\Http\Controllers\Web\Comunicados;

use App\Domain\Notifications\AnnouncementService;
use App\Domain\Notifications\AudienceResolver;
use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementRecipient;
use App\Domain\Notifications\NotificationSettings;
use App\Domain\Security\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comunicados a colaboradores (bandeja de la app + push FCM) y ajustes de
 * avisos automáticos. Permisos: communications.view / communications.send.
 */
final class AnnouncementController extends Controller
{
    private const AUDIENCE_LABELS = [
        'all' => 'Todo el personal',
        'branches' => 'Sucursales',
        'departments' => 'Departamentos',
        'positions' => 'Puestos',
        'employees' => 'Colaboradores',
    ];

    public function __construct(
        private readonly AnnouncementService $announcements,
        private readonly AudienceResolver $audience,
        private readonly AccessScope $scope,
        private readonly CurrentOrganization $current,
        private readonly NotificationSettings $settings,
        private readonly FcmClient $fcm,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', 'in:scheduled,sent,withdrawn'],
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'in:15,25,50,100'],
        ]);

        $page = $this->visible($user)
            ->with('createdBy:id,name')
            ->when($filters['status'] ?? null, function (Builder $q, string $status) {
                $status === 'withdrawn' ? $q->whereNotNull('withdrawn_at') : $q->whereNull('withdrawn_at')->where('status', $status === 'sent' ? '!=' : '=', 'scheduled');
            })
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        $ids = $page->getCollection()->pluck('id')->all();
        foreach ($page->getCollection() as $a) {
            $this->announcements->syncReadState($a);
        }
        $stats = $this->announcements->stats($ids);

        return Inertia::render('comunicados/Index', [
            'announcements' => $page->through(fn (Announcement $a) => $this->row($a, $stats[$a->id] ?? null)),
            'filters' => ['status' => $filters['status'] ?? null, 'search' => $filters['search'] ?? ''],
            'settings' => $this->settings->all(),
            'push' => ['enabled' => $this->fcm->enabled()],
            'can' => ['send' => $user->can('communications.send')],
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->actor($request);
        $full = $this->scope->hasFullAccess($user);

        $branches = Branch::query()->orderBy('name')
            ->when(! $full, fn (Builder $q) => $q->whereIn('id', $user->branches()->pluck('branches.id')->all() ?: [0]))
            ->get(['id', 'name', 'code']);

        return Inertia::render('comunicados/Create', [
            'options' => [
                'branches' => $this->options($branches),
                'departments' => $this->options(Department::query()->orderBy('name')->get(['id', 'name', 'code'])),
                'positions' => $this->options(Position::query()->orderBy('name')->get(['id', 'name', 'code'])),
            ],
            'employeeOptions' => fn () => $this->employeeOptions($user, (string) $request->query('q', ''), (array) $request->query('selected', [])),
            'estimate' => Inertia::optional(fn () => $this->estimate($user, $request)),
            'timezone' => $this->current->require()->timezone,
            'push' => ['enabled' => $this->fcm->enabled()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $orgId = $this->current->require()->id;
        $tables = ['branches' => 'branches', 'departments' => 'departments', 'positions' => 'positions', 'employees' => 'employees'];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:1000'],
            'priority' => ['required', 'in:normal,high'],
            'audience_type' => ['required', Rule::in(Announcement::AUDIENCES)],
            'audience_ids' => ['exclude_if:audience_type,all', 'required', 'array', 'min:1', 'max:500'],
            'audience_ids.*' => ['integer', Rule::exists($tables[$request->input('audience_type')] ?? 'branches', 'id')->where('organization_id', $orgId)],
            'send_mode' => ['required', 'in:now,scheduled'],
            'scheduled_date' => ['exclude_unless:send_mode,scheduled', 'required', 'date_format:Y-m-d'],
            'scheduled_time' => ['exclude_unless:send_mode,scheduled', 'required', 'date_format:H:i'],
        ], [
            'audience_ids.required' => 'Elige al menos un destinatario.',
            'audience_ids.*.exists' => 'Un destinatario no pertenece a la organización.',
        ], [
            'title' => 'título', 'body' => 'mensaje', 'audience_ids' => 'destinatarios',
            'scheduled_date' => 'fecha', 'scheduled_time' => 'hora',
        ]);

        $ids = array_map('intval', (array) ($data['audience_ids'] ?? []));
        if ($data['audience_type'] === 'employees') {
            $allowed = $this->scope->employees($user)->whereIn('employees.id', $ids)->count();
            if ($allowed !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['audience_ids' => 'Incluye colaboradores fuera de tu alcance.']);
            }
        }
        if ($this->audience->count($user, $data['audience_type'], $ids) === 0) {
            throw ValidationException::withMessages(['audience_ids' => 'No hay colaboradores activos con esos criterios.']);
        }

        $scheduledAt = null;
        if ($data['send_mode'] === 'scheduled') {
            $scheduledAt = CarbonImmutable::parse($data['scheduled_date'].' '.$data['scheduled_time'], $this->current->require()->timezone)->utc();
            if ($scheduledAt->lte(now()->addMinute())) {
                throw ValidationException::withMessages(['scheduled_time' => 'Elige una hora futura.']);
            }
        }

        $announcement = $this->announcements->create($user, [
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'priority' => $data['priority'],
            'audience_type' => $data['audience_type'],
            'audience_ids' => $ids,
            'scheduled_at' => $scheduledAt,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $scheduledAt ? 'Comunicado programado.' : 'Comunicado enviado.']);

        return redirect()->route('comunicados.show', $announcement->id);
    }

    public function show(Request $request, int $announcement): Response
    {
        $user = $this->actor($request);
        $model = $this->find($user, $announcement);
        $filters = $request->validate([
            'state' => ['nullable', 'in:read,unread,failed'],
            'per_page' => ['nullable', 'integer', 'in:15,25,50,100'],
        ]);

        $this->announcements->syncReadState($model);
        $stats = $this->announcements->stats([$model->id])[$model->id] ?? null;

        $recipients = $this->scope->scopeByEmployee(AnnouncementRecipient::query(), $user)
            ->where('announcement_id', $model->id)
            ->with('employee:id,first_name,last_name,second_last_name,employee_number')
            ->when($filters['state'] ?? null, fn (Builder $q, string $state) => match ($state) {
                'read' => $q->whereNotNull('read_at'),
                'unread' => $q->whereNull('read_at')->whereNull('failed_at'),
                default => $q->where(fn ($w) => $w->whereNotNull('failed_at')->orWhere('push_status', AnnouncementRecipient::PUSH_FAILED)),
            })
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (AnnouncementRecipient $r) => [
                'id' => $r->id,
                'employee' => $r->employee ? ['id' => $r->employee->id, 'name' => $r->employee->fullName(), 'employee_number' => $r->employee->employee_number] : null,
                'delivered_at' => $r->delivered_at?->toIso8601String(),
                'read_at' => $r->read_at?->toIso8601String(),
                'push_status' => $r->push_status,
                'error' => $r->error,
            ]);

        return Inertia::render('comunicados/Show', [
            'announcement' => $this->row($model->loadMissing('createdBy:id,name', 'withdrawnBy:id,name'), $stats) + [
                'body' => $model->body,
                'audience_names' => $this->audienceNames($model),
                'withdrawn_by' => $model->withdrawnBy?->name,
            ],
            'recipients' => $recipients,
            'filters' => ['state' => $filters['state'] ?? null],
            'can' => ['send' => $user->can('communications.send')],
        ]);
    }

    public function withdraw(Request $request, int $announcement): RedirectResponse
    {
        $user = $this->actor($request);
        $this->announcements->withdraw($this->find($user, $announcement), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comunicado retirado.']);

        return back();
    }

    public function reactivate(Request $request, int $announcement): RedirectResponse
    {
        $user = $this->actor($request);
        $this->announcements->reactivate($this->find($user, $announcement), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comunicado reactivado.']);

        return back();
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'late_enabled' => ['required', 'boolean'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'clock_out_enabled' => ['required', 'boolean'],
            'clock_out_minutes_after' => ['required', 'integer', 'min:0', 'max:240'],
        ], [], ['late_grace_minutes' => 'minutos de gracia', 'clock_out_minutes_after' => 'minutos']);

        $this->settings->save([
            'late' => ['enabled' => (bool) $data['late_enabled'], 'grace_minutes' => (int) $data['late_grace_minutes']],
            'clock_out' => ['enabled' => (bool) $data['clock_out_enabled'], 'minutes_after' => (int) $data['clock_out_minutes_after']],
        ], $request->user()?->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Avisos automáticos guardados.']);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Acceso total: todos los comunicados de la organización. Si no, los propios.
     *
     * @return Builder<Announcement>
     */
    private function visible(User $user): Builder
    {
        return Announcement::query()->when(! $this->scope->hasFullAccess($user), fn (Builder $q) => $q->where('created_by', $user->id));
    }

    private function find(User $user, int $id): Announcement
    {
        /** @var Announcement */
        return $this->visible($user)->findOrFail($id);
    }

    /**
     * @param  array{total:int, delivered:int, read:int, push_sent:int, failed:int}|null  $stats
     * @return array<string, mixed>
     */
    private function row(Announcement $a, ?array $stats): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'excerpt' => mb_strimwidth($a->body, 0, 90, '…'),
            'priority' => $a->priority,
            'audience_type' => $a->audience_type,
            'audience_label' => self::AUDIENCE_LABELS[$a->audience_type] ?? $a->audience_type,
            'audience_count' => count((array) ($a->audience_ids ?? [])),
            'status' => $a->isWithdrawn() ? 'withdrawn' : ($a->status === 'scheduled' ? 'scheduled' : 'sent'),
            'scheduled_at' => $a->scheduled_at?->toIso8601String(),
            'sent_at' => $a->sent_at?->toIso8601String(),
            'withdrawn_at' => $a->withdrawn_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'created_by' => $a->createdBy?->name,
            'stats' => $stats ?? ['total' => 0, 'delivered' => 0, 'read' => 0, 'push_sent' => 0, 'failed' => 0],
        ];
    }

    /**
     * @return list<string>
     */
    private function audienceNames(Announcement $a): array
    {
        $ids = (array) ($a->audience_ids ?? []);
        if ($ids === []) {
            return [];
        }

        return match ($a->audience_type) {
            'branches' => Branch::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all(),
            'departments' => Department::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all(),
            'positions' => Position::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all(),
            default => [],
        };
    }

    /**
     * @param  iterable<Model>  $items
     * @return list<array{value:string, label:string, description?:string}>
     */
    private function options(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = ['value' => (string) $item->getKey(), 'label' => (string) $item->getAttribute('name'), 'description' => (string) $item->getAttribute('code')];
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $selected
     * @return list<array{value:string, label:string, description:string}>
     */
    private function employeeOptions(User $user, string $term, array $selected): array
    {
        $term = mb_substr(trim($term), 0, 60);
        $selectedIds = array_values(array_filter(array_map('intval', $selected)));

        $query = $this->scope->employees($user)->where('employees.status', 'active');
        $matches = (clone $query)
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('employee_number', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name, second_last_name) like ?", [$like]));
            })
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(30)
            ->get(['employees.id', 'first_name', 'last_name', 'second_last_name', 'employee_number']);

        $chosen = $selectedIds === [] ? collect() : (clone $query)->whereIn('employees.id', $selectedIds)
            ->get(['employees.id', 'first_name', 'last_name', 'second_last_name', 'employee_number']);

        return $chosen->merge($matches)->unique('id')->values()
            ->map(fn (Employee $e) => ['value' => (string) $e->id, 'label' => $e->fullName(), 'description' => (string) $e->employee_number])
            ->all();
    }

    private function estimate(User $user, Request $request): int
    {
        $type = (string) $request->query('audience_type', 'all');
        if (! in_array($type, Announcement::AUDIENCES, true)) {
            return 0;
        }

        return $this->audience->count($user, $type, array_map('intval', (array) $request->query('audience_ids', [])));
    }
}
