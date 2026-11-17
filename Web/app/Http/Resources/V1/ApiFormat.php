<?php

namespace App\Http\Resources\V1;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Formatos del contrato API v1: fechas-hora ISO-8601 UTC con sufijo `Z`
 * y fechas civiles `YYYY-MM-DD`.
 */
final class ApiFormat
{
    public static function dateTime(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Fecha civil tal como está almacenada (columna `date`, sin conversión de zona).
     */
    public static function date(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : CarbonImmutable::parse($value)->format('Y-m-d');
    }
}
