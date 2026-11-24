<?php

namespace App\Console\Commands;

use App\Domain\Location\LocationRetention;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Depuración legal programada de recorridos de ubicación (LFPDPPP). Ver LocationRetention.
 */
#[Signature('ubicacion:depurar {--organizacion= : ID de organización (todas si se omite)}')]
#[Description('Suprime los recorridos de ubicación que superaron el plazo de conservación configurado')]
final class LocationPurgeCommand extends Command
{
    public function handle(LocationRetention $retention): int
    {
        $orgs = Organization::query()
            ->when($this->option('organizacion'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($orgs as $org) {
            $r = $retention->purge($org);
            $this->info("{$org->name}: {$r['purged']} punto(s) depurado(s) (plazo {$r['retention_days']} días, corte {$r['cutoff']}).");
        }

        return self::SUCCESS;
    }
}
