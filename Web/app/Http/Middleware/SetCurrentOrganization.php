<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establece la organización activa del usuario autenticado (web y API) y el
 * equipo de permisos de Spatie. Un usuario sólo puede operar en organizaciones
 * a las que pertenece de forma activa.
 */
final class SetCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! $user->isActive()) {
            abort(403, 'Tu cuenta está desactivada.');
        }

        $organization = null;
        if ($user->current_organization_id !== null && ($user->is_super_admin || $user->belongsToOrganization($user->current_organization_id))) {
            $organization = Organization::query()->find($user->current_organization_id);
        }

        if ($organization === null) {
            $organization = $user->organizations()->wherePivot('status', 'active')->orderBy('organizations.id')->first();
            if ($organization !== null) {
                $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
            }
        }

        if ($organization === null && ! $user->is_super_admin) {
            abort(403, 'Tu usuario no está vinculado a ninguna organización activa.');
        }

        if ($organization !== null && ! $user->is_super_admin && $this->authorityAccessExpired($user, $organization)) {
            abort(403, 'Tu acceso de autoridad laboral venció. Solicita una nueva vigencia a la empresa.');
        }

        $this->current->set($organization);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization?->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }

    /**
     * Accesos de autoridad laboral con vigencia (organization_settings.authority_access).
     */
    private function authorityAccessExpired(User $user, Organization $organization): bool
    {
        $setting = OrganizationSetting::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('key', 'authority_access')
            ->value('value');
        $access = is_string($setting) ? json_decode($setting, true) : $setting;
        $expiresOn = is_array($access) ? ($access[(string) $user->id]['expires_on'] ?? null) : null;

        return $expiresOn !== null && now($organization->timezone)->toDateString() > (string) $expiresOn;
    }
}
