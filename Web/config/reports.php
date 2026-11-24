<?php

use App\Domain\Reports\Layouts\InstitutionalLayout;
use App\Domain\Reports\Layouts\NoiLayout;

/*
|--------------------------------------------------------------------------
| Reportes
|--------------------------------------------------------------------------
|
| Documentación: docs/reportes/REPORTES.md
|
*/

return [

    /*
    | Diseño (ReportLayout) por reporte. `default` aplica a todos los que no
    | tengan uno propio. Para adoptar una plantilla nueva basta con crear una
    | clase que implemente App\Domain\Reports\Layouts\ReportLayout y asignarla
    | aquí; los datos (dataset) no cambian.
    */
    'layouts' => [
        'default' => InstitutionalLayout::class,
        'noi_incidencias' => NoiLayout::class,
    ],

    // Número de pausa del día que corresponde a la comida (1 = primera, 2 = segunda…).
    // 0 = la organización no distingue comida: todas las pausas se reportan como descanso.
    'meal_pause' => (int) env('REPORTS_MEAL_PAUSE', 0),

    // Filas máximas en PDF (documento legible). Excel y CSV no tienen límite.
    'pdf_max_rows' => (int) env('REPORTS_PDF_MAX_ROWS', 2000),

    // Memoria mínima del proceso al dibujar un PDF (Dompdf trabaja en memoria).
    'pdf_memory_limit' => env('REPORTS_PDF_MEMORY_LIMIT', '512M'),

    // Volumen estimado (colaborador-día o registros) a partir del cual la
    // exportación se procesa en la cola en lugar de la misma petición.
    'sync_max_rows' => (int) env('REPORTS_SYNC_MAX_ROWS', 2000),

    // Filas máximas que recorre la vista previa para calcular totales.
    'preview_max_rows' => (int) env('REPORTS_PREVIEW_MAX_ROWS', 5000),

    /*
    | Exportación de incidencias y tiempo extraordinario para NOI.
    |
    | Es un archivo de MOVIMIENTOS en formato tabular (CSV/Excel) con columnas
    | configurables. Las claves de concepto son marcadores: RH debe sustituirlas
    | por las claves de su catálogo de NOI. Cada organización puede sobrescribir
    | cualquier parte de esta configuración con el ajuste `reports.noi`
    | (tabla organization_settings). No se modifica ni se conecta a NOI.
    */
    'noi' => [
        'date_format' => 'd/m/Y',
        'decimal_separator' => '.',
        // Encabezado institucional dentro del CSV (desactivar si el importador
        // exige sólo datos; el encabezado siempre queda en el PDF/Excel y en la bitácora).
        'csv_institutional_header' => false,
        'columns' => [
            ['key' => 'employee_number', 'label' => 'Clave empleado'],
            ['key' => 'employee_name', 'label' => 'Nombre'],
            ['key' => 'date', 'label' => 'Fecha'],
            ['key' => 'concept_code', 'label' => 'Clave concepto'],
            ['key' => 'concept_name', 'label' => 'Concepto'],
            ['key' => 'unit', 'label' => 'Unidad'],
            ['key' => 'quantity', 'label' => 'Cantidad'],
        ],
        'concepts' => [
            'absence' => ['code' => 'FALTA', 'label' => 'Falta injustificada', 'enabled' => true],
            'vacation' => ['code' => 'VACACIONES', 'label' => 'Vacaciones', 'enabled' => true],
            'overtime_double' => ['code' => 'HORAS_EXTRA_DOBLES', 'label' => 'Horas extra dobles', 'enabled' => true],
            'overtime_triple' => ['code' => 'HORAS_EXTRA_TRIPLES', 'label' => 'Horas extra triples', 'enabled' => true],
            'late' => ['code' => 'RETARDO', 'label' => 'Retardo (minutos)', 'enabled' => false],
        ],
        // Código de tipo de incidencia del sistema => clave de NOI. Si un tipo no
        // aparece, se usa su propio código.
        'incidence_types' => [],
        // Sólo incidencias cuyo tipo «afecta nómina».
        'only_payroll_incidences' => true,
    ],
];
