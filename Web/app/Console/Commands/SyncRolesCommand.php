<?php

namespace App\Console\Commands;

use App\Domain\Security\RoleStructure;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Alinea (idempotente) los roles de cada organización a la estructura mínima y
 * reporta conflictos de separación de funciones.
 */
class SyncRolesCommand extends Command
{
    protected $signature = 'asistencias:roles {--organizacion= : ID de una organización}';

    protected $description = 'Alinea los roles mínimos (pantalla 18) sin perder asignaciones';

    public function handle(RoleStructure $structure): int
    {
        $query = Organization::query()->orderBy('id');
        if ($this->option('organizacion')) {
            $query->whereKey((int) $this->option('organizacion'));
        }

        foreach ($query->get() as $organization) {
            $changes = $structure->apply($organization);
            $this->info("{$organization->name}: ".($changes === [] ? 'sin cambios' : implode('; ', $changes)));
            foreach ($structure->segregationConflicts($organization) as $conflict) {
                $this->warn("  Usuario #{$conflict['user_id']} combina administración de usuarios con: ".implode(', ', $conflict['conflicts']));
            }
        }

        return self::SUCCESS;
    }
}
