<?php

namespace App\Console\Commands;

use App\Domain\Attendance\AutoClockOut;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Punch;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asistencias:cierre-automatico')]
#[Description('Registra la salida automática de jornadas abiertas que superaron su hora máxima configurada')]
final class AutoClockOutCommand extends Command
{
    public function handle(AutoClockOut $auto, CurrentOrganization $current): int
    {
        $closed = 0;
        foreach (Organization::query()->where('status', 'active')->cursor() as $org) {
            $current->runAs($org, function () use ($auto, &$closed) {
                // Candidatos: colaboradores cuyo último marcaje (30 h) no es una salida.
                $candidateIds = Punch::query()
                    ->where('server_time', '>=', now()->subHours(30))
                    ->selectRaw('employee_id, MAX(sequence) as last_seq')
                    ->groupBy('employee_id')
                    ->pluck('last_seq', 'employee_id');

                foreach ($candidateIds as $employeeId => $lastSeq) {
                    $last = Punch::query()->where('sequence', $lastSeq)->first(['type']);
                    if ($last === null || $last->type === PunchType::ClockOut) {
                        continue;
                    }
                    $employee = Employee::query()->find($employeeId);
                    if ($employee !== null && $auto->closeIfDue($employee) !== null) {
                        $closed++;
                    }
                }
            });
        }

        $this->info("{$closed} jornada(s) cerrada(s) automáticamente.");

        return self::SUCCESS;
    }
}
