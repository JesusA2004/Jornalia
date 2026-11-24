<?php

namespace App\Console\Commands;

use App\Domain\Attendance\RectificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rectificaciones:vencer')]
#[Description('Rechaza las rectificaciones cuyo plazo de firma del colaborador venció (el día queda como omisión)')]
final class ExpireRectificationsCommand extends Command
{
    public function handle(RectificationService $service): int
    {
        $count = $service->expireOverdue();
        $this->info("{$count} rectificación(es) vencida(s).");

        return self::SUCCESS;
    }
}
