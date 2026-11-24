<?php

namespace App\Domain\Reports\Dataset;

use App\Domain\Reports\Definition\ReportColumn;
use App\Domain\Reports\Definition\ReportDefinition;
use Closure;
use Generator;
use LogicException;

/**
 * Dataset tipado de un reporte, independiente del formato de salida.
 *
 * Las filas se recorren en streaming (generador): el dataset intercala
 * encabezados de grupo y subtotales según `ReportDefinition::$groupings`, y
 * acumula el total general, el número de filas y la huella SHA-256 de los datos
 * mientras se recorre. PDF, Excel y CSV consumen exactamente el mismo dataset,
 * por lo que los totales y la huella coinciden entre formatos.
 */
final class ReportDataset
{
    /** @var array<string, int> */
    private array $sums = [];

    private int $count = 0;

    private string $sha = '';

    private bool $complete = false;

    /**
     * @param  Closure(): iterable<array<string, mixed>>  $source  filas crudas ya ordenadas por los niveles de agrupación
     * @param  array<string, mixed>|null  $integrity  verificación de integridad (inspección laboral)
     * @param  list<string>  $notes  notas al pie del reporte
     */
    public function __construct(
        public readonly ReportDefinition $definition,
        public readonly ReportHeader $header,
        private readonly Closure $source,
        public readonly ?array $integrity = null,
        public readonly array $notes = [],
    ) {}

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return $this->definition->columns;
    }

    /**
     * Recorre el dataset. Cada recorrido recalcula totales y huella.
     *
     * @return Generator<int, DatasetRow>
     */
    public function rows(): Generator
    {
        $this->sums = [];
        $this->count = 0;
        $this->complete = false;
        $hash = hash_init('sha256');

        $groups = $this->definition->groupings;
        $levels = count($groups);
        /** @var list<mixed> $current */
        $current = array_fill(0, $levels, null);
        /** @var list<string> $labels */
        $labels = array_fill(0, $levels, '');
        /** @var list<array<string, int>> $acc */
        $acc = array_fill(0, $levels, []);
        /** @var list<int> $counts */
        $counts = array_fill(0, $levels, 0);
        $first = true;
        $sumKeys = $this->sumKeys();
        $columns = $this->columns();

        foreach (($this->source)() as $row) {
            $change = null;
            for ($l = 0; $l < $levels; $l++) {
                if ($first || ($row[$groups[$l]->key] ?? null) !== $current[$l]) {
                    $change = $l;
                    break;
                }
            }
            if ($change !== null) {
                if (! $first) {
                    for ($l = $levels - 1; $l >= $change; $l--) {
                        if ($groups[$l]->subtotals) {
                            yield $this->subtotal($l, $labels[$l], $acc[$l], $counts[$l]);
                        }
                    }
                }
                for ($l = $change; $l < $levels; $l++) {
                    $current[$l] = $row[$groups[$l]->key] ?? null;
                    $labels[$l] = (string) ($row[$groups[$l]->label] ?? '');
                    $acc[$l] = [];
                    $counts[$l] = 0;
                    yield new DatasetRow(DatasetRow::GROUP, $row, $l, $labels[$l]);
                }
            }
            $first = false;

            foreach ($sumKeys as $key) {
                $value = (int) ($row[$key] ?? 0);
                $this->sums[$key] = ($this->sums[$key] ?? 0) + $value;
                for ($l = 0; $l < $levels; $l++) {
                    $acc[$l][$key] = ($acc[$l][$key] ?? 0) + $value;
                }
            }
            for ($l = 0; $l < $levels; $l++) {
                $counts[$l]++;
            }
            $this->count++;
            $line = [];
            foreach ($columns as $c) {
                $line[] = $row[$c->key] ?? null;
            }
            hash_update($hash, json_encode($line, JSON_UNESCAPED_UNICODE)."\n");

            yield new DatasetRow(DatasetRow::ROW, $row);
        }

        if (! $first) {
            for ($l = $levels - 1; $l >= 0; $l--) {
                if ($groups[$l]->subtotals) {
                    yield $this->subtotal($l, $labels[$l], $acc[$l], $counts[$l]);
                }
            }
        }

        $this->sha = hash_final($hash);
        $this->complete = true;
    }

    /**
     * Total general (llave de columna => valor) de las columnas sumables y de razón.
     *
     * @return array<string, int|float|null>
     */
    public function totals(): array
    {
        $this->assertComplete();

        return $this->computeTotals($this->sums);
    }

    public function rowCount(): int
    {
        $this->assertComplete();

        return $this->count;
    }

    public function dataSha256(): string
    {
        $this->assertComplete();

        return $this->sha;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Llaves que se acumulan: columnas sumables y componentes de razones.
     *
     * @return list<string>
     */
    private function sumKeys(): array
    {
        $keys = [];
        foreach ($this->columns() as $c) {
            if ($c->sum) {
                $keys[] = $c->key;
            }
            if ($c->ratio !== null) {
                $keys[] = $c->ratio[0];
                $keys[] = $c->ratio[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, int>  $sums
     * @return array<string, int|float|null>
     */
    private function computeTotals(array $sums): array
    {
        $out = [];
        foreach ($this->columns() as $c) {
            if ($c->ratio !== null) {
                $den = (int) ($sums[$c->ratio[1]] ?? 0);
                $out[$c->key] = $den > 0 ? round(((int) ($sums[$c->ratio[0]] ?? 0)) * 100 / $den, 1) : null;
            } elseif ($c->sum) {
                $out[$c->key] = (int) ($sums[$c->key] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $sums
     */
    private function subtotal(int $level, string $label, array $sums, int $count): DatasetRow
    {
        $group = $this->definition->groupings[$level];

        return new DatasetRow(DatasetRow::SUBTOTAL, $this->computeTotals($sums), $level, trim($group->subtotalLabel.' '.$label), $count);
    }

    private function assertComplete(): void
    {
        if (! $this->complete) {
            throw new LogicException('El dataset debe recorrerse completo antes de consultar totales.');
        }
    }
}
