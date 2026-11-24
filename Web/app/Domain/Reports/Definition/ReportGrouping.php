<?php

namespace App\Domain\Reports\Definition;

/**
 * Nivel de agrupación: las filas llegan ordenadas por `key` y cada cambio de
 * valor abre un encabezado de grupo y cierra el subtotal del grupo anterior.
 * `key` y `label` son llaves internas de la fila (prefijo `_`), no columnas.
 */
final readonly class ReportGrouping
{
    public function __construct(
        public string $key,
        public string $label,
        public string $subtotalLabel = 'Subtotal',
        public bool $subtotals = true,
    ) {}
}
