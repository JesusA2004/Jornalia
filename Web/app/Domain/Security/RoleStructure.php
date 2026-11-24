<?php

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lleva los roles de una organización a la estructura mínima del PDF (pantalla 18)
 * sin perder asignaciones. Idempotente: puede ejecutarse en cada despliegue.
 *
 *  1. Crea los permisos del catálogo que falten.
 *  2. Renombra los roles anteriores a su equivalente (PermissionCatalog::LEGACY_ROLES);
 *     si el equivalente ya existe, mueve las asignaciones y da de baja lógica al anterior.
 *  3. Crea los roles mínimos que falten con sus permisos por omisión.
 *  4. En roles existentes agrega los permisos por omisión faltantes y retira los
 *     prohibidos: el administrador de plataforma nunca conserva permisos operativos y
 *     los roles de consulta (Contraloría, Autoridad laboral) sólo conservan lectura.
 *     Los permisos adicionales configurados por la empresa en otros roles se conservan.
 *
 * Cada cambio queda en la bitácora con valor anterior y nuevo.
 */
final class RoleStructure
{
    /** Roles de sólo consulta. */
    private const READ_ONLY_ROLES = [PermissionCatalog::ROLE_COMPTROLLER, PermissionCatalog::ROLE_AUTHORITY];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<string> descripción de los cambios aplicados
     */
    public function apply(Organization $organization): array
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organization->id);
        $changes = [];

        try {
            DB::transaction(function () use ($organization, &$changes) {
                $changes = [...$this->migrateLegacy($organization), ...$this->syncDefaults($organization)];
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function migrateLegacy(Organization $organization): array
    {
        $changes = [];
        foreach (PermissionCatalog::LEGACY_ROLES as $legacyName => $targetName) {
            $legacy = Role::query()->where('organization_id', $organization->id)->where('name', $legacyName)->first();
            if ($legacy === null) {
                continue;
            }
            $target = Role::query()->withTrashed()->where('organization_id', $organization->id)->where('name', $targetName)->first();

            if ($target === null) {
                $legacy->forceFill(['name' => $targetName])->save();
                $this->audit->log('role.renamed', $legacy, "Rol «{$legacyName}» ahora es «{$targetName}»", ['before' => $legacyName, 'after' => $targetName], $organization->id);
                $changes[] = "{$legacyName} → {$targetName} (renombrado)";

                continue;
            }

            if ($target->trashed()) {
                $target->restore();
            }
            $holders = DB::table('model_has_roles')->where('role_id', $legacy->id)->get();
            foreach ($holders as $row) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $target->id,
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                    'organization_id' => $row->organization_id,
                ]);
            }
            // Se retira sólo la asignación al rol anterior (pivote); el rol queda con baja lógica.
            DB::table('model_has_roles')->where('role_id', $legacy->id)->delete();
            $legacy->delete();
            $this->audit->log('role.merged', $target, "Asignaciones de «{$legacyName}» movidas a «{$targetName}»", [
                'before' => $legacyName, 'after' => $targetName, 'users' => $holders->pluck('model_id')->all(),
            ], $organization->id);
            $changes[] = "{$legacyName} → {$targetName} (".$holders->count().' asignaciones movidas)';
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function syncDefaults(Organization $organization): array
    {
        $changes = [];
        foreach (PermissionCatalog::defaultRoles() as $roleName => $defaults) {
            $defaults = $defaults === ['*'] ? PermissionCatalog::all() : $defaults;
            $role = Role::query()->withTrashed()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web', 'organization_id' => $organization->id]);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($defaults);
                $this->audit->log('role.created', $role, "Rol mínimo creado: {$roleName}", ['permissions' => $defaults], $organization->id);
                $changes[] = "{$roleName} creado";

                continue;
            }

            $before = $role->permissions()->pluck('name')->all();
            $after = array_values(array_unique([...$before, ...$defaults]));
            if ($roleName === PermissionCatalog::ROLE_PLATFORM_ADMIN) {
                // Separación de funciones: Sistemas administra, no autoriza ni rectifica.
                $after = array_values(array_diff($after, PermissionCatalog::OPERATIONAL));
            }
            if (in_array($roleName, self::READ_ONLY_ROLES, true)) {
                $after = array_values(array_filter($after, fn (string $p) => str_ends_with($p, '.view') || str_ends_with($p, '.export') || $p === 'settings.legal'));
            }
            if (! in_array('locations.view', $defaults, true)) {
                // PDF pantalla 04: la ubicación en tiempo real es dato personal; sólo RH,
                // regional y gerente de sucursal la consultan. Se retira de los demás roles.
                $after = array_values(array_diff($after, ['locations.view']));
            }

            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added === [] && $removed === []) {
                continue;
            }
            $role->syncPermissions($after);
            $this->audit->log('role.permissions_aligned', $role, "Permisos alineados a la estructura mínima: {$roleName}", [
                'before' => $before, 'after' => $after, 'added' => $added, 'removed' => $removed,
            ], $organization->id);
            $changes[] = "{$roleName}: +".count($added).' / -'.count($removed).' permisos';
        }

        return $changes;
    }

    /**
     * Usuarios activos de la organización cuyos roles combinan administración de
     * usuarios con permisos operativos (conflicto de separación de funciones).
     *
     * @return list<array{user_id:int, conflicts:list<string>}>
     */
    public function segregationConflicts(Organization $organization): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organization->id);

        try {
            $result = [];
            $users = User::query()
                ->whereIn('id', DB::table('organization_user')->where('organization_id', $organization->id)->where('status', 'active')->select('user_id'))
                ->get();
            foreach ($users as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $conflicts = PermissionCatalog::segregationConflicts($user->getAllPermissions()->pluck('name')->all());
                if ($conflicts !== []) {
                    $result[] = ['user_id' => $user->id, 'conflicts' => $conflicts];
                }
            }

            return $result;
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
