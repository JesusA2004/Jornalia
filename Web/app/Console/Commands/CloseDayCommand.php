<?php

namespace App\Console\Commands;

use App\Jobs\RecomputeWorkday;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asistencias:cerrar-dia {--fecha= : Fecha civil (por defecto ayer en la zona de cada organización)} {--dias=1 : Número de días hacia atrás a recalcular}')]
#[Description('Recalcula las jornadas del día (ausencias, omisiones de salida, horas extra y alertas) para todo el personal activo')]
final class CloseDayCommand extends Command
{
    public function handle(CurrentOrganization $current): int
    {
        $total = 0;
        foreach (Organization::query()->where('status', 'active')->cursor() as $org) {
            $base = $this->option('fecha')
                ? CarbonImmutable::parse((string) $this->option('fecha'))
                : CarbonImmutable::now($org->timezone)->subDay();

            $current->runAs($org, function () use ($org, $base, &$total) {
                Employee::query()->where('status', 'active')->select('id')->chunkById(500, function ($employees) use ($org, $base, &$total) {
                    foreach ($employees as $employee) {
                        for ($i = 0; $i < (int) $this->option('dias'); $i++) {
                            RecomputeWorkday::dispatch($org->id, $employee->id, $base->subDays($i)->toDateString());
                            $total++;
                        }
                    }
                });
            });
        }

        $this->info("{$total} recálculos encolados.");

        return self::SUCCESS;
    }
}
