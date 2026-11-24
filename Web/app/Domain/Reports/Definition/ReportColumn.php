<?php

namespace App\Domain\Reports\Definition;

/**
 * Columna de un reporte.
 *
 * Tipos: text, date, datetime, time, minutes (enteros), number, percent, hash,
 * decimal (cadena con punto decimal, sin float).
 *
 *  - `sum`: se totaliza en subtotales y total general.
 *  - `ratio`: porcentaje recalculado en subtotales a partir de dos columnas sumadas
 *    (numerador, denominador), p. ej. puntualidad = días puntuales / días asistidos.
 *  - `hoist`: el diseño PDF la muestra en el encabezado del grupo y no en cada fila
 *    (Excel y CSV conservan todas las columnas para poder filtrar).
 *  - `width`: peso relativo del ancho (PDF) y ancho aproximado en caracteres (Excel).
 */
final readonly class ReportColumn
{
    /**
     * @param  array{0:string,1:string}|null  $ratio
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $sum = false,
        public ?array $ratio = null,
        public bool $hoist = false,
        public float $width = 0,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, ['number', 'minutes', 'percent', 'decimal'], true);
    }

    /** Ancho por omisión según el tipo cuando la definición no lo fija. */
    public function effectiveWidth(): float
    {
        if ($this->width > 0) {
            return $this->width;
        }

        return match ($this->type) {
            'date' => 10,
            'time' => 7,
            'datetime' => 16,
            'minutes', 'number', 'percent', 'decimal' => 9,
            'hash' => 20,
            default => 14,
        };
    }

    /**
     * @return array{key:string,label:string,type:string,sum?:bool}
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'sum' => $this->sum || $this->ratio !== null ? true : null,
        ], fn ($v) => $v !== null);
    }
}
