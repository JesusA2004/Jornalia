# Reportes y exportación para NOI

Módulo de reportes de MR LANA Asistencias (`Web/app/Domain/Reports/`). Los datos se construyen una sola vez en un dataset y los tres formatos (PDF, XLSX y CSV) se generan a partir de ese mismo dataset.

## Arquitectura

```
ReportCatalog (definición)
  → ReportSources (constructores: AttendanceDays, AttendanceAggregates, OvertimeReport,
                   EvidenceReport, NoiExport, ReportQuery)
  → ReportDataset (filas en streaming, grupos, subtotales, totales, encabezado, SHA-256)
  → Rendering (PdfRenderer · XlsxRenderer · CsvRenderer) con el ReportLayout del reporte
```

- **Definiciones** (`Definition/`): `ReportDefinition`, `ReportColumn` y `ReportGrouping`.
  - Tipos de columna: `text`, `date`, `datetime`, `time`, `minutes`, `number`, `percent`, `hash` y `decimal`.
  - Opciones por columna:
    - `sum`: se suma en subtotales y totales.
    - `ratio`: razón que se recalcula en cada subtotal.
    - `hoist`: en el PDF, el valor sube al encabezado del grupo.
    - `width`: ancho de la columna.
- **Constructores** (`Builders/`): no contienen lógica laboral propia.
  - `AttendanceDays` produce una fila por colaborador y día con relación laboral, tomada de `workday_summaries`.
    - Si un día pasado no tiene proyección, se calcula al vuelo con `WorkdayCalculator::compute(persist: false)`; el reporte no guarda nada.
    - Los días sin marcaje, descansos, vacaciones, permisos y festivos siempre aparecen.
  - Los concentrados reutilizan las mismas filas diarias, así que cuadran con el detalle.
  - Horas extra y NOI usan `App\Domain\Legal\WeeklyOvertimeCalculator`, el mismo cálculo semanal por colaborador del panel y del módulo de horas extra.
- **Encabezado institucional** (`ReportHeaderFactory`): logotipo oficial, razón social, RFC, centro de trabajo, periodo, fecha y hora de generación, usuario y folio `REP-AAAA-NNNNNN`. Lo que no está capturado aparece como «Sin capturar»; nunca se inventan datos.
- **Alcance**: `ScopedEmployees` aplica `AccessScope` (sucursal y región del usuario) y el aislamiento por organización, tanto en la vista previa como en la exportación.

## Catálogo (27 reportes)

| Grupo | Reportes |
|---|---|
| Asistencia | Asistencia detallada, diaria (hasta 31 días), individual, por colaborador y periodo (semana, quincena o mes), puntualidad, retardos, faltas, omisiones de checada, salidas anticipadas, descansos y comidas, horarios programados |
| Tiempo | Horas trabajadas; horas extraordinarias (registrado, dobles, triples, sobre límite, autorizado, justificado, pendiente); horas dobles; horas triples |
| Concentrados | Consolidada, por sucursal, por región, por departamento, por puesto |
| Incidencias | Incidencias con evidencia; omisiones e incidencias con evidencia; vacaciones |
| Legal | Rectificaciones y firmas; cumplimiento laboral; historial de marcajes; inspección laboral (con verificación de integridad) |
| Nómina | Exportación de incidencias y tiempo extra para NOI |

El reporte detallado incluye estas columnas:

- **Identificación:** colaborador, número, sucursal, departamento y puesto.
- **Día y horario:** fecha, día de la semana y horario asignado.
- **Marcajes:** entrada, salida, inicio y término de descanso, inicio y término de comida.
- **Tiempos:** tiempo efectivo, ordinario, tiempo extra, retardo y salida anticipada.
- **Situación:** incidencia, omisión, estado, origen del marcaje, rectificación y observaciones.

Se agrupa por colaborador y semana, con subtotales y total general.

## Formatos

- **PDF** (Dompdf):
  - El encabezado institucional y los encabezados de tabla se repiten en cada página.
  - Tamaño carta u oficio, en orientación vertical u horizontal según el reporte.
  - Subtotales destacados, «Página X de Y» y el folio en el pie.
  - Límite de `pdf_max_rows` filas; para volúmenes mayores se usa XLSX o CSV.
- **XLSX** (OpenSpout):
  - Logotipo, encabezados inmovilizados, autofiltro y anchos de columna.
  - Fechas y horas como valores reales, y duraciones en formato `[h]:mm`.
  - Subtotales y total, más una hoja «Información» con totales, SHA-256 e integridad.
- **CSV**: UTF-8 con BOM, encabezado institucional y totales. La exportación para NOI sale en formato plano.
- **Inyección de fórmulas**: `Support/SpreadsheetSafe` antepone `'` a todo texto que empiece con `=`, `+`, `-`, `@`, tabulador o retorno de carro.

## Bitácora y cola

- Cada exportación registra en la bitácora `report.requested`, `report.generated`, `report.failed` y `report.downloaded`, todos con folio. Si el reporte se genera en cola, el registro se atribuye al usuario que lo pidió.
- Si la estimación supera `sync_max_rows`, el reporte se envía al job `GenerateReportExport` y la pantalla consulta su estado cada 5 segundos. Requiere un worker de cola en producción.

## Punto de extensión para plantillas

La plantilla definitiva de formato (referencia comercial que proporcionará la empresa) se incorpora sin tocar los datos:

1. Crear `App\Domain\Reports\Layouts\<Nombre>Layout`, que extienda `InstitutionalLayout` y sobrescriba sólo lo necesario: `pdfView`, `paper`, `pdfColumns`, `primaryColor`, `accentColor`, `csvIncludesHeader`, `csvIncludesGroups` y `dateFormat`.
2. Crear su vista Blade. Recibe `dataset`, `header`, `definition`, `columns`, `widths`, `rows`, `totals`, `totalList`, `truncated`, `logo`, `primary` y `accent`.
3. Registrarlo en `config/reports.php > layouts`, como `default` o por clave de reporte.

Ningún constructor ni prueba de datos cambia. **Estado:** la plantilla exacta aún no se recibe; los reportes usan el diseño institucional.

## Configuración (`config/reports.php`)

`pdf_max_rows`, `pdf_memory_limit`, `sync_max_rows`, `preview_max_rows`, `meal_pause` y `noi` (`columns`, `concepts`, `incidence_types`, `date_format`, `decimal_separator`, `csv_institutional_header`, `only_payroll_incidences`). El ajuste de organización `reports.noi` sobrescribe la sección `noi`.

## Exportación para NOI

- Genera un archivo de movimientos con columnas configurables.
- Incluye faltas, vacaciones, las incidencias aprobadas que afectan nómina, y las horas dobles y triples **autorizadas** del cálculo semanal.
- El tiempo autorizado cubre primero las horas dobles y después las triples. Las horas decimales se calculan con aritmética entera.
- Las claves iniciales (`FALTA`, `HORAS_EXTRA_DOBLES`, …) son marcadores que RH debe sustituir por las de su catálogo NOI.
- El sistema no modifica NOI; sólo genera el archivo de intercambio.

## Pendientes de definición por RH

- `reports.meal_pause`: indica qué pausa del día es la comida. Con 0 (valor inicial), todas las pausas se reportan como descanso y el reporte lo indica en una nota.
- Las claves de concepto de NOI.
