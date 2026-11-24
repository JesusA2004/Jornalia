<?php

namespace App\Domain\Reports\Rendering;

use App\Domain\Reports\Dataset\DatasetRow;
use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Layouts\ReportLayout;
use App\Domain\Reports\Support\SpreadsheetSafe;
use RuntimeException;

/**
 * CSV UTF-8 con BOM (Excel lo abre con acentos correctos), en streaming.
 * Todo texto pasa por SpreadsheetSafe para evitar inyección de fórmulas.
 */
final class CsvRenderer implements ReportRenderer
{
    public function render(ReportDataset $dataset, ReportLayout $layout, string $path): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('No fue posible crear el archivo CSV.');
        }
        fwrite($fh, "\xEF\xBB\xBF");
        $tz = $dataset->header->timezone;
        $columns = $dataset->columns();
        $withHeader = $layout->csvIncludesHeader($dataset);
        $withGroups = $layout->csvIncludesGroups($dataset);

        if ($withHeader) {
            foreach ($dataset->header->lines() as [$label, $value]) {
                $this->put($fh, [$label, $value]);
            }
            $this->put($fh, []);
        }
        $this->put($fh, array_map(fn (ReportColumn $c) => $c->label, $columns));

        foreach ($dataset->rows() as $row) {
            if ($row->kind === DatasetRow::ROW) {
                $this->put($fh, array_map(fn (ReportColumn $c) => CellText::format($row->values[$c->key] ?? null, $c, $tz, $layout->dateFormat()), $columns));
            } elseif ($withGroups && $row->kind === DatasetRow::GROUP) {
                $this->put($fh, [$row->label]);
            } elseif ($withGroups && $row->kind === DatasetRow::SUBTOTAL) {
                $this->put($fh, $this->summaryLine($row->label.' ('.$row->count.')', $row->values, $columns, $tz));
            }
        }

        if ($withGroups && $dataset->definition->sumColumns() !== []) {
            $this->put($fh, $this->summaryLine('TOTAL GENERAL ('.$dataset->rowCount().')', $dataset->totals(), $columns, $tz));
        }
        if ($withHeader) {
            $this->put($fh, []);
            $this->put($fh, ['Registros', (string) $dataset->rowCount()]);
            $this->put($fh, ['SHA-256 de los datos', $dataset->dataSha256()]);
            foreach ($dataset->notes as $note) {
                $this->put($fh, ['Nota', $note]);
            }
        }
        fclose($fh);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<ReportColumn>  $columns
     * @return list<string>
     */
    private function summaryLine(string $label, array $values, array $columns, string $tz): array
    {
        $line = [];
        foreach ($columns as $i => $c) {
            $line[] = $i === 0 ? $label : (array_key_exists($c->key, $values) && ($c->sum || $c->ratio) ? CellText::format($values[$c->key], $c, $tz) : '');
        }

        return $line;
    }

    /**
     * @param  resource  $fh
     * @param  list<string>  $cells
     */
    private function put($fh, array $cells): void
    {
        fputcsv($fh, array_map(fn ($v) => SpreadsheetSafe::text((string) $v), $cells), ',', '"', '');
    }
}
