<?php

namespace App\Console\Commands;

use App\Domain\Organization\OfficialCompanyData;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asistencias:datos-oficiales {--organizacion= : ID de organización (obligatorio si hay varias)} {--forzar : Sobrescribe valores capturados distintos (queda en bitácora)} {--simular : Sólo muestra lo que cambiaría}')]
#[Description('Carga razón social, RFC, domicilio fiscal y nombre comercial oficiales de Mr. Lana (idempotente)')]
final class OfficialDataCommand extends Command
{
    public function handle(OfficialCompanyData $data): int
    {
        $query = Organization::query()->when($this->option('organizacion'), fn ($q, $id) => $q->whereKey($id));
        $organizations = $query->get();

        if ($organizations->isEmpty()) {
            $this->error('No hay organizaciones que actualizar.');

            return self::FAILURE;
        }
        if ($organizations->count() > 1 && ! $this->option('organizacion')) {
            $this->error('Hay varias organizaciones: indica --organizacion=ID.');
            foreach ($organizations as $org) {
                $this->line("  {$org->id}: {$org->name}");
            }

            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($organizations as $org) {
            $result = $data->apply($org, (bool) $this->option('forzar'), (bool) $this->option('simular'));
            $this->info("{$org->id} · {$result['message']}");
            foreach ($result['changes'] as $c) {
                $this->line("  {$c['field']}: «".($c['before'] ?? '—')."» → «{$c['after']}»");
            }
            foreach ($result['conflicts'] as $c) {
                $this->warn("  {$c['field']}: capturado «{$c['current']}» ≠ oficial «{$c['official']}»");
            }
            if (in_array($result['status'], ['conflict', 'skipped'], true)) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
