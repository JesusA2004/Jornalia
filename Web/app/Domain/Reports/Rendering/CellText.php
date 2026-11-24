<?php

namespace App\Domain\Reports\Rendering;

use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Support\ReportFormat;
use Carbon\CarbonImmutable;

/**
 * Representación en texto de un valor crudo del dataset (PDF y CSV).
 * Las horas se muestran en la zona del reporte; las duraciones como h:mm.
 */
final class CellText
{
    /**
     * Texto de una celda según el tipo de columna; las fechas se muestran en la
     * zona del centro de trabajo y los minutos como horas:minutos.
     */
    public static function format(mixed $value, ReportColumn $column, string $timezone, string $dateFormat = 'd/m/Y'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            'minutes' => ReportFormat::minutes((int) $value),
            'number' => (string) (int) $value,
            'percent' => number_format((float) $value, 1, '.', '').' %',
            'date' => CarbonImmutable::parse((string) $value)->format($dateFormat),
            'datetime' => CarbonImmutable::parse((string) $value)->setTimezone($timezone)->format($dateFormat.' H:i'),
            'time' => CarbonImmutable::parse((string) $value)->setTimezone($timezone)->format('H:i'),
            default => (string) $value,
        };
    }
}
