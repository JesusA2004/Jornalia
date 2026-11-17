<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Spatie\Permission\PermissionRegistrar;

/**
 * Determina la organización activa al iniciar sesión (misma regla que el
 * middleware `SetCurrentOrganization`) y la fija en el contexto.
 */
final class OrganizationResolver
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function resolveAndActivate(User $user): ?Organization
    {
        $organization = null;
        if ($user->current_organization_id !== null && $user->belongsToOrganization($user->current_organization_id)) {
            $organization = Organization::query()->find($user->current_organization_id);
        }

        if ($organization === null) {
            $organization = $user->organizations()->wherePivot('status', 'active')->orderBy('organizations.id')->first();
            if ($organization !== null) {
                $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
            }
        }

        if ($organization !== null) {
            $this->current->set($organization);
            app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        }

        return $organization;
    }
}
