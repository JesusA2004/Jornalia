<?php

namespace App\Domain\Reports\Rendering;

use App\Domain\Reports\Dataset\DatasetRow;
use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Layouts\ReportLayout;
use App\Domain\Reports\Support\SpreadsheetSafe;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ImageCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel en streaming (OpenSpout): encabezado institucional con logotipo,
 * encabezados de tabla inmovilizados, autofiltro, anchos de columna, fechas y
 * horas como valores reales, duraciones en formato [h]:mm (minutos/1440),
 * subtotales y total destacados, y hoja «Información» con totales, huella e
 * integridad. Todo texto pasa por SpreadsheetSafe (sin fórmulas).
 */
final class XlsxRenderer implements ReportRenderer
{
    private const FONT = 'Calibri';

    public function render(ReportDataset $dataset, ReportLayout $layout, string $path): void
    {
        $definition = $dataset->definition;
        $header = $dataset->header;
        $columns = $dataset->columns();
        $last = count($columns) - 1;
        $primary = self::hex($layout->primaryColor(), '225C54');

        [$paper, $orientation] = $layout->paper($definition);
        $writer = new Writer(new Options(
            pageSetup: new PageSetup(
                $orientation === 'landscape' ? PageOrientation::LANDSCAPE : PageOrientation::PORTRAIT,
                $paper === 'legal' ? PaperSize::Legal : PaperSize::Letter,
                fitToHeight: 0,
                fitToWidth: 1,
            ),
            properties: new Properties(
                title: $definition->title,
                subject: (string) ($header->folio ?? ''),
                application: 'MR LANA Asistencias',
                creator: $header->organizationName,
                lastModifiedBy: $header->generatedBy,
            ),
        ));
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Reporte');

        // Encabezado institucional: logotipo, título y líneas (etiqueta, valor).
        $lines = $header->lines();
        $headerRow = 3 + count($lines) + 1; // fila (1-based) de los títulos de columna
        foreach ($columns as $i => $c) {
            $sheet->setColumnWidth(max(9, min(48, $c->effectiveWidth() * 1.15)), $i + 1);
        }
        $sheet->setSheetView((new SheetView)->withFreezeRow($headerRow + 1));
        $sheet->setPrintTitleRows('$'.$headerRow.':$'.$headerRow);

        $logo = $header->logoPath;
        if ($logo !== null && is_file($logo)) {
            [$w, $h] = @getimagesize($logo) ?: [1200, 467];
            $height = 44;
            $writer->addRow(new Row([new ImageCell($logo, (int) round($w * $height / max(1, $h)), $height)], 36));
        } else {
            $writer->addRow(Row::fromValues([SpreadsheetSafe::text($header->organizationName)], 36));
        }
        $writer->addRow(new Row([Cell::fromValue(SpreadsheetSafe::text($definition->title), new Style(fontBold: true, fontSize: 15, fontColor: $primary, fontName: self::FONT))], 22));
        $writer->addRow(Row::fromValuesWithStyle([SpreadsheetSafe::text($definition->description)], new Style(fontItalic: true, fontColor: '555555', fontName: self::FONT)));
        $label = new Style(fontBold: true, fontColor: '444444', fontName: self::FONT, fontSize: 10);
        $value = new Style(fontName: self::FONT, fontSize: 10);
        foreach ($lines as [$k, $v]) {
            $writer->addRow(new Row([Cell::fromValue(SpreadsheetSafe::text($k), $label), Cell::fromValue(SpreadsheetSafe::text($v), $value)]));
        }
        $writer->addRow(Row::fromValues([]));

        $thead = new Style(fontBold: true, fontColor: Color::WHITE, backgroundColor: $primary, fontName: self::FONT, fontSize: 10,
            cellVerticalAlignment: CellVerticalAlignment::CENTER, shouldWrapText: true);
        $writer->addRow(Row::fromValuesWithStyle(array_map(fn (ReportColumn $c) => SpreadsheetSafe::text($c->label), $columns), $thead, 30));

        $styles = $this->styles($columns, null, false);
        $groupStyle = new Style(fontBold: true, fontColor: $primary, backgroundColor: 'E6EFED', fontName: self::FONT, fontSize: 10);
        $subStyles = $this->styles($columns, 'F4EEE3', true);
        $totalStyles = $this->styles($columns, $primary, true, Color::WHITE);
        $rowIndex = $headerRow;

        foreach ($dataset->rows() as $row) {
            $rowIndex++;
            if ($row->kind === DatasetRow::ROW) {
                $cells = [];
                foreach ($columns as $i => $c) {
                    $cells[] = $this->cell($row->values[$c->key] ?? null, $c, $header->timezone, $styles[$i]);
                }
                $writer->addRow(new Row($cells));
            } elseif ($row->kind === DatasetRow::GROUP) {
                $cells = [Cell::fromValue(SpreadsheetSafe::text($row->label), $groupStyle)];
                for ($i = 1; $i <= $last; $i++) {
                    $cells[] = Cell::fromValue('', $groupStyle);
                }
                $writer->addRow(new Row($cells));
            } else {
                $writer->addRow($this->summaryRow($row->label.' ('.$row->count.')', $row->values, $columns, $header->timezone, $subStyles));
            }
        }
        if ($definition->sumColumns() !== []) {
            $rowIndex++;
            $writer->addRow($this->summaryRow('TOTAL GENERAL ('.$dataset->rowCount().')', $dataset->totals(), $columns, $header->timezone, $totalStyles));
        }
        if ($rowIndex > $headerRow && $last >= 0) {
            $sheet->setAutoFilter(new AutoFilter(0, $headerRow, $last, $rowIndex));
        }

        // Hoja de información: encabezado, totales, huella e integridad.
        $info = $writer->addNewSheetAndMakeItCurrent();
        $info->setName('Información');
        $info->setColumnWidth(34, 1);
        $info->setColumnWidth(90, 2);
        foreach ($lines as [$k, $v]) {
            $writer->addRow(new Row([Cell::fromValue(SpreadsheetSafe::text($k), $label), Cell::fromValue(SpreadsheetSafe::text($v), $value)]));
        }
        $writer->addRow(new Row([Cell::fromValue('Registros', $label), Cell::fromValue($dataset->rowCount(), $value)]));
        foreach ($dataset->totals() as $key => $total) {
            $column = $definition->column($key);
            if ($column !== null) {
                $writer->addRow(new Row([Cell::fromValue(SpreadsheetSafe::text('Total '.mb_strtolower($column->label)), $label), Cell::fromValue(CellText::format($total, $column, $header->timezone), $value)]));
            }
        }
        $writer->addRow(new Row([Cell::fromValue('SHA-256 de los datos', $label), Cell::fromValue($dataset->dataSha256(), $value)]));
        if ($header->observations) {
            $writer->addRow(new Row([Cell::fromValue('Observaciones', $label), Cell::fromValue(SpreadsheetSafe::text($header->observations), $value)]));
        }
        foreach ($dataset->notes as $note) {
            $writer->addRow(new Row([Cell::fromValue('Nota', $label), Cell::fromValue(SpreadsheetSafe::text($note), $value)]));
        }
        if ($dataset->integrity !== null) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(new Row([Cell::fromValue('Verificación de integridad', $label), Cell::fromValue((string) $dataset->integrity['verified_at'], $value)]));
            foreach ($dataset->integrity['chains'] as $chain) {
                $writer->addRow(new Row([
                    Cell::fromValue(SpreadsheetSafe::text((string) $chain['label']), $label),
                    Cell::fromValue(($chain['valid'] ? 'Íntegra' : 'Con hallazgos')." · {$chain['checked']} registros · secuencia {$chain['head_sequence']} · anclas {$chain['anchors_checked']}", $value),
                ]));
                $writer->addRow(new Row([Cell::fromValue('Hash de cabeza', $label), Cell::fromValue((string) $chain['head_hash'], $value)]));
                foreach (array_slice($chain['errors'], 0, 50) as $e) {
                    $writer->addRow(new Row([Cell::fromValue('Hallazgo', $label), Cell::fromValue(SpreadsheetSafe::text("#{$e['sequence']}: {$e['error']}"), $value)]));
                }
            }
        }
        $writer->close();
    }

    /**
     * Estilo por columna (formato numérico, alineación y relleno).
     *
     * @param  list<ReportColumn>  $columns
     * @param  non-empty-string|null  $fill
     * @param  non-empty-string  $color
     * @return list<Style>
     */
    private function styles(array $columns, ?string $fill, bool $bold, string $color = '1F2937'): array
    {
        return array_map(fn (ReportColumn $c) => new Style(
            fontBold: $bold,
            fontColor: $color,
            fontName: self::FONT,
            fontSize: 10,
            cellAlignment: $c->isNumeric() || in_array($c->type, ['time', 'date'], true) ? CellAlignment::RIGHT : CellAlignment::LEFT,
            shouldWrapText: $c->type === 'text' && $c->effectiveWidth() >= 14 ? true : null,
            backgroundColor: $fill,
            format: match ($c->type) {
                'date' => 'dd/mm/yyyy',
                'datetime' => 'dd/mm/yyyy hh:mm',
                'time' => 'hh:mm',
                'minutes' => '[h]:mm',
                'percent' => '0.0%',
                'number' => '#,##0',
                'decimal' => '0.00',
                default => null,
            },
        ), $columns);
    }

    private function cell(mixed $value, ReportColumn $c, string $tz, Style $style): Cell
    {
        if ($value === null || $value === '') {
            return Cell::fromValue(null, $style);
        }

        return Cell::fromValue(match ($c->type) {
            'date' => new DateTimeImmutable(CarbonImmutable::parse((string) $value)->format('Y-m-d').' 00:00:00'),
            'datetime', 'time' => new DateTimeImmutable(CarbonImmutable::parse((string) $value)->setTimezone($tz)->format('Y-m-d H:i:s')),
            // Duración: fracción de día; el formato [h]:mm la muestra como horas:minutos.
            'minutes' => ((int) $value) / 1440,
            'number' => (int) $value,
            'percent' => ((float) $value) / 100,
            'decimal' => (float) $value,
            default => SpreadsheetSafe::text((string) $value),
        }, $style);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<ReportColumn>  $columns
     * @param  list<Style>  $styles
     */
    private function summaryRow(string $label, array $values, array $columns, string $tz, array $styles): Row
    {
        $cells = [];
        foreach ($columns as $i => $c) {
            if ($i === 0) {
                $cells[] = Cell::fromValue(SpreadsheetSafe::text($label), $styles[$i]);
            } elseif (($c->sum || $c->ratio !== null) && array_key_exists($c->key, $values)) {
                $cells[] = $this->cell($values[$c->key], $c, $tz, $styles[$i]);
            } else {
                $cells[] = Cell::fromValue('', $styles[$i]);
            }
        }

        return new Row($cells);
    }

    /**
     * Color hexadecimal sin `#` (OpenSpout no acepta colores vacíos).
     *
     * @param  non-empty-string  $fallback
     * @return non-empty-string
     */
    private static function hex(string $color, string $fallback): string
    {
        $hex = ltrim($color, '#');

        return $hex !== '' ? $hex : $fallback;
    }
}
