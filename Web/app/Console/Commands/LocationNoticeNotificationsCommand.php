<?php

namespace App\Console\Commands;

use App\Domain\Notifications\LocationNotice;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asistencias:aviso-ubicacion')]
#[Description('Publica el aviso de ubicación (UBICACION) en las organizaciones que aún no lo tienen. Idempotente.')]
final class LocationNoticeNotificationsCommand extends Command
{
    public function handle(): int
    {
        $count = 0;
        foreach (Organization::query()->cursor() as $org) {
            $doc = LocationNotice::ensureFor($org);
            $count += $doc->wasRecentlyCreated ? 1 : 0;
        }
        $this->info("Avisos de ubicación publicados: {$count}");

        return self::SUCCESS;
    }
}
