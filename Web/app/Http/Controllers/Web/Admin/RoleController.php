<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\PermissionCatalog;
use App\Http\Controllers\Web\Admin\Concerns\GuardsAdministrators;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles configurables por organización con matriz de permisos por módulo/acción.
 */
class RoleController extends AdminController
{
    use GuardsAdministrators;

    /** Roles que el sistema usa por nombre: no se renombran ni eliminan. */
    private const PROTECTED = ['Administrador de empresa', 'Colaborador'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $org = $this->organizationId();
        $counts = DB::table('model_has_roles')->where('organization_id', $org)->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        $roles = Role::query()->withTrashed()->where('organization_id', $org)->with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'permissions' => $r->permissions->pluck('name')->values(),
                'users_count' => (int) ($counts[$r->id] ?? 0),
                'protected' => in_array($r->name, self::PROTECTED, true),
                'is_default' => array_key_exists($r->name, PermissionCatalog::defaultRoles()),
                'deleted' => $r->trashed(),
            ]);

        $modules = [];
        foreach (PermissionCatalog::MODULES as $key => $def) {
            $modules[] = [
                'key' => $key,
                'label' => $def['label'],
                'actions' => array_map(fn ($a, $l) => ['key' => "{$key}.{$a}", 'action' => $a, 'label' => $l], array_keys($def['actions']), $def['actions']),
            ];
        }

        return Inertia::render('usuarios/Roles', [
            'roles' => $roles,
            'modules' => $modules,
            'can' => ['manage' => $request->user()?->can('users.roles') ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $org = $this->organizationId();
        $data = $this->validated($request, $org, null);

        $role = DB::transaction(function () use ($data, $org) {
            $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web', 'organization_id' => $org]);
            $role->syncPermissions($this->permissions($data['permissions']));

            return $role;
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit->log('role.created', $role, "Rol creado: {$role->name}", ['permissions' => $data['permissions']]);
        $this->toast("Rol «{$role->name}» creado.");

        return back();
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        $org = $this->organizationId();
        $model = $this->find($role, $org);
        $data = $this->validated($request, $org, $model);
        $actor = $this->actor($request);

        if (in_array($model->name, self::PROTECTED, true) && $data['name'] !== $model->name) {
            throw ValidationException::withMessages(['name' => 'Este rol lo usa el sistema y no puede renombrarse.']);
        }

        $before = $model->permissions()->pluck('name')->all();
        $snapshot = $this->adminSnapshot($actor, $org);

        DB::transaction(function () use ($model, $data, $actor, $org, $snapshot) {
            $model->update(['name' => $data['name']]);
            $model->syncPermissions($this->permissions($data['permissions']));
            $this->assertAdministratorsRemain($actor, $org, $snapshot);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit->log('role.updated', $model, "Rol actualizado: {$model->name}", [
            'added' => array_values(array_diff($data['permissions'], $before)),
            'removed' => array_values(array_diff($before, $data['permissions'])),
        ]);
        $this->toast("Permisos de «{$model->name}» actualizados.");

        return back();
    }

    public function deactivate(Request $request, int $role): RedirectResponse
    {
        $org = $this->organizationId();
        $model = $this->find($role, $org);

        if (in_array($model->name, self::PROTECTED, true)) {
            throw ValidationException::withMessages(['role' => 'Este rol lo usa el sistema y no puede desactivarse.']);
        }
        if (DB::table('model_has_roles')->where('role_id', $model->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'El rol tiene usuarios asignados. Reasígnalos antes de desactivarlo.']);
        }

        $model->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->audit->log('role.deactivated', $model, "Rol desactivado: {$model->name}", ['permissions' => $model->permissions()->pluck('name')->all()]);
        $this->toast('Rol desactivado. Puedes reactivarlo cuando lo necesites.');

        return back();
    }

    public function restore(Request $request, int $role): RedirectResponse
    {
        $org = $this->organizationId();
        $model = $this->find($role, $org, true);
        $model->restore();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->audit->log('role.restored', $model, "Rol reactivado: {$model->name}");
        $this->toast("Rol «{$model->name}» reactivado.");

        return back();
    }

    /**
     * @return array{name:string, permissions:list<string>}
     */
    private function validated(Request $request, int $org, ?Role $role): array
    {
        /** @var array{name:string, permissions:list<string>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:125', Rule::unique('roles', 'name')->where('organization_id', $org)->where('guard_name', 'web')->ignore($role?->id)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ], ['name.unique' => 'Ya existe un rol con ese nombre.'], ['name' => 'nombre', 'permissions' => 'permisos']);
        $data['name'] = trim($data['name']);
        $data['permissions'] = array_values(array_unique($data['permissions']));

        return $data;
    }

    /**
     * @param  list<string>  $names
     * @return list<Permission>
     */
    private function permissions(array $names): array
    {
        return array_map(fn (string $n) => Permission::findOrCreate($n, 'web'), $names);
    }

    private function find(int $id, int $org, bool $withTrashed = false): Role
    {
        /** @var Role $role */
        $role = Role::query()->when($withTrashed, fn ($q) => $q->withTrashed())->where('organization_id', $org)->findOrFail($id);

        return $role;
    }
}
