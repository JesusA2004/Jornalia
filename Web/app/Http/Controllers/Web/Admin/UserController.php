<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\OrganizationProvisioner;
use App\Domain\Security\PermissionCatalog;
use App\Domain\Security\TwoFactorPolicy;
use App\Domain\Security\UserAccessService;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Web\Admin\Concerns\GuardsAdministrators;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessReview;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Usuarios de la organización: vinculación, roles y alcance por sucursal.
 */
class UserController extends AdminController
{
    use GuardsAdministrators, OrganizationRules;

    public const AUTHORITY_ROLE = PermissionCatalog::ROLE_AUTHORITY;

    /** organization_settings.key con {user_id: {expires_on, granted_by, granted_at, revoked_at}}. */
    public const AUTHORITY_SETTING = UserAccessService::AUTHORITY_SETTING;

    /** Sólo storeAuthority puede otorgar el rol de autoridad laboral (siempre con vigencia). */
    private bool $grantingAuthority = false;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserAccessService $access,
        private readonly TwoFactorPolicy $twoFactor,
    ) {}

    public function index(Request $request): Response
    {
        $org = $this->organizationId();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'role' => ['nullable', 'string', 'max:125'],
        ]);

        $users = User::query()
            ->join('organization_user as ou', fn ($j) => $j->on('ou.user_id', '=', 'users.id')->where('ou.organization_id', $org))
            ->select('users.*', 'ou.status as membership_status', 'ou.access_all_branches', 'ou.created_at as linked_at')
            ->with([
                'roles' => fn ($q) => $q->where('roles.organization_id', $org)->with('permissions:id,name'),
                'branches' => fn ($q) => $q->where('branch_user.organization_id', $org),
            ])
            ->when($filters['search'] ?? null, function ($q, $t) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%';
                $q->where(fn ($w) => $w->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('ou.status', $s))
            ->when($filters['role'] ?? null, fn ($q, $r) => $q->whereHas('roles', fn ($w) => $w->where('roles.organization_id', $org)->where('name', $r)))
            ->orderBy('users.name')
            ->paginate(25)->withQueryString();

        $employees = Employee::query()->whereIn('user_id', collect($users->items())->pluck('id'))->get(['id', 'user_id', 'first_name', 'last_name', 'second_last_name', 'employee_number'])->keyBy('user_id');

        $authority = (array) (OrganizationSetting::query()->where('key', self::AUTHORITY_SETTING)->first()?->value ?? []);

        $users->through(fn (User $u) => [
            'authority_expires_on' => $authority[(string) $u->id]['expires_on'] ?? null,
            'authority_revoked_at' => $authority[(string) $u->id]['revoked_at'] ?? null,
            'two_factor_required' => $this->twoFactor->enforced() && PermissionCatalog::requiresTwoFactor(
                $u->roles->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->all()
            ),
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'account_status' => $u->status,
            'status' => $u->getAttribute('membership_status'),
            'access_all_branches' => (bool) $u->getAttribute('access_all_branches'),
            'branch_ids' => $u->branches->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'branches' => $u->branches->pluck('name')->values(),
            'roles' => $u->roles->pluck('name')->values(),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'two_factor' => filled($u->getAttribute('two_factor_confirmed_at')),
            'is_self' => $u->id === $request->user()?->id,
            'employee' => ($e = $employees->get($u->id)) ? ['id' => $e->id, 'name' => $e->fullName(), 'employee_number' => $e->employee_number] : null,
        ]);

        return Inertia::render('usuarios/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::query()->where('organization_id', $org)->orderBy('name')->pluck('name')->map(fn ($n) => ['value' => $n, 'label' => $n])->values(),
            'branches' => $this->options(Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])),
            'employees_without_account' => Employee::query()->whereNull('user_id')->where('status', 'active')->orderBy('last_name')->limit(2000)
                ->get(['id', 'first_name', 'last_name', 'second_last_name', 'employee_number', 'email'])
                ->map(fn (Employee $e) => ['value' => (string) $e->id, 'label' => $e->fullName(), 'description' => $e->employee_number, 'email' => $e->email])->values(),
            'can' => ['manage' => $request->user()?->can('users.manage') ?? false, 'roles' => $request->user()?->can('users.roles') ?? false],
            'flash_password' => $request->session()->get('temporary_password'),
            'scoped_roles' => PermissionCatalog::BRANCH_SCOPED_ROLES,
            'authority_max_days' => UserAccessService::AUTHORITY_MAX_DAYS,
            'open_review' => ($review = UserAccessReview::query()->where('status', 'open')->latest('id')->first())
                ? ['id' => $review->id, 'period' => $review->period, 'due_on' => $review->due_on->toDateString()]
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $org = $this->organizationId();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mode' => ['required', Rule::in(['password', 'reset'])],
            ...$this->accessRules($org),
            'employee_id' => ['nullable', 'integer', $this->existsInOrg('employees')],
        ], [], $this->labels());

        $this->assertCanGrant($this->actor($request), $data['roles'], $org);
        $this->assertRoleRules($data, $org);
        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing !== null && $existing->organizations()->where('organizations.id', $org)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ese usuario ya pertenece a la organización.']);
        }
        if ($existing !== null && $data['mode'] === 'password') {
            throw ValidationException::withMessages(['mode' => 'El correo ya tiene cuenta: se vinculará con su contraseña actual. Elige «Enviar enlace» si necesita restablecerla.']);
        }

        $employee = ! empty($data['employee_id']) ? Employee::query()->find($data['employee_id']) : null;
        if ($employee?->user_id !== null) {
            throw ValidationException::withMessages(['employee_id' => 'Ese colaborador ya tiene una cuenta vinculada.']);
        }
        if ($existing !== null && $employee !== null && Employee::query()->withoutGlobalScopes()->where('user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'El usuario ya está vinculado a otro expediente.']);
        }

        $temporary = null;
        $user = DB::transaction(function () use ($data, $existing, $org, $employee, &$temporary) {
            $user = $existing;
            if ($user === null) {
                $temporary = $data['mode'] === 'password' ? Str::password(16) : null;
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $temporary ?? Str::password(40),
                    'status' => 'active',
                    'current_organization_id' => $org,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $user->organizations()->syncWithoutDetaching([$org => ['status' => 'active', 'access_all_branches' => (bool) $data['access_all_branches']]]);
            $this->syncAccess($user, $org, $data);
            $employee?->forceFill(['user_id' => $user->id])->save();

            return $user;
        });

        $sent = $data['mode'] === 'reset' && Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;

        $this->audit->log('user.linked', $user, ($existing ? 'Usuario existente vinculado: ' : 'Usuario creado: ').$user->email, [
            'roles' => $data['roles'], 'access_all_branches' => (bool) $data['access_all_branches'], 'branch_ids' => $data['branch_ids'] ?? [],
            'employee_id' => $employee?->id, 'mode' => $data['mode'], 'reset_link_sent' => $sent,
        ]);

        if ($temporary !== null) {
            $request->session()->flash('temporary_password', ['email' => $user->email, 'password' => $temporary]);
            $this->toast('Usuario creado. Comparte la contraseña temporal de forma segura: no volverá a mostrarse.');
        } else {
            $this->toast($sent ? 'Usuario vinculado. Se envió el enlace para definir contraseña.' : 'Usuario vinculado a la organización.');
        }

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $org = $this->organizationId();
        $this->ensureMember($user, $org);
        $actor = $this->actor($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            ...$this->accessRules($org),
        ], [], $this->labels());

        $this->assertCanGrant($actor, $data['roles'], $org);
        $this->assertCanGrant($actor, $user->roles()->where('roles.organization_id', $org)->pluck('name')->all(), $org, 'No puedes modificar a un usuario con permisos superiores a los tuyos.');
        if ($user->id === $actor->id && $data['status'] !== 'active') {
            throw ValidationException::withMessages(['status' => 'No puedes desactivar tu propio acceso.']);
        }
        $currentRoles = $user->roles()->where('roles.organization_id', $org)->pluck('name')->all();
        // El rol de autoridad laboral sólo se otorga con vigencia (storeAuthority); aquí puede conservarse o retirarse.
        $this->grantingAuthority = in_array(self::AUTHORITY_ROLE, $currentRoles, true);
        $this->assertRoleRules($data, $org);

        $membership = DB::table('organization_user')->where('organization_id', $org)->where('user_id', $user->id)->first();
        $before = [
            'roles' => $currentRoles,
            'status' => $membership?->status,
            'access_all_branches' => (bool) $membership?->access_all_branches,
            'branch_ids' => DB::table('branch_user')->where('organization_id', $org)->where('user_id', $user->id)->pluck('branch_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
        $snapshot = $this->adminSnapshot($actor, $org);

        DB::transaction(function () use ($user, $org, $data, $actor, $snapshot) {
            DB::table('organization_user')->where('organization_id', $org)->where('user_id', $user->id)
                ->update(['status' => $data['status'], 'access_all_branches' => (bool) $data['access_all_branches'], 'updated_at' => now()]);
            $this->syncAccess($user, $org, $data);
            $this->assertAdministratorsRemain($actor, $org, $snapshot);
        });

        if ($data['status'] !== 'active') {
            $user->tokens()->delete();
        }

        $after = [
            'roles' => array_values($data['roles']),
            'status' => $data['status'],
            'access_all_branches' => (bool) $data['access_all_branches'],
            'branch_ids' => $data['access_all_branches'] ? [] : collect($data['branch_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
        // Bitácora con valor anterior y nuevo (PDF regla general 5).
        $this->audit->log('user.access_updated', $user, 'Accesos actualizados: '.$user->email, [
            'before' => $before, 'after' => $after,
            'roles_before' => $before['roles'], 'roles_after' => $after['roles'],
        ]);
        $this->toast('Accesos actualizados.');

        return back();
    }

    /**
     * Desvincula (desactiva) el acceso a la organización. No se borra nada:
     * la pertenencia queda inactiva y puede reactivarse.
     */
    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $org = $this->organizationId();
        $this->ensureMember($user, $org);
        $actor = $this->actor($request);
        if ($user->id === $actor->id) {
            throw ValidationException::withMessages(['user' => 'No puedes desactivar tu propio acceso.']);
        }
        $this->assertCanGrant($actor, $user->roles()->where('roles.organization_id', $org)->pluck('name')->all(), $org, 'No puedes desactivar a un usuario con permisos superiores a los tuyos.');

        $snapshot = $this->adminSnapshot($actor, $org);
        DB::transaction(function () use ($user, $org, $actor, $snapshot) {
            $this->access->deactivate($user, $org, 'Desactivación manual', $actor);
            $this->assertAdministratorsRemain($actor, $org, $snapshot);
        });

        $this->toast('Acceso desactivado. Su historial y bitácora se conservan.');

        return back();
    }

    public function restore(Request $request, User $user): RedirectResponse
    {
        $org = $this->organizationId();
        $this->ensureMember($user, $org);
        $roles = $user->roles()->where('roles.organization_id', $org)->pluck('name')->all();
        $this->assertCanGrant($this->actor($request), $roles, $org, 'No puedes reactivar a un usuario con permisos superiores a los tuyos.');

        if (Employee::query()->where('user_id', $user->id)->where('status', EmployeeStatus::Terminated)->exists()) {
            throw ValidationException::withMessages(['user' => 'El colaborador está dado de baja: su acceso no puede reactivarse.']);
        }
        if (in_array(self::AUTHORITY_ROLE, $roles, true)) {
            $grant = $this->access->authorityGrants($org)[(string) $user->id] ?? null;
            if ($grant === null || ($grant['revoked_at'] ?? null) !== null || (string) ($grant['expires_on'] ?? '') < now()->toDateString()) {
                throw ValidationException::withMessages(['user' => 'Otorga una nueva vigencia desde «Acceso para autoridad laboral».']);
            }
        }

        DB::table('organization_user')->where('organization_id', $org)->where('user_id', $user->id)->update(['status' => 'active', 'updated_at' => now()]);
        $this->audit->log('user.restored', $user, 'Acceso reactivado en la organización: '.$user->email, ['before' => ['status' => 'inactive'], 'after' => ['status' => 'active']]);
        $this->toast('Acceso reactivado.');

        return back();
    }

    /**
     * Acceso de sólo lectura para la autoridad laboral (inspección).
     */
    public function storeAuthority(Request $request, OrganizationProvisioner $provisioner): RedirectResponse
    {
        $org = $this->organizationId();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // Vigencia obligatoria (sólo lectura temporal para una inspección).
            'expires_on' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(UserAccessService::AUTHORITY_MAX_DAYS)->toDateString()],
            'mode' => ['required', Rule::in(['password', 'reset'])],
        ], [], ['name' => 'nombre', 'email' => 'correo', 'expires_on' => 'vigencia', 'mode' => 'método de acceso']);

        if (! Role::query()->where('organization_id', $org)->where('name', self::AUTHORITY_ROLE)->exists()) {
            $provisioner->syncRoles(Organization::query()->findOrFail($org));
        }

        // Nueva vigencia para una autoridad ya registrada (vencida o revocada).
        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing !== null && DB::table('organization_user')->where('organization_id', $org)->where('user_id', $existing->id)->exists()) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org);
            if (! $existing->hasRole(self::AUTHORITY_ROLE) || $existing->roles()->where('roles.organization_id', $org)->count() > 1) {
                throw ValidationException::withMessages(['email' => 'Ese correo ya tiene otro acceso en la organización.']);
            }
            DB::table('organization_user')->where('organization_id', $org)->where('user_id', $existing->id)->update(['status' => 'active', 'updated_at' => now()]);
            $this->access->grantAuthority($existing, $org, CarbonImmutable::parse($data['expires_on'])->toDateString(), $this->actor($request));
            $this->toast('Vigencia de autoridad laboral renovada.');

            return back();
        }

        $request->merge(['roles' => [self::AUTHORITY_ROLE], 'access_all_branches' => true, 'branch_ids' => [], 'employee_id' => null]);
        $this->grantingAuthority = true;
        $response = $this->store($request);

        $user = User::query()->where('email', $data['email'])->first();
        if ($user !== null) {
            $this->access->grantAuthority($user, $org, CarbonImmutable::parse($data['expires_on'])->toDateString(), $this->actor($request));
        }

        return $response;
    }

    /**
     * Revoca de inmediato el acceso de autoridad laboral (antes del fin de su vigencia).
     */
    public function revokeAuthority(Request $request, User $user): RedirectResponse
    {
        $org = $this->organizationId();
        $this->ensureMember($user, $org);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org);
        abort_unless($user->hasRole(self::AUTHORITY_ROLE), 404);

        $this->access->revokeAuthority($user, $org, $this->actor($request), $data['reason']);
        $this->toast('Acceso de autoridad laboral revocado.');

        return back();
    }

    public function reset(Request $request, User $user): RedirectResponse
    {
        $this->ensureMember($user, $this->organizationId());
        $sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        $this->audit->log('user.password_reset_sent', $user, 'Enlace de restablecimiento enviado: '.$user->email, ['sent' => $sent]);
        $this->toast($sent ? 'Enlace de restablecimiento enviado.' : 'No fue posible enviar el enlace (espera unos minutos antes de reintentar).', $sent ? 'success' : 'warning');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function accessRules(int $org): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('organization_id', $org)],
            'access_all_branches' => ['required', 'boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', $this->existsInOrg('branches')],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        return ['name' => 'nombre', 'email' => 'correo', 'mode' => 'método de acceso', 'roles' => 'roles', 'access_all_branches' => 'alcance', 'branch_ids' => 'sucursales', 'employee_id' => 'colaborador', 'status' => 'estado'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAccess(User $user, int $org, array $data): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($org);
        $user->unsetRelation('roles')->syncRoles($data['roles']);

        $branchIds = $data['access_all_branches'] ? [] : array_map('intval', $data['branch_ids'] ?? []);
        DB::table('branch_user')->where('organization_id', $org)->where('user_id', $user->id)->whereNotIn('branch_id', $branchIds ?: [0])->delete();
        $existing = DB::table('branch_user')->where('organization_id', $org)->where('user_id', $user->id)->pluck('branch_id')->all();
        foreach (array_diff($branchIds, $existing) as $branchId) {
            DB::table('branch_user')->insert(['organization_id' => $org, 'branch_id' => $branchId, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $registrar->forgetCachedPermissions();
    }

    /**
     * Sin `users.roles`, sólo pueden otorgarse roles cuyos permisos ya tiene quien los otorga (evita escalamiento).
     *
     * @param  list<string>  $roles
     */
    private function assertCanGrant(User $actor, array $roles, int $org, string $message = 'No puedes otorgar roles con permisos que tú no tienes.'): void
    {
        if ($actor->is_super_admin || $actor->can('users.roles') || $roles === []) {
            return;
        }
        $granted = Role::query()->where('organization_id', $org)->whereIn('name', $roles)->with('permissions:id,name')->get()
            ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique();
        $own = $actor->getAllPermissions()->pluck('name');
        if ($granted->diff($own)->isNotEmpty()) {
            throw ValidationException::withMessages(['roles' => $message]);
        }
    }

    /**
     * Reglas de asignación de roles (PDF pantalla 18):
     *  - Separación de funciones: administrar usuarios/roles y autorizar o rectificar asistencia no se combinan.
     *  - Gerentes y responsables regionales requieren alcance por sucursal (nunca «todas»).
     *  - El rol de autoridad laboral sólo se otorga con vigencia (flujo dedicado).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRoleRules(array $data, int $org): void
    {
        /** @var list<string> $roles */
        $roles = array_values($data['roles'] ?? []);

        if (in_array(self::AUTHORITY_ROLE, $roles, true) && ! $this->grantingAuthority) {
            throw ValidationException::withMessages(['roles' => 'La autoridad laboral se otorga desde «Acceso para autoridad laboral», con vigencia.']);
        }

        $permissions = Role::query()->where('organization_id', $org)->whereIn('name', $roles)->with('permissions:id,name')->get()
            ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique()->values()->all();
        $conflicts = PermissionCatalog::segregationConflicts($permissions);
        if ($conflicts !== []) {
            throw ValidationException::withMessages(['roles' => 'Separación de funciones: quien administra usuarios no puede autorizar, validar ni rectificar asistencia ('.implode(', ', array_slice($conflicts, 0, 4)).').']);
        }

        $scoped = array_values(array_intersect($roles, PermissionCatalog::BRANCH_SCOPED_ROLES));
        if ($scoped !== [] && ((bool) ($data['access_all_branches'] ?? false) || empty($data['branch_ids']))) {
            throw ValidationException::withMessages(['branch_ids' => '«'.implode('», «', $scoped).'» requiere elegir sus sucursales (no «todas»).']);
        }
    }

    private function ensureMember(User $user, int $org): void
    {
        abort_unless(DB::table('organization_user')->where('organization_id', $org)->where('user_id', $user->id)->exists(), 404);
    }
}
