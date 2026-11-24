<?php

namespace App\Console\Commands;

use App\Domain\Compliance\IntegrityMonitor;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Integridad del registro: anclaje externo y verificación de las cadenas.
 * `diaria` (programada) hace ambas; cualquier falla genera alerta crítica
 * en Cumplimiento y avisa a RH y Sistemas (IntegrityMonitor).
 */
#[Signature('asistencias:integridad {accion : anclar|verificar|diaria} {--organizacion= : ID de organización (todas si se omite)}')]
#[Description('Ancla externamente y/o verifica las cadenas de integridad de marcajes, ajustes y firmas electrónicas')]
final class IntegrityCommand extends Command
{
    public function handle(IntegrityMonitor $monitor): int
    {
        $action = (string) $this->argument('accion');
        if (! in_array($action, ['anclar', 'verificar', 'diaria'], true)) {
            $this->error('Acción no válida: use anclar, verificar o diaria.');

            return self::INVALID;
        }

        $orgs = Organization::query()
            ->when($this->option('organizacion'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $failed = false;
        foreach ($orgs as $org) {
            if ($action === 'anclar' || $action === 'diaria') {
                $r = $monitor->anchor($org, automatic: true);
                $r['ok']
                    ? $this->info("{$org->name}: {$r['created']} ancla(s) emitida(s).")
                    : $this->error("{$org->name}: anclaje fallido — {$r['error']}");
                $failed = $failed || ! $r['ok'];
            }

            if ($action === 'verificar' || $action === 'diaria') {
                $r = $monitor->verify($org, automatic: true);
                foreach ($r['chains'] as $c) {
                    $line = "{$org->name} [{$c['chain']}]: {$c['checked']} registros, {$c['anchors_checked']} anclas — ".($c['valid'] ? 'ÍNTEGRA' : 'ALTERADA');
                    $c['valid'] ? $this->info($line) : $this->error($line);
                    foreach ($c['errors'] as $e) {
                        $this->line("  · secuencia {$e['sequence']}: {$e['error']}");
                    }
                }
                $failed = $failed || ! $r['valid'];
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
