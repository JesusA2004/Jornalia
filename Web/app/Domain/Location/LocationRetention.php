<?php

namespace App\Domain\Location;

use App\Domain\Audit\AuditLogger;
use App\Models\LocationPoint;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Depuración legal de recorridos de ubicación (LFPDPPP: los datos personales se
 * suprimen al concluir la finalidad y el plazo de conservación).
 *
 * ÚNICA excepción documentada a la regla «nada se elimina físicamente»
 * (AGENTS.md regla 11): sólo aplica a `location_points` (recorridos de
 * seguimiento), nunca a marcajes ni a su ubicación de evidencia. El trigger de
 * BD rechaza el DELETE salvo que esta sesión lo habilite explícitamente
 * (`@jornalia_location_purge = 1`), y cada depuración queda en la bitácora con
 * el plazo, la fecha de corte y el número de puntos suprimidos.
 */
final class LocationRetention
{
    private const CHUNK = 5000;

    public function __construct(
        private readonly LocationTracking $tracking,
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * @return array{retention_days:int, cutoff:string, purged:int}
     */
    public function purge(Organization $organization, ?CarbonImmutable $now = null): array
    {
        return $this->current->runAs($organization, function () use ($organization, $now) {
            $days = $this->tracking->settings()['retention_days'];
            $cutoff = ($now ?? CarbonImmutable::now('UTC'))->utc()->subDays($days);

            $purged = 0;
            $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
            try {
                if ($mysql) {
                    DB::statement('SET @jornalia_location_purge = 1');
                }
                do {
                    // Query builder (no Eloquent): NoPhysicalDeletes protege modelos; esta es la excepción legal.
                    $ids = DB::table('location_points')
                        ->where('organization_id', $organization->id)
                        ->where('captured_at', '<', $cutoff)
                        ->orderBy('id')->limit(self::CHUNK)->pluck('id');
                    if ($ids->isEmpty()) {
                        break;
                    }
                    $purged += DB::table('location_points')->whereIn('id', $ids->all())->delete();
                } while ($ids->count() === self::CHUNK);
            } finally {
                if ($mysql) {
                    DB::statement('SET @jornalia_location_purge = NULL');
                }
            }

            if ($purged > 0) {
                $this->audit->log('location.purged', null, "Depuración legal de recorridos: {$purged} punto(s)", [
                    'retention_days' => $days,
                    'cutoff' => $cutoff->format('Y-m-d\TH:i:s\Z'),
                    'purged' => $purged,
                    'basis' => 'LFPDPPP (supresión al concluir la finalidad)',
                ], $organization->id);
            }

            return ['retention_days' => $days, 'cutoff' => $cutoff->format('Y-m-d\TH:i:s\Z'), 'purged' => $purged];
        });
    }

    /**
     * Puntos que se depurarán en la próxima ejecución (para mostrar en Configuración).
     */
    public function pending(): int
    {
        $days = $this->tracking->settings()['retention_days'];

        return LocationPoint::query()->where('captured_at', '<', CarbonImmutable::now('UTC')->subDays($days))->count();
    }
}
