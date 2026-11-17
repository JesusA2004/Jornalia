<?php

namespace App\Console\Commands;

use App\Domain\Attendance\IntegrityAnchorService;
use App\Domain\Attendance\IntegrityVerifier;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asistencias:integridad {accion : anclar|verificar} {--organizacion= : ID de organización (todas si se omite)}')]
#[Description('Ancla externamente o verifica las cadenas de integridad de marcajes y ajustes')]
final class IntegrityCommand extends Command
{
    public function handle(IntegrityAnchorService $anchors, IntegrityVerifier $verifier): int
    {
        $orgs = Organization::query()
            ->when($this->option('organizacion'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $failed = false;
        foreach ($orgs as $org) {
            if ($this->argument('accion') === 'anclar') {
                $created = $anchors->anchor($org->id);
                $this->info("{$org->name}: ".count($created).' ancla(s) emitida(s).');

                continue;
            }

            foreach (['punches', 'adjustments'] as $chain) {
                $r = $verifier->verify($org->id, $chain);
                $line = "{$org->name} [{$chain}]: {$r['checked']} registros, {$r['anchors_checked']} anclas — ".($r['valid'] ? 'ÍNTEGRA' : 'ALTERADA');
                $r['valid'] ? $this->info($line) : $this->error($line);
                foreach ($r['errors'] as $e) {
                    $this->line("  · secuencia {$e['sequence']}: {$e['error']}");
                }
                $failed = $failed || ! $r['valid'];
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
