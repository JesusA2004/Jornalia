<?php

namespace App\Console\Commands;

use App\Domain\Incidences\IncidenceCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('incidencias:catalogo')]
#[Description('Ajusta el catálogo cerrado de incidencias en todas las organizaciones (idempotente; desactiva, no borra)')]
final class SyncIncidenceCatalogCommand extends Command
{
    public function handle(): int
    {
        IncidenceCatalog::syncAllOrganizations();
        $this->info('Catálogo de incidencias ajustado.');

        return self::SUCCESS;
    }
}
