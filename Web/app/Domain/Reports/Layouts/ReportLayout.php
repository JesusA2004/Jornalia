<?php

namespace App\Domain\Reports\Layouts;

use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Definition\ReportDefinition;

/**
 * Punto de extensión del diseño de un reporte.
 *
 * El dataset (filas, grupos, subtotales, totales y encabezado institucional) es
 * siempre el mismo; el layout sólo decide CÓMO se presenta en cada formato.
 * Para adoptar una plantilla nueva se implementa esta interfaz (o se extiende
 * InstitutionalLayout) y se registra en `config/reports.php` > `layouts`.
 * Ver docs/reportes/REPORTES.md.
 */
interface ReportLayout
{
    /** Vista Blade del PDF; recibe `dataset`, `layout`, `columns`, `rows`, `totals`, `truncated`. */
    public function pdfView(): string;

    /**
     * Papel y orientación del PDF.
     *
     * @return array{0:string,1:'portrait'|'landscape'}
     */
    public function paper(ReportDefinition $definition): array;

    /**
     * Columnas que se dibujan en la tabla del PDF (las «izadas» van al encabezado de grupo).
     *
     * @return list<ReportColumn>
     */
    public function pdfColumns(ReportDataset $dataset): array;

    /** Color institucional principal (hex) para encabezados y totales. */
    public function primaryColor(): string;

    /** Color de acento (hex) para subtotales y detalles. */
    public function accentColor(): string;

    /** ¿El CSV lleva el encabezado institucional antes de la tabla? */
    public function csvIncludesHeader(ReportDataset $dataset): bool;

    /** ¿El CSV lleva filas de grupo y subtotales? (false = tabla plana importable) */
    public function csvIncludesGroups(ReportDataset $dataset): bool;

    /** Formato de fecha civil en CSV y PDF (sintaxis de PHP `date()`). */
    public function dateFormat(): string;
}
