<?php

namespace App\Console\Commands;

use App\Domain\Integrations\People\HttpPeopleIncidenceSource;
use App\Domain\Integrations\People\PeopleIncidenceReceiver;
use App\Models\IntegrationRun;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('people:incidencias {--organizacion= : ID de organización (por defecto, las que tienen colaboradores vinculados a People)}')]
#[Description('Recibe de Mr. Lana People las incidencias, permisos, incapacidades y vacaciones aprobados (idempotente)')]
final class PeopleIncidencesCommand extends Command
{
    public function handle(PeopleIncidenceReceiver $receiver, HttpPeopleIncidenceSource $source, CurrentOrganization $current): int
    {
        if (! $source->isConfigured()) {
            $this->warn('People sin configurar (PEOPLE_API_URL / PEOPLE_API_TOKEN): no se ejecutó nada.');

            return self::SUCCESS;
        }

        $orgs = Organization::query()
            ->when($this->option('organizacion'), fn ($q, $id) => $q->whereKey($id),
                fn ($q) => $q->whereIn('id', fn ($s) => $s->select('organization_id')->from('employees')->where('external_source', 'people')))
            ->get();

        foreach ($orgs as $org) {
            $run = $current->runAs($org, fn () => $receiver->run($source));
            if (! $run instanceof IntegrationRun) {
                continue;
            }
            /** @var array<string, int> $stats */
            $stats = (array) $run->stats;
            $this->line("{$org->name}: {$run->status} · nuevas ".($stats['created'] ?? 0).', canceladas '.($stats['cancelled'] ?? 0).', errores '.($stats['errors'] ?? 0));
        }

        return self::SUCCESS;
    }
}
