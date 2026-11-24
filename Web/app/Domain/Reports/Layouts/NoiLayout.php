<?php

namespace App\Domain\Reports\Layouts;

use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Noi\NoiConfig;

/**
 * Layout de movimientos para NOI: tabla plana (sin filas de grupo ni
 * subtotales en CSV) con el formato de fecha configurado. El encabezado
 * institucional en CSV es opcional (`csv_institutional_header`); en PDF y
 * Excel siempre se incluye.
 */
final class NoiLayout extends InstitutionalLayout
{
    public function csvIncludesHeader(ReportDataset $dataset): bool
    {
        return NoiConfig::current()['csv_institutional_header'];
    }

    public function csvIncludesGroups(ReportDataset $dataset): bool
    {
        return false;
    }

    public function dateFormat(): string
    {
        return NoiConfig::current()['date_format'];
    }
}
