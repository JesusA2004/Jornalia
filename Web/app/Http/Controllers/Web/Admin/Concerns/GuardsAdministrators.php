<?php

namespace App\Http\Controllers\Web\Admin\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Evita que una organización se quede sin administradores de roles y que un
 * usuario se quite a sí mismo el último rol administrador.
 */
trait GuardsAdministrators
{
    protected const ADMIN_PERMISSION = 'users.roles';

    protected function adminHolders(int $organizationId, ?int $userId = null): int
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', fn ($j) => $j->on('r.id', '=', 'mr.role_id')->whereNull('r.deleted_at'))
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('organization_user as ou', function ($join) {
                $join->on('ou.user_id', '=', 'mr.model_id')->on('ou.organization_id', '=', 'mr.organization_id');
            })
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.organization_id', $organizationId)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('p.name', self::ADMIN_PERMISSION)
            ->where('ou.status', 'active')
            ->where('u.status', 'active')
            ->when($userId, fn ($q) => $q->where('mr.model_id', $userId))
            ->distinct()
            ->count('mr.model_id');
    }

    /**
     * @return array{actor:int, organization:int}
     */
    protected function adminSnapshot(User $actor, int $organizationId): array
    {
        return ['actor' => $this->adminHolders($organizationId, $actor->id), 'organization' => $this->adminHolders($organizationId)];
    }

    /**
     * Ejecútalo dentro de la transacción, después de aplicar el cambio.
     *
     * @param  array{actor:int, organization:int}  $before
     */
    protected function assertAdministratorsRemain(User $actor, int $organizationId, array $before): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($before['actor'] > 0 && ! $actor->is_super_admin && $this->adminHolders($organizationId, $actor->id) === 0) {
            throw ValidationException::withMessages(['roles' => 'No puedes quitarte a ti mismo el último rol con permiso para administrar roles. Pide a otro administrador que haga el cambio.']);
        }

        if ($before['organization'] > 0 && $this->adminHolders($organizationId) === 0) {
            throw ValidationException::withMessages(['roles' => 'La organización debe conservar al menos un usuario activo con permiso para administrar roles.']);
        }
    }
}
