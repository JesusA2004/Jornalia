<?php

namespace App\Listeners;

use App\Events\PunchRecorded;
use App\Jobs\RecomputeWorkday;
use Carbon\CarbonImmutable;

/**
 * Tras cada marcaje recalcula el día civil del evento y el anterior
 * (turnos que cruzan medianoche).
 */
final class ScheduleWorkdayRecompute
{
    public function handle(PunchRecorded $event): void
    {
        $p = $event->punch;
        $date = CarbonImmutable::parse($p->local_date);

        foreach ([$date->subDay(), $date] as $d) {
            RecomputeWorkday::dispatch($p->organization_id, $p->employee_id, $d->toDateString())->afterCommit();
        }
    }
}
