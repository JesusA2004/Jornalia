<?php

namespace App\Domain\Reports\Dataset;

/**
 * Fila del dataset ya agrupado:
 *  - `row`: fila de datos (valores crudos por llave de columna).
 *  - `group`: encabezado de grupo (nivel, etiqueta y valores de la primera fila del grupo).
 *  - `subtotal`: totales del grupo que se cierra (nivel, etiqueta, número de filas).
 */
final readonly class DatasetRow
{
    public const ROW = 'row';

    public const GROUP = 'group';

    public const SUBTOTAL = 'subtotal';

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $kind,
        public array $values,
        public int $level = 0,
        public string $label = '',
        public int $count = 0,
    ) {}

    public function isData(): bool
    {
        return $this->kind === self::ROW;
    }
}
