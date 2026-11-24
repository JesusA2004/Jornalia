<?php

namespace App\Domain\Reports\Definition;

/**
 * Definición declarativa de un reporte: qué columnas tiene, cómo se agrupa,
 * qué constructor de datos lo alimenta y cómo se presenta (orientación, papel).
 * El diseño visual concreto lo decide el `ReportLayout` asignado.
 */
final readonly class ReportDefinition
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportGrouping>  $groupings
     * @param  array<string, mixed>  $options  opciones para el constructor de datos
     * @param  list<array{value:string,label:string}>  $statusOptions
     * @param  list<string>  $filters  filtros aplicables en la UI
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $category,
        public string $categoryLabel,
        public string $builder,
        public array $columns,
        public array $groupings = [],
        public array $options = [],
        public bool $landscape = true,
        public string $paper = 'letter',
        public array $statusOptions = [],
        public bool $requiresEmployee = false,
        public bool $featured = false,
        public ?int $maxDays = null,
        public array $filters = ['branch', 'department', 'position', 'region', 'employee'],
    ) {}

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @return list<ReportColumn>
     */
    public function sumColumns(): array
    {
        return array_values(array_filter($this->columns, fn (ReportColumn $c) => $c->sum));
    }

    /**
     * Forma serializable para la UI (Inertia).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'category_label' => $this->categoryLabel,
            'landscape' => $this->landscape,
            'columns' => array_map(fn (ReportColumn $c) => $c->toArray(), $this->columns),
            'status_options' => $this->statusOptions,
            'requires_employee' => $this->requiresEmployee,
            'filters' => $this->filters,
            'max_days' => $this->maxDays,
        ];
    }
}
