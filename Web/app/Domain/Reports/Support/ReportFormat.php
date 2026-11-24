<?php

namespace App\Domain\Reports\Support;

use App\Enums\ApprovalStatus;
use App\Enums\RectificationStage;
use App\Models\RectificationRequest;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Formatos de presentación compartidos por constructores y renderizadores.
 * Duraciones siempre en minutos enteros; horas decimales se forman con
 * aritmética entera (sin float).
 */
final class ReportFormat
{
    private const WEEKDAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /** Instante en ISO-8601 UTC (`Y-m-d\TH:i:s\Z`) o null. */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');

        return $instant->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function weekday(CarbonImmutable $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeekIso - 1];
    }

    /** Minutos como `h:mm` (p. ej. 545 → `9:05`). */
    public static function minutes(int $minutes): string
    {
        return sprintf('%s%d:%02d', $minutes < 0 ? '-' : '', intdiv(abs($minutes), 60), abs($minutes) % 60);
    }

    /**
     * Minutos como horas decimales con dos posiciones, redondeo al centésimo
     * más cercano con enteros (p. ej. 90 → `1.50`, 20 → `0.33`).
     */
    public static function hoursDecimal(int $minutes, string $separator = '.'): string
    {
        $hundredths = intdiv(abs($minutes) * 100 + 30, 60);

        return ($minutes < 0 ? '-' : '').intdiv($hundredths, 100).$separator.sprintf('%02d', $hundredths % 100);
    }

    /** Etapa legible de una rectificación (flujo de 5 pasos o estado heredado). */
    public static function rectificationStage(RectificationRequest $r): string
    {
        $stage = $r->getAttribute('stage');
        if ($stage instanceof RectificationStage) {
            return $stage->label();
        }
        if (is_string($stage) && RectificationStage::tryFrom($stage) !== null) {
            return RectificationStage::from($stage)->label();
        }
        $status = $r->getAttribute('status');

        return $status instanceof ApprovalStatus ? $status->label() : (string) $status;
    }
}
