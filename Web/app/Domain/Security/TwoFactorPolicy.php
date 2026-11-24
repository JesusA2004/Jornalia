<?php

namespace App\Domain\Security;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Doble factor obligatorio para roles administrativos (PDF pantalla 18, punto 2).
 * Se exige a quien tenga algún permiso de PermissionCatalog::TWO_FACTOR_REQUIRED
 * en la organización activa, y siempre al superadministrador.
 * Desactivable sólo por configuración (`security.admin_two_factor=false`).
 */
final class TwoFactorPolicy
{
    public function enforced(): bool
    {
        return (bool) config('security.admin_two_factor', true);
    }

    public function requiredFor(User $user): bool
    {
        if (! $this->enforced()) {
            return false;
        }
        if ($user->is_super_admin) {
            return true;
        }

        // Fuera del middleware `organization` (p. ej. rutas de Fortify) se usa la organización activa del usuario.
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        if ($previous === null && $user->current_organization_id !== null) {
            $registrar->setPermissionsTeamId($user->current_organization_id);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        try {
            return PermissionCatalog::requiresTwoFactor($user->getAllPermissions()->pluck('name')->all());
        } finally {
            if ($previous === null) {
                $registrar->setPermissionsTeamId(null);
            }
        }
    }

    public function isConfigured(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    public function missingFor(User $user): bool
    {
        return $this->requiredFor($user) && ! $this->isConfigured($user);
    }
}
