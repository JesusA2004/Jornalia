<?php

namespace App\Console\Commands;

use App\Domain\Legal\HolidayGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Precarga anual del catálogo de días de descanso obligatorio (LFT art. 74).
 * Idempotente: nunca sobrescribe ni reactiva días que RH editó o dio de baja.
 */
#[Signature('asistencias:festivos {--anio= : Año a precargar (por defecto el actual y el siguiente)}')]
#[Description('Precarga los días de descanso obligatorio del art. 74 LFT para el año en curso y el siguiente')]
final class PreloadHolidaysCommand extends Command
{
    public function handle(HolidayGenerator $generator): int
    {
        $year = $this->option('anio') !== null ? (int) $this->option('anio') : (int) now()->year;
        $to = $this->option('anio') !== null ? $year : $year + 1;

        $created = $generator->generate($generator->officialRules(), $year, $to);
        $this->info("{$created} día(s) de descanso obligatorio precargado(s).");

        return self::SUCCESS;
    }
}
