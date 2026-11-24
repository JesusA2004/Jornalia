<?php

namespace App\Domain\Reports\Rendering;

use App\Domain\Reports\Dataset\DatasetRow;
use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Layouts\ReportLayout;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF con Dompdf: encabezado institucional en cada página, encabezados de
 * tabla repetidos, anchos proporcionales (sin columnas fuera de margen),
 * subtotales y total destacados y pie «Página X de Y».
 *
 * Por legibilidad se dibujan como máximo `reports.pdf_max_rows` filas de
 * datos; los totales y la huella siempre corresponden al conjunto completo.
 */
final class PdfRenderer implements ReportRenderer
{
    public function render(ReportDataset $dataset, ReportLayout $layout, string $path): void
    {
        $this->ensureMemory((string) config('reports.pdf_memory_limit', '512M'));
        $max = (int) config('reports.pdf_max_rows', 2000);
        $columns = $layout->pdfColumns($dataset);
        $tz = $dataset->header->timezone;
        $rows = [];
        $data = 0;
        $truncated = false;

        foreach ($dataset->rows() as $row) {
            if ($row->kind === DatasetRow::ROW) {
                $data++;
                if ($data > $max) {
                    $truncated = true;

                    continue;
                }
                // En el detalle, una duración cero se deja en blanco para facilitar la lectura.
                $rows[] = ['kind' => 'row', 'cells' => array_map(fn (ReportColumn $c) => $c->type === 'minutes' && (int) ($row->values[$c->key] ?? 0) === 0
                    ? ''
                    : CellText::format($row->values[$c->key] ?? null, $c, $tz, $layout->dateFormat()), $columns)];
            } elseif (! $truncated) {
                $rows[] = [
                    'kind' => $row->kind,
                    'level' => $row->level,
                    'label' => $row->label,
                    'count' => $row->count,
                    'hoisted' => $row->kind === DatasetRow::GROUP && $row->level === 0 ? $this->hoisted($dataset, $row->values, $row->label) : [],
                    'cells' => $row->kind === DatasetRow::SUBTOTAL ? $this->summary($row->values, $columns, $tz) : [],
                ];
            }
        }

        $totals = $dataset->definition->sumColumns() !== [] ? $this->summary($dataset->totals(), $columns, $tz) : [];
        $totalList = [];
        foreach ($dataset->totals() as $key => $value) {
            $column = $dataset->definition->column($key);
            if ($column !== null) {
                $text = CellText::format($value, $column, $tz);
                $totalList[] = ['label' => $column->label, 'value' => $text !== '' ? $text : '—'];
            }
        }

        $weights = array_map(fn (ReportColumn $c) => $c->effectiveWidth(), $columns);
        $sum = array_sum($weights) ?: 1;
        [$paper, $orientation] = $layout->paper($dataset->definition);

        $pdf = Pdf::loadView($layout->pdfView(), [
            'dataset' => $dataset,
            'header' => $dataset->header,
            'definition' => $dataset->definition,
            'columns' => $columns,
            'widths' => array_map(fn ($w) => round($w * 100 / $sum, 3), $weights),
            'rows' => $rows,
            'totals' => $totals,
            'totalList' => $totalList,
            'truncated' => $truncated,
            'maxRows' => $max,
            'logo' => $dataset->header->logoDataUri(),
            'primary' => $layout->primaryColor(),
            'accent' => $layout->accentColor(),
            'dense' => count($columns) > 14,
        ])->setPaper($paper, $orientation);

        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $w = $canvas->get_width();
        $h = $canvas->get_height();
        // Paginación «Página X de Y» y folio en cada página.
        $canvas->page_text($w - 118, $h - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, [0.27, 0.32, 0.36]);
        $canvas->page_text(28, $h - 24, $this->footer($dataset), $font, 7, [0.42, 0.45, 0.5]);

        file_put_contents($path, $pdf->output());
    }

    /**
     * Dompdf arma el documento completo en memoria: se eleva el límite del
     * proceso sólo si el configurado es menor (nunca se reduce).
     */
    private function ensureMemory(string $limit): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        $bytes = fn (string $v): int => (int) $v * match (strtoupper(substr(trim($v), -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
        if ($bytes($current) < $bytes($limit)) {
            ini_set('memory_limit', $limit);
        }
    }

    private function footer(ReportDataset $dataset): string
    {
        $h = $dataset->header;

        return ($h->folio ? 'Folio '.$h->folio.' · ' : '').'Generado el '.$h->generatedAt.' por '.$h->generatedBy;
    }

    /**
     * Valores de las columnas «izadas» al encabezado del grupo principal.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{label:string,value:string}>
     */
    private function hoisted(ReportDataset $dataset, array $values, string $groupLabel): array
    {
        $out = [];
        foreach ($dataset->columns() as $c) {
            $value = (string) ($values[$c->key] ?? '');
            // Se omite lo que ya dice la etiqueta del grupo (nombre, número, sucursal…).
            if ($c->hoist && $value !== '' && ! str_contains($groupLabel, $value)) {
                $out[] = ['label' => $c->label, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<ReportColumn>  $columns
     * @return list<string|null>
     */
    private function summary(array $values, array $columns, string $tz): array
    {
        return array_map(fn (ReportColumn $c) => ($c->sum || $c->ratio !== null) && array_key_exists($c->key, $values)
            ? CellText::format($values[$c->key], $c, $tz)
            : null, $columns);
    }
}
