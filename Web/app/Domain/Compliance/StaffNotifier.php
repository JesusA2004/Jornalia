<?php

namespace App\Domain\Compliance;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Avisa al personal administrativo activo de una organización que tenga
 * alguno de los permisos indicados (p. ej. RH y Sistemas).
 */
final class StaffNotifier
{
    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * @param  list<string>  $permissions
     * @return int destinatarios notificados
     */
    public function notify(Organization $organization, array $permissions, AppNotification $notification): int
    {
        return $this->current->runAs($organization, function () use ($organization, $permissions, $notification) {
            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($organization->id);

            $sent = 0;
            try {
                /** @var Collection<int, User> $candidates */
                $candidates = $organization->users()->wherePivot('status', 'active')->get();
                foreach ($candidates as $candidate) {
                    $candidate->unsetRelation('roles')->unsetRelation('permissions');
                    if (! $candidate->canAny($permissions)) {
                        continue;
                    }
                    $candidate->notify((clone $notification)->forOrganization($organization->id));
                    $sent++;
                }
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }

            return $sent;
        });
    }
}
