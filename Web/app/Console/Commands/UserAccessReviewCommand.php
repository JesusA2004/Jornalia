<?php

namespace App\Console\Commands;

use App\Domain\Security\UserAccessService;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/**
 * Proceso diario de accesos (PDF pantalla 18, punto 4):
 *  - desactiva accesos de autoridad laboral con vigencia vencida;
 *  - abre (una vez por trimestre) la revisión de usuarios activos con alerta en Cumplimiento.
 * Idempotente.
 */
class UserAccessReviewCommand extends Command
{
    protected $signature = 'asistencias:revision-usuarios';

    protected $description = 'Vence accesos de autoridad laboral y abre la revisión trimestral de usuarios';

    public function handle(UserAccessService $access, CurrentOrganization $current): int
    {
        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            $current->runAs($organization, function () use ($access, $organization) {
                $expired = $access->expireAuthorities($organization);
                $review = $access->openQuarterlyReview($organization);
                $this->line("{$organization->name}: revisión {$review->period} ({$review->status}); autoridades vencidas desactivadas: {$expired}");
            });
        }

        return self::SUCCESS;
    }
}
