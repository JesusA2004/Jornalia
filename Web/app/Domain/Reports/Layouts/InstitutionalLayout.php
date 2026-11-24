<?php

namespace App\Domain\Reports\Layouts;

use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Definition\ReportDefinition;

/**
 * Diseño institucional por omisión: logotipo oficial, razón social, RFC,
 * centro de trabajo, periodo, generación y folio en todas las salidas; tablas
 * con encabezados repetidos, subtotales por grupo y total destacado.
 *
 * NO reproduce la plantilla de ningún tercero; es la base sobre la que se
 * montará la plantilla definitiva que entregue la empresa.
 */
class InstitutionalLayout implements ReportLayout
{
    public function pdfView(): string
    {
        return 'reports.institutional';
    }

    public function paper(ReportDefinition $definition): array
    {
        return [$definition->paper, $definition->landscape ? 'landscape' : 'portrait'];
    }

    public function pdfColumns(ReportDataset $dataset): array
    {
        if ($dataset->definition->groupings === []) {
            return $dataset->columns();
        }

        return array_values(array_filter($dataset->columns(), fn (ReportColumn $c) => ! $c->hoist));
    }

    public function primaryColor(): string
    {
        return '#225C54';
    }

    public function accentColor(): string
    {
        return '#AF8B51';
    }

    public function csvIncludesHeader(ReportDataset $dataset): bool
    {
        return true;
    }

    public function csvIncludesGroups(ReportDataset $dataset): bool
    {
        return true;
    }

    public function dateFormat(): string
    {
        return 'd/m/Y';
    }
}
