<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Definition\ReportColumn as C;
use App\Domain\Reports\Definition\ReportDefinition;
use App\Domain\Reports\Definition\ReportGrouping;
use App\Domain\Reports\Noi\NoiConfig;
use App\Enums\ApprovalStatus;
use App\Enums\RectificationStage;
use App\Enums\WorkdayStatus;

/**
 * Catálogo de reportes. Cada definición declara columnas, agrupación y
 * constructor de datos; el diseño de salida lo decide el ReportLayout
 * configurado (config/reports.php). Ver docs/reportes/REPORTES.md.
 */
final class ReportCatalog
{
    private const CATEGORIES = [
        'asistencia' => 'Asistencia',
        'tiempo' => 'Tiempo de trabajo',
        'concentrados' => 'Concentrados',
        'ausencias' => 'Incidencias y ausencias',
        'legal' => 'Legal e integridad',
        'nomina' => 'Nómina',
    ];

    /** @var array<string, ReportDefinition>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, ReportDefinition>
     */
    public static function definitions(bool $withNoi = true): array
    {
        if (self::$cache !== null) {
            // El layout NOI depende del ajuste de la organización activa: se rearma siempre.
            return $withNoi ? self::$cache + ['noi_incidencias' => self::noiDefinition()] : self::$cache;
        }

        $byEmployee = [new ReportGrouping('_employee_key', '_employee_label', 'Subtotal')];
        $byEmployeeWeek = [
            new ReportGrouping('_employee_key', '_employee_label', 'Total colaborador'),
            new ReportGrouping('_week_key', '_week_label', 'Subtotal'),
        ];
        $emp = fn (bool $hoist = true) => [
            new C('employee_name', 'Colaborador', hoist: $hoist, width: 22),
            new C('employee_number', 'Número', hoist: $hoist, width: 9),
            new C('branch', 'Sucursal', hoist: $hoist, width: 13),
            new C('department', 'Departamento', hoist: $hoist, width: 13),
            new C('position', 'Puesto', hoist: $hoist, width: 13),
        ];
        $day = [
            new C('date', 'Fecha', 'date', width: 9),
            new C('weekday', 'Día', width: 8),
        ];
        $schedule = new C('schedule', 'Horario asignado', width: 13);
        $status = array_map(fn ($o) => $o, WorkdayStatus::options());
        $approval = ApprovalStatus::options();
        $punctuality = new C('punctuality_rate', 'Puntualidad', 'percent', ratio: ['punctual_days', 'attended_days'], width: 9);

        $detailed = [
            ...$emp(),
            ...$day,
            $schedule,
            new C('first_in', 'Entrada', 'time', width: 6),
            new C('last_out', 'Salida', 'time', width: 6),
            new C('break_start', 'Inicio descanso', 'time', width: 6.5),
            new C('break_end', 'Término descanso', 'time', width: 6.5),
            new C('meal_start', 'Inicio comida', 'time', width: 6.5),
            new C('meal_end', 'Término comida', 'time', width: 6.5),
            new C('worked_minutes', 'Tiempo efectivo', 'minutes', sum: true, width: 7.5),
            new C('ordinary_minutes', 'Ordinario', 'minutes', sum: true, width: 7.5),
            new C('extra_minutes', 'Tiempo extra', 'minutes', sum: true, width: 9),
            new C('late_minutes', 'Retardo', 'minutes', sum: true, width: 6.5),
            new C('early_leave_minutes', 'Salida anticipada', 'minutes', sum: true, width: 7.5),
            new C('incidence', 'Incidencia', width: 11),
            new C('omission', 'Omisión', width: 9),
            new C('status_label', 'Estado', width: 10),
            new C('punch_origin', 'Origen del marcaje', width: 12),
            new C('rectification', 'Rectificación', width: 12.5),
            new C('observations', 'Observaciones', width: 16),
        ];
        $aggregateCols = [
            new C('scheduled_days', 'Días programados', 'number', sum: true, width: 8),
            new C('attended_days', 'Días asistidos', 'number', sum: true, width: 8),
            new C('absent_days', 'Faltas', 'number', sum: true, width: 7),
            new C('late_days', 'Retardos', 'number', sum: true, width: 7),
            new C('omission_days', 'Omisiones', 'number', sum: true, width: 8),
            new C('worked_minutes', 'Tiempo efectivo', 'minutes', sum: true, width: 9),
            new C('ordinary_minutes', 'Ordinario', 'minutes', sum: true, width: 9),
            new C('extra_minutes', 'Tiempo extra', 'minutes', sum: true, width: 9),
            $punctuality,
        ];
        $dimension = fn (string $label, string $dim) => [
            'builder' => 'aggregate', 'options' => ['mode' => 'dimension', 'dimension' => $dim], 'landscape' => true,
            'columns' => [new C('group_name', $label, width: 22), new C('employees', 'Colaboradores', 'number', sum: true, width: 9), ...$aggregateCols],
        ];
        $overtimeCols = fn (array $minutes) => [
            ...$emp(),
            ...$day,
            new C('week_start', 'Semana (inicio)', 'date', width: 9),
            ...$minutes,
            new C('status_label', 'Estado', width: 9),
            new C('authorized_by', 'Autorizó', width: 14),
            new C('justification', 'Justificación', width: 20),
        ];
        $punchCols = [
            ...$emp(false),
            new C('sequence', 'Secuencia', 'number', width: 8),
            new C('local_date', 'Fecha', 'date', width: 9),
            new C('server_time', 'Hora servidor', 'datetime', width: 14),
            new C('type_label', 'Tipo', width: 10),
            new C('recognized_time', 'Hora reconocida', 'datetime', width: 14),
            new C('origin_label', 'Origen del marcaje', width: 12),
            new C('auth_label', 'Autenticación', width: 11),
            new C('device', 'Dispositivo', width: 11),
            new C('status_label', 'Estado', width: 8),
            new C('location', 'Ubicación (precisión)', width: 15),
            new C('anomalies', 'Anomalías', width: 14),
            new C('hash', 'Hash', 'hash', width: 14),
        ];

        /**
         * Catálogo declarativo; PHPStan valida cada entrada contra esta forma.
         *
         * @var array<string, array{
         *     title: string, description: string, category: key-of<self::CATEGORIES>, builder: string, columns: list<C>,
         *     groupings?: list<ReportGrouping>, options?: array<string, mixed>, landscape?: bool, paper?: string,
         *     status?: list<array{value: string, label: string}>, requires_employee?: bool, featured?: bool,
         *     max_days?: int, filters_extra?: list<string>
         * }> $defs
         */
        $defs = [
            // ── Asistencia ──────────────────────────────────────────
            'asistencia_detallada' => [
                'title' => 'Reporte detallado de asistencia', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Día por día: horario, marcajes, descansos, tiempos, incidencias, omisiones y rectificaciones.',
                'options' => ['events' => true], 'groupings' => $byEmployeeWeek, 'paper' => 'legal', 'columns' => $detailed, 'status' => $status,
            ],
            'asistencia_diaria' => [
                'title' => 'Asistencia diaria', 'category' => 'asistencia', 'builder' => 'days', 'max_days' => 31,
                'description' => 'Todo el personal por fecha, incluidos descansos, vacaciones y festivos.',
                'options' => ['events' => true, 'order' => 'date'], 'groupings' => [new ReportGrouping('_date_key', '_date_label', 'Total del día')], 'status' => $status,
                'columns' => [
                    new C('employee_name', 'Colaborador', width: 20), new C('employee_number', 'Número', width: 8), new C('branch', 'Sucursal', width: 12),
                    $schedule, new C('first_in', 'Entrada', 'time', width: 6), new C('last_out', 'Salida', 'time', width: 6),
                    new C('break_start', 'Inicio descanso', 'time', width: 6.5), new C('break_end', 'Término descanso', 'time', width: 6.5),
                    new C('worked_minutes', 'Tiempo efectivo', 'minutes', sum: true, width: 7.5), new C('extra_minutes', 'Tiempo extra', 'minutes', sum: true, width: 7.5),
                    new C('late_minutes', 'Retardo', 'minutes', sum: true, width: 6.5), new C('early_leave_minutes', 'Salida anticipada', 'minutes', sum: true, width: 7.5),
                    new C('incidence', 'Incidencia', width: 10), new C('omission', 'Omisión', width: 9), new C('status_label', 'Estado', width: 10),
                    new C('punch_origin', 'Origen del marcaje', width: 12),
                ],
            ],
            'asistencia_individual' => [
                'title' => 'Asistencia individual', 'category' => 'asistencia', 'builder' => 'days', 'requires_employee' => true,
                'description' => 'Tarjeta de asistencia de un colaborador por semana, con subtotales y total del periodo.',
                'options' => ['events' => true], 'groupings' => $byEmployeeWeek, 'paper' => 'legal', 'columns' => $detailed, 'status' => $status,
            ],
            'por_colaborador_periodo' => [
                'title' => 'Por colaborador y periodo', 'category' => 'asistencia', 'builder' => 'aggregate',
                'description' => 'Concentrado de cada colaborador por semana, quincena o mes.',
                'options' => ['mode' => 'employee_period'], 'groupings' => $byEmployee, 'filters_extra' => ['period_unit'],
                'columns' => [...$emp(), new C('period', 'Periodo', width: 18), ...$aggregateCols],
            ],
            'puntualidad' => [
                'title' => 'Puntualidad', 'category' => 'asistencia', 'builder' => 'aggregate', 'landscape' => false,
                'description' => 'Días asistidos, puntuales y retardos por colaborador.',
                'options' => ['mode' => 'employee'],
                'columns' => [
                    new C('employee_name', 'Colaborador', width: 22), new C('employee_number', 'Número', width: 9), new C('branch', 'Sucursal', width: 13),
                    new C('attended_days', 'Días asistidos', 'number', sum: true), new C('punctual_days', 'Días puntuales', 'number', sum: true),
                    new C('late_days', 'Retardos', 'number', sum: true), new C('late_minutes', 'Minutos de retardo', 'minutes', sum: true), $punctuality,
                ],
            ],
            'retardos' => [
                'title' => 'Retardos', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Entradas posteriores a la tolerancia del horario.',
                'options' => ['filter' => 'late'], 'groupings' => $byEmployee,
                'columns' => [...$emp(), ...$day, $schedule, new C('first_in', 'Entrada', 'time', width: 7), new C('late_minutes', 'Retardo', 'minutes', sum: true),
                    new C('incidence', 'Incidencia', width: 12), new C('rectification', 'Rectificación', width: 12), new C('observations', 'Observaciones', width: 16)],
            ],
            'faltas' => [
                'title' => 'Faltas', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Días programados sin marcaje ni justificación aprobada.',
                'options' => ['filter' => 'absent'], 'groupings' => $byEmployee,
                'columns' => [...$emp(), ...$day, $schedule, new C('scheduled_minutes', 'Jornada programada', 'minutes', sum: true),
                    new C('rectification', 'Rectificación', width: 14), new C('observations', 'Observaciones', width: 18)],
            ],
            'omisiones' => [
                'title' => 'Omisiones de checada', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Días sin entrada o sin salida; permanecen hasta una rectificación firmada.',
                'options' => ['filter' => 'omission', 'events' => true], 'groupings' => $byEmployee,
                'columns' => [...$emp(), ...$day, $schedule, new C('first_in', 'Entrada', 'time', width: 7), new C('last_out', 'Salida', 'time', width: 7),
                    new C('omission', 'Omisión', width: 11), new C('status_label', 'Estado', width: 10), new C('punch_origin', 'Origen del marcaje', width: 12),
                    new C('rectification', 'Rectificación', width: 13), new C('observations', 'Observaciones', width: 16)],
            ],
            'salidas_anticipadas' => [
                'title' => 'Salidas anticipadas', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Salidas antes del fin del horario programado.',
                'options' => ['filter' => 'early'], 'groupings' => $byEmployee,
                'columns' => [...$emp(), ...$day, $schedule, new C('scheduled_end', 'Fin programado', 'time', width: 7), new C('last_out', 'Salida', 'time', width: 7),
                    new C('early_leave_minutes', 'Salida anticipada', 'minutes', sum: true), new C('incidence', 'Incidencia', width: 12), new C('rectification', 'Rectificación', width: 12)],
            ],
            'descansos' => [
                'title' => 'Descansos y comidas', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Pausas registradas durante la jornada.',
                'options' => ['filter' => 'breaks', 'events' => true], 'groupings' => $byEmployee,
                'columns' => [...$emp(), ...$day, $schedule, new C('break_start', 'Inicio descanso', 'time', width: 7), new C('break_end', 'Término descanso', 'time', width: 7),
                    new C('meal_start', 'Inicio comida', 'time', width: 7), new C('meal_end', 'Término comida', 'time', width: 7),
                    new C('break_minutes', 'Tiempo de descanso', 'minutes', sum: true), new C('omission', 'Omisión', width: 11), new C('observations', 'Observaciones', width: 16)],
            ],
            'horarios_programados' => [
                'title' => 'Horarios programados', 'category' => 'asistencia', 'builder' => 'days',
                'description' => 'Horario asignado por día, con descansos y días no laborables.',
                'groupings' => $byEmployeeWeek, 'status' => $status,
                'columns' => [...$emp(), ...$day, $schedule, new C('shift_kind', 'Jornada', width: 8), new C('scheduled_start', 'Inicio', 'time', width: 7),
                    new C('scheduled_end', 'Fin', 'time', width: 7), new C('scheduled_minutes', 'Tiempo programado', 'minutes', sum: true), new C('status_label', 'Estado', width: 11)],
            ],

            // ── Tiempo de trabajo ───────────────────────────────────
            'horas_trabajadas' => [
                'title' => 'Horas trabajadas', 'category' => 'tiempo', 'builder' => 'aggregate',
                'description' => 'Tiempo efectivo, ordinario, tiempo extra y nocturno por colaborador.',
                'options' => ['mode' => 'employee'],
                'columns' => [...$emp(false), new C('attended_days', 'Días asistidos', 'number', sum: true),
                    new C('worked_minutes', 'Tiempo efectivo', 'minutes', sum: true), new C('ordinary_minutes', 'Ordinario', 'minutes', sum: true),
                    new C('extra_minutes', 'Tiempo extra', 'minutes', sum: true), new C('night_minutes', 'Nocturno', 'minutes', sum: true)],
            ],
            'horas_extra' => [
                'title' => 'Horas extraordinarias', 'category' => 'tiempo', 'builder' => 'overtime', 'status' => $approval,
                'description' => 'Tiempo extra registrado, autorizado, dobles, triples y sobre límite legal (cálculo semanal).',
                'options' => ['only' => 'all'], 'groupings' => $byEmployee,
                'columns' => $overtimeCols([
                    new C('recorded_excess_minutes', 'Tiempo extra registrado', 'minutes', sum: true), new C('double_minutes', 'Dobles', 'minutes', sum: true),
                    new C('triple_minutes', 'Triples', 'minutes', sum: true), new C('over_limit_minutes', 'Sobre límite', 'minutes', sum: true),
                    new C('authorized_minutes', 'Autorizado', 'minutes', sum: true), new C('justified_minutes', 'Justificado', 'minutes', sum: true),
                    new C('pending_minutes', 'Pendiente', 'minutes', sum: true),
                ]),
            ],
            'horas_dobles' => [
                'title' => 'Horas dobles', 'category' => 'tiempo', 'builder' => 'overtime', 'status' => $approval,
                'description' => 'Tiempo extra que se paga al doble (LFT art. 67).',
                'options' => ['only' => 'double'], 'groupings' => $byEmployee,
                'columns' => $overtimeCols([
                    new C('recorded_excess_minutes', 'Tiempo extra registrado', 'minutes', sum: true), new C('double_minutes', 'Dobles', 'minutes', sum: true),
                    new C('authorized_minutes', 'Autorizado', 'minutes', sum: true),
                ]),
            ],
            'horas_triples' => [
                'title' => 'Horas triples', 'category' => 'tiempo', 'builder' => 'overtime', 'status' => $approval,
                'description' => 'Tiempo extra que rebasa el tope semanal y se paga al triple (LFT art. 68).',
                'options' => ['only' => 'triple'], 'groupings' => $byEmployee,
                'columns' => $overtimeCols([
                    new C('recorded_excess_minutes', 'Tiempo extra registrado', 'minutes', sum: true), new C('triple_minutes', 'Triples', 'minutes', sum: true),
                    new C('over_limit_minutes', 'Sobre límite', 'minutes', sum: true), new C('authorized_minutes', 'Autorizado', 'minutes', sum: true),
                ]),
            ],

            // ── Concentrados ────────────────────────────────────────
            'consolidada' => [
                'title' => 'Asistencia consolidada', 'category' => 'concentrados', 'builder' => 'aggregate',
                'description' => 'Un renglón por colaborador con subtotales por sucursal.', 'paper' => 'legal',
                'options' => ['mode' => 'employee_branch'], 'groupings' => [new ReportGrouping('_branch_key', '_branch_label', 'Subtotal')],
                'columns' => [
                    new C('employee_name', 'Colaborador', width: 20), new C('employee_number', 'Número', width: 8), new C('department', 'Departamento', width: 12),
                    new C('position', 'Puesto', width: 12), new C('branch', 'Sucursal', hoist: true, width: 12),
                    new C('scheduled_days', 'Días programados', 'number', sum: true, width: 8), new C('attended_days', 'Días asistidos', 'number', sum: true, width: 8),
                    new C('absent_days', 'Faltas', 'number', sum: true, width: 6), new C('late_days', 'Retardos', 'number', sum: true, width: 7),
                    new C('omission_days', 'Omisiones', 'number', sum: true, width: 7), new C('vacation_days', 'Vacaciones', 'number', sum: true, width: 8),
                    new C('leave_days', 'Permisos', 'number', sum: true, width: 7), new C('rest_days', 'Descansos', 'number', sum: true, width: 7),
                    new C('holiday_days', 'Festivos', 'number', sum: true, width: 7),
                    new C('worked_minutes', 'Tiempo efectivo', 'minutes', sum: true, width: 8), new C('ordinary_minutes', 'Ordinario', 'minutes', sum: true, width: 8),
                    new C('extra_minutes', 'Tiempo extra', 'minutes', sum: true, width: 9), $punctuality,
                ],
            ],
            'por_sucursal' => ['title' => 'Por sucursal', 'category' => 'concentrados', 'description' => 'Asistencia y tiempo trabajado por sucursal.'] + $dimension('Sucursal', 'branch'),
            'por_region' => ['title' => 'Por región', 'category' => 'concentrados', 'description' => 'Asistencia y tiempo trabajado por región.'] + $dimension('Región', 'region'),
            'por_departamento' => ['title' => 'Por departamento', 'category' => 'concentrados', 'description' => 'Asistencia y tiempo trabajado por departamento.'] + $dimension('Departamento', 'department'),
            'por_puesto' => ['title' => 'Por puesto', 'category' => 'concentrados', 'description' => 'Asistencia y tiempo trabajado por puesto.'] + $dimension('Puesto', 'position'),

            // ── Incidencias y ausencias ─────────────────────────────
            'incidencias' => [
                'title' => 'Incidencias', 'category' => 'ausencias', 'builder' => 'records', 'status' => $approval,
                'description' => 'Permisos, incapacidades y demás incidencias con su resolución.',
                'options' => ['query' => 'incidences'], 'groupings' => $byEmployee,
                'columns' => [...$emp(), new C('folio', 'Folio', width: 8), new C('type_name', 'Tipo', width: 14), new C('starts_on', 'Inicio', 'date'),
                    new C('ends_on', 'Fin', 'date'), new C('days', 'Días', 'number', sum: true, width: 6), new C('source_label', 'Registró', width: 10),
                    new C('status_label', 'Estado', width: 9), new C('reviewed_by', 'Resolvió', width: 13), new C('evidence', 'Evidencia', width: 11), new C('reason', 'Motivo', width: 20)],
            ],
            'omisiones_incidencias_evidencia' => [
                'title' => 'Omisiones e incidencias con evidencia', 'category' => 'ausencias', 'builder' => 'evidence', 'status' => $approval,
                'description' => 'Cada omisión e incidencia con su evidencia, rectificación y firma.',
                'groupings' => $byEmployee,
                'columns' => [...$emp(), new C('date', 'Fecha', 'date'), new C('kind_label', 'Tipo', width: 11), new C('concept', 'Concepto', width: 13),
                    new C('detail', 'Detalle', width: 18), new C('status_label', 'Estado', width: 11), new C('evidence', 'Evidencia (huella)', width: 20),
                    new C('evidence_count', 'Archivos', 'number', sum: true, width: 6), new C('rectification', 'Rectificación', width: 9),
                    new C('signed_at', 'Firma (fecha)', 'datetime', width: 13), new C('signature_method', 'Método de firma', width: 13), new C('signature_seal', 'Sello', 'hash', width: 13)],
            ],
            'vacaciones' => [
                'title' => 'Vacaciones', 'category' => 'ausencias', 'builder' => 'records', 'status' => $approval, 'landscape' => false,
                'description' => 'Solicitudes de vacaciones con días, año de servicio y resolución.',
                'options' => ['query' => 'vacations'], 'groupings' => $byEmployee,
                'columns' => [...$emp(), new C('starts_on', 'Inicio', 'date'), new C('ends_on', 'Fin', 'date'), new C('days', 'Días', 'number', sum: true),
                    new C('service_year', 'Año de servicio', 'number'), new C('status_label', 'Estado'), new C('reviewed_by', 'Resolvió', width: 18)],
            ],

            // ── Legal e integridad ──────────────────────────────────
            'rectificaciones_firmas' => [
                'title' => 'Rectificaciones y firmas', 'category' => 'legal', 'builder' => 'records', 'status' => RectificationStage::options(), 'paper' => 'legal',
                'description' => 'Cada rectificación con original, propuesta, evidencia, autorización, firma del colaborador y validación RH.',
                'options' => ['query' => 'rectifications'], 'groupings' => $byEmployee,
                'columns' => [...$emp(), new C('folio', 'Folio', width: 8), new C('date', 'Fecha', 'date'), new C('requested_at', 'Solicitada', 'datetime', width: 12),
                    new C('kind_label', 'Tipo', width: 11), new C('original', 'Original', width: 10), new C('proposed', 'Propuesta', width: 10),
                    new C('reason', 'Motivo', width: 16), new C('evidence', 'Evidencia (huella)', width: 18), new C('authorized_by', 'Autorizó', width: 12),
                    new C('stage_label', 'Etapa', width: 11), new C('signed_at', 'Firma (fecha)', 'datetime', width: 12),
                    new C('signature_method', 'Método de firma', width: 11), new C('signature_device', 'Dispositivo', width: 11),
                    new C('signature_seal', 'Sello', 'hash', width: 12), new C('validated_by', 'Validó RH', width: 12)],
            ],
            'cumplimiento' => [
                'title' => 'Cumplimiento laboral', 'category' => 'legal', 'builder' => 'records',
                'status' => [['value' => 'open', 'label' => 'Abierta'], ['value' => 'acknowledged', 'label' => 'Atendida'], ['value' => 'resolved', 'label' => 'Resuelta']],
                'description' => 'Alertas por regla, severidad y fundamento legal, con su atención.',
                'options' => ['query' => 'compliance'],
                'columns' => [...$emp(false), new C('date', 'Fecha', 'date'), new C('severity_label', 'Severidad', width: 9), new C('message', 'Alerta', width: 26),
                    new C('legal_basis', 'Fundamento', width: 16), new C('status_label', 'Estado', width: 9), new C('resolution_note', 'Atención', width: 18)],
            ],
            'historial_marcajes' => [
                'title' => 'Historial de marcajes', 'category' => 'legal', 'builder' => 'records', 'paper' => 'legal',
                'description' => 'Marcajes originales inmutables con origen, autenticación, ubicación y hash.',
                'options' => ['query' => 'punches'], 'columns' => $punchCols,
            ],
            'inspeccion_laboral' => [
                'title' => 'Reporte para inspección laboral', 'category' => 'legal', 'builder' => 'records', 'paper' => 'legal', 'featured' => true,
                'description' => 'Registro original de marcajes con verificación de integridad (cadena de hash y anclas) al generarse.',
                'options' => ['query' => 'punches', 'integrity' => true],
                'columns' => [...$punchCols, new C('previous_hash', 'Hash anterior', 'hash', width: 14)],
            ],

        ];

        $out = [];
        foreach ($defs as $key => $d) {
            $filters = ['branch', 'department', 'position', 'region', 'employee'];
            if (! empty($d['status'])) {
                $filters[] = 'status';
            }
            $out[$key] = new ReportDefinition(
                key: $key,
                title: $d['title'],
                description: $d['description'],
                category: $d['category'],
                categoryLabel: self::CATEGORIES[$d['category']],
                builder: $d['builder'],
                columns: $d['columns'],
                groupings: $d['groupings'] ?? [],
                options: $d['options'] ?? [],
                landscape: $d['landscape'] ?? true,
                paper: $d['paper'] ?? 'letter',
                statusOptions: $d['status'] ?? [],
                requiresEmployee: $d['requires_employee'] ?? false,
                featured: $d['featured'] ?? false,
                maxDays: $d['max_days'] ?? null,
                filters: [...$filters, ...($d['filters_extra'] ?? [])],
            );
        }

        self::$cache = $out;

        return $withNoi ? $out + ['noi_incidencias' => self::noiDefinition()] : $out;
    }

    private static function noiDefinition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'noi_incidencias',
            title: 'Exportación para NOI',
            description: 'Faltas, incidencias y horas extra autorizadas en el layout configurado para NOI.',
            category: 'nomina',
            categoryLabel: self::CATEGORIES['nomina'],
            builder: 'noi',
            columns: self::noiColumns(),
            landscape: false,
        );
    }

    /**
     * Columnas del layout NOI según la configuración vigente.
     *
     * @return list<C>
     */
    private static function noiColumns(): array
    {
        return array_map(fn (array $c) => new C(
            $c['key'],
            $c['label'],
            match ($c['key']) {
                'date' => 'date',
                'quantity' => 'decimal',
                default => 'text',
            },
            width: match ($c['key']) {
                'employee_name' => 22,
                'concept_name', 'concept_code' => 18,
                default => 11,
            },
        ), NoiConfig::current()['columns']);
    }

    /** Olvida el catálogo en memoria (p. ej. tras cambiar el layout NOI). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Forma serializable de todo el catálogo (compatibilidad con la UI).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(fn (ReportDefinition $d) => $d->toArray(), self::definitions());
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        // Las llaves no dependen de la configuración: no se arma el layout NOI.
        self::definitions(withNoi: false);

        return [...array_keys(self::$cache ?? []), 'noi_incidencias'];
    }

    /**
     * Definición de un reporte por clave, o null si no existe.
     */
    public static function definition(string $key): ?ReportDefinition
    {
        return self::definitions()[$key] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::definition($key)?->toArray();
    }
}
