<?php

namespace App\Console\Commands;

use App\Domain\Compliance\OmissionMonitor;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Alerta en Cumplimiento las omisiones de checada sin rectificar por más de 2 días.
 */
#[Signature('cumplimiento:omisiones {--organizacion= : ID de organización (todas si se omite)}')]
#[Description('Genera o cierra alertas de omisiones de checada sin rectificar por más de 2 días')]
final class UnrectifiedOmissionsCommand extends Command
{
    public function handle(OmissionMonitor $monitor): int
    {
        $orgs = Organization::query()
            ->when($this->option('organizacion'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($orgs as $org) {
            $r = $monitor->evaluate($org);
            $this->info("{$org->name}: {$r['opened']} alerta(s) abierta(s), {$r['resolved']} cerrada(s).");
        }

        return self::SUCCESS;
    }
}
