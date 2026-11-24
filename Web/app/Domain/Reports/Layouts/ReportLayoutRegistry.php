<?php

namespace App\Domain\Reports\Layouts;

use App\Domain\Reports\Definition\ReportDefinition;
use InvalidArgumentException;

/**
 * Resuelve el layout de un reporte desde `config/reports.php` > `layouts`.
 */
final class ReportLayoutRegistry
{
    public function for(ReportDefinition $definition): ReportLayout
    {
        $class = config("reports.layouts.{$definition->key}") ?? config('reports.layouts.default', InstitutionalLayout::class);
        $layout = app((string) $class);
        if (! $layout instanceof ReportLayout) {
            throw new InvalidArgumentException("El layout {$class} no implementa ReportLayout.");
        }

        return $layout;
    }
}
