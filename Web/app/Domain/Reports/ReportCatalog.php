<?php

namespace App\Domain\Reports;

/**
 * Catálogo de reportes operativos y legales.
 *
 * Tipos de columna: text, date, datetime, time, minutes, number, percent, status, hash.
 * `sum` marca columnas totalizables.
 */
final class ReportCatalog
{
    private const EMPLOYEE = [
        ['key' => 'employee_number', 'label' => 'No. colaborador', 'type' => 'text'],
        ['key' => 'employee_name', 'label' => 'Colaborador', 'type' => 'text'],
        ['key' => 'branch', 'label' => 'Sucursal', 'type' => 'text'],
    ];

    /**
     * @return array<string, array{key:string,title:string,description:string,category:string,category_label:string,landscape:bool,columns:list<array{key:string,label:string,type:string,sum?:bool}>,status_options?:list<array{value:string,label:string}>}>
     */
    public static function all(): array
    {
        $daily = [
            ...self::EMPLOYEE,
            ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
            ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
            ['key' => 'scheduled_start', 'label' => 'Entrada programada', 'type' => 'time'],
            ['key' => 'scheduled_end', 'label' => 'Salida programada', 'type' => 'time'],
            ['key' => 'first_in', 'label' => 'Entrada', 'type' => 'time'],
            ['key' => 'last_out', 'label' => 'Salida', 'type' => 'time'],
            ['key' => 'worked_minutes', 'label' => 'Trabajado', 'type' => 'minutes', 'sum' => true],
            ['key' => 'late_minutes', 'label' => 'Retardo', 'type' => 'minutes', 'sum' => true],
            ['key' => 'excess_minutes', 'label' => 'Excedente', 'type' => 'minutes', 'sum' => true],
            ['key' => 'auto_clock_out_at', 'label' => 'Salida automática', 'type' => 'time'],
            ['key' => 'after_cutoff_minutes', 'label' => 'Después del cierre (no reconocido)', 'type' => 'minutes', 'sum' => true],
            ['key' => 'rectified', 'label' => 'Rectificada', 'type' => 'text'],
        ];
        $aggregate = [
            ...self::EMPLOYEE,
            ['key' => 'period', 'label' => 'Periodo', 'type' => 'text'],
            ['key' => 'scheduled_days', 'label' => 'Días programados', 'type' => 'number', 'sum' => true],
            ['key' => 'attended_days', 'label' => 'Días asistidos', 'type' => 'number', 'sum' => true],
            ['key' => 'absent_days', 'label' => 'Faltas', 'type' => 'number', 'sum' => true],
            ['key' => 'late_days', 'label' => 'Retardos', 'type' => 'number', 'sum' => true],
            ['key' => 'worked_minutes', 'label' => 'Trabajado', 'type' => 'minutes', 'sum' => true],
            ['key' => 'excess_minutes', 'label' => 'Excedente', 'type' => 'minutes', 'sum' => true],
        ];
        $group = fn (string $label) => [
            ['key' => 'group_name', 'label' => $label, 'type' => 'text'],
            ['key' => 'employees', 'label' => 'Colaboradores', 'type' => 'number', 'sum' => true],
            ['key' => 'scheduled_days', 'label' => 'Días programados', 'type' => 'number', 'sum' => true],
            ['key' => 'attended_days', 'label' => 'Días asistidos', 'type' => 'number', 'sum' => true],
            ['key' => 'absent_days', 'label' => 'Faltas', 'type' => 'number', 'sum' => true],
            ['key' => 'late_days', 'label' => 'Retardos', 'type' => 'number', 'sum' => true],
            ['key' => 'punctuality_rate', 'label' => 'Puntualidad', 'type' => 'percent'],
            ['key' => 'worked_minutes', 'label' => 'Trabajado', 'type' => 'minutes', 'sum' => true],
            ['key' => 'excess_minutes', 'label' => 'Excedente', 'type' => 'minutes', 'sum' => true],
        ];
        $approval = [
            ['value' => 'pending', 'label' => 'Pendiente'],
            ['value' => 'approved', 'label' => 'Aprobada'],
            ['value' => 'rejected', 'label' => 'Rechazada'],
            ['value' => 'cancelled', 'label' => 'Cancelada'],
        ];
        $punchColumns = [
            ...self::EMPLOYEE,
            ['key' => 'sequence', 'label' => 'Secuencia', 'type' => 'number'],
            ['key' => 'server_time', 'label' => 'Fecha y hora (servidor)', 'type' => 'datetime'],
            ['key' => 'type_label', 'label' => 'Tipo', 'type' => 'text'],
            ['key' => 'recognized_time', 'label' => 'Hora reconocida', 'type' => 'datetime'],
            ['key' => 'origin_label', 'label' => 'Registrado por', 'type' => 'text'],
            ['key' => 'source_label', 'label' => 'Origen', 'type' => 'text'],
            ['key' => 'auth_label', 'label' => 'Autenticación', 'type' => 'text'],
            ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
            ['key' => 'offline', 'label' => 'Sin conexión', 'type' => 'text'],
            ['key' => 'location', 'label' => 'Ubicación (precisión)', 'type' => 'text'],
            ['key' => 'anomalies', 'label' => 'Anomalías', 'type' => 'text'],
            ['key' => 'after_cutoff_minutes', 'label' => 'Después del cierre (no reconocido)', 'type' => 'minutes', 'sum' => true],
            ['key' => 'hash', 'label' => 'Hash', 'type' => 'hash'],
        ];

        $defs = [
            'asistencia_diaria' => ['title' => 'Asistencia diaria', 'description' => 'Jornada por colaborador y día: horario, entradas, salidas, tiempo trabajado, retardos y excedentes.', 'category' => 'asistencia', 'landscape' => true, 'columns' => $daily],
            'asistencia_semanal' => ['title' => 'Asistencia semanal', 'description' => 'Concentrado por colaborador y semana (lunes a domingo).', 'category' => 'asistencia', 'landscape' => true, 'columns' => $aggregate],
            'asistencia_mensual' => ['title' => 'Asistencia mensual', 'description' => 'Concentrado por colaborador y mes calendario.', 'category' => 'asistencia', 'landscape' => true, 'columns' => $aggregate],
            'puntualidad' => ['title' => 'Puntualidad', 'description' => 'Días asistidos, días puntuales y porcentaje de puntualidad por colaborador.', 'category' => 'asistencia', 'landscape' => false, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'attended_days', 'label' => 'Días asistidos', 'type' => 'number', 'sum' => true],
                ['key' => 'punctual_days', 'label' => 'Días puntuales', 'type' => 'number', 'sum' => true],
                ['key' => 'late_days', 'label' => 'Retardos', 'type' => 'number', 'sum' => true],
                ['key' => 'late_minutes', 'label' => 'Minutos de retardo', 'type' => 'minutes', 'sum' => true],
                ['key' => 'punctuality_rate', 'label' => 'Puntualidad', 'type' => 'percent'],
            ]],
            'retardos' => ['title' => 'Retardos', 'description' => 'Jornadas con entrada posterior a la tolerancia del horario.', 'category' => 'asistencia', 'landscape' => false, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
                ['key' => 'scheduled_start', 'label' => 'Entrada programada', 'type' => 'time'],
                ['key' => 'first_in', 'label' => 'Entrada registrada', 'type' => 'time'],
                ['key' => 'late_minutes', 'label' => 'Retardo', 'type' => 'minutes', 'sum' => true],
            ]],
            'faltas' => ['title' => 'Faltas', 'description' => 'Jornadas programadas sin asistencia registrada ni justificación aprobada.', 'category' => 'asistencia', 'landscape' => false, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
                ['key' => 'scheduled_start', 'label' => 'Entrada programada', 'type' => 'time'],
                ['key' => 'scheduled_minutes', 'label' => 'Jornada programada', 'type' => 'minutes', 'sum' => true],
            ]],
            'horas_trabajadas' => ['title' => 'Horas trabajadas', 'description' => 'Tiempo trabajado, ordinario, excedente y nocturno por colaborador.', 'category' => 'tiempo', 'landscape' => false, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'attended_days', 'label' => 'Días', 'type' => 'number', 'sum' => true],
                ['key' => 'worked_minutes', 'label' => 'Trabajado', 'type' => 'minutes', 'sum' => true],
                ['key' => 'ordinary_minutes', 'label' => 'Ordinario', 'type' => 'minutes', 'sum' => true],
                ['key' => 'excess_minutes', 'label' => 'Excedente', 'type' => 'minutes', 'sum' => true],
                ['key' => 'night_minutes', 'label' => 'Nocturno', 'type' => 'minutes', 'sum' => true],
                ['key' => 'after_cutoff_minutes', 'label' => 'Después del cierre (no reconocido)', 'type' => 'minutes', 'sum' => true],
            ]],
            'horas_extra' => ['title' => 'Horas extraordinarias', 'description' => 'Excedente registrado, autorizado, dobles, triples y tiempo sobre límite legal por día.', 'category' => 'tiempo', 'landscape' => true, 'status_options' => $approval, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
                ['key' => 'week_start', 'label' => 'Semana', 'type' => 'date'],
                ['key' => 'recorded_excess_minutes', 'label' => 'Excedente registrado', 'type' => 'minutes', 'sum' => true],
                ['key' => 'double_minutes', 'label' => 'Dobles', 'type' => 'minutes', 'sum' => true],
                ['key' => 'triple_minutes', 'label' => 'Triples', 'type' => 'minutes', 'sum' => true],
                ['key' => 'over_limit_minutes', 'label' => 'Sobre límite', 'type' => 'minutes', 'sum' => true],
                ['key' => 'authorized_minutes', 'label' => 'Autorizado', 'type' => 'minutes', 'sum' => true],
                ['key' => 'after_cutoff_minutes', 'label' => 'Después del cierre (no reconocido)', 'type' => 'minutes', 'sum' => true],
                ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
                ['key' => 'justification', 'label' => 'Justificación', 'type' => 'text'],
            ]],
            'vacaciones' => ['title' => 'Vacaciones', 'description' => 'Solicitudes de vacaciones del periodo con días, año de servicio y resolución.', 'category' => 'ausencias', 'landscape' => false, 'status_options' => $approval, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'starts_on', 'label' => 'Inicio', 'type' => 'date'],
                ['key' => 'ends_on', 'label' => 'Fin', 'type' => 'date'],
                ['key' => 'days', 'label' => 'Días', 'type' => 'number', 'sum' => true],
                ['key' => 'service_year', 'label' => 'Año de servicio', 'type' => 'number'],
                ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
                ['key' => 'reviewed_by', 'label' => 'Resolvió', 'type' => 'text'],
            ]],
            'incidencias' => ['title' => 'Incidencias', 'description' => 'Permisos, faltas justificadas, incapacidades y demás incidencias del periodo.', 'category' => 'ausencias', 'landscape' => true, 'status_options' => $approval, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'type_name', 'label' => 'Tipo', 'type' => 'text'],
                ['key' => 'starts_on', 'label' => 'Inicio', 'type' => 'date'],
                ['key' => 'ends_on', 'label' => 'Fin', 'type' => 'date'],
                ['key' => 'source_label', 'label' => 'Origen', 'type' => 'text'],
                ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
                ['key' => 'reason', 'label' => 'Motivo', 'type' => 'text'],
            ]],
            'cumplimiento' => ['title' => 'Cumplimiento laboral', 'description' => 'Alertas por regla, severidad y fundamento legal, con su atención.', 'category' => 'legal', 'landscape' => true, 'status_options' => [
                ['value' => 'open', 'label' => 'Abierta'], ['value' => 'acknowledged', 'label' => 'Atendida'], ['value' => 'resolved', 'label' => 'Resuelta'],
            ], 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
                ['key' => 'severity_label', 'label' => 'Severidad', 'type' => 'text'],
                ['key' => 'message', 'label' => 'Alerta', 'type' => 'text'],
                ['key' => 'legal_basis', 'label' => 'Fundamento', 'type' => 'text'],
                ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
                ['key' => 'resolution_note', 'label' => 'Atención', 'type' => 'text'],
            ]],
            'historial_marcajes' => ['title' => 'Historial de marcajes', 'description' => 'Marcajes originales inmutables con secuencia, método de autenticación, ubicación y hash.', 'category' => 'legal', 'landscape' => true, 'columns' => $punchColumns],
            'rectificaciones' => ['title' => 'Rectificaciones', 'description' => 'Solicitudes de corrección o aclaración, su resolución y responsable.', 'category' => 'legal', 'landscape' => true, 'status_options' => $approval, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
                ['key' => 'kind_label', 'label' => 'Tipo', 'type' => 'text'],
                ['key' => 'proposed', 'label' => 'Propuesta', 'type' => 'text'],
                ['key' => 'reason', 'label' => 'Motivo', 'type' => 'text'],
                ['key' => 'status_label', 'label' => 'Estado', 'type' => 'text'],
                ['key' => 'reviewed_by', 'label' => 'Resolvió', 'type' => 'text'],
                ['key' => 'review_comment', 'label' => 'Comentario', 'type' => 'text'],
            ]],
            'por_sucursal' => ['title' => 'Concentrado por sucursal', 'description' => 'Asistencia, puntualidad y tiempo trabajado agrupados por sucursal.', 'category' => 'concentrados', 'landscape' => true, 'columns' => $group('Sucursal')],
            'por_departamento' => ['title' => 'Concentrado por departamento', 'description' => 'Asistencia, puntualidad y tiempo trabajado agrupados por departamento.', 'category' => 'concentrados', 'landscape' => true, 'columns' => $group('Departamento')],
            'por_colaborador' => ['title' => 'Concentrado por colaborador', 'description' => 'Resumen integral del periodo por colaborador.', 'category' => 'concentrados', 'landscape' => true, 'columns' => [
                ...self::EMPLOYEE,
                ['key' => 'department', 'label' => 'Departamento', 'type' => 'text'],
                ['key' => 'scheduled_days', 'label' => 'Programados', 'type' => 'number', 'sum' => true],
                ['key' => 'attended_days', 'label' => 'Asistidos', 'type' => 'number', 'sum' => true],
                ['key' => 'absent_days', 'label' => 'Faltas', 'type' => 'number', 'sum' => true],
                ['key' => 'late_days', 'label' => 'Retardos', 'type' => 'number', 'sum' => true],
                ['key' => 'punctuality_rate', 'label' => 'Puntualidad', 'type' => 'percent'],
                ['key' => 'worked_minutes', 'label' => 'Trabajado', 'type' => 'minutes', 'sum' => true],
                ['key' => 'excess_minutes', 'label' => 'Excedente', 'type' => 'minutes', 'sum' => true],
                ['key' => 'after_cutoff_minutes', 'label' => 'Después del cierre (no reconocido)', 'type' => 'minutes', 'sum' => true],
            ]],
            'inspeccion_laboral' => ['title' => 'Reporte para inspección laboral', 'description' => 'Registro original de marcajes con datos de verificación de integridad (cadena de hash y anclas) al momento de la generación.', 'category' => 'legal', 'landscape' => true, 'columns' => $punchColumns],
        ];

        $labels = ['asistencia' => 'Asistencia', 'tiempo' => 'Tiempo de trabajo', 'ausencias' => 'Ausencias', 'legal' => 'Legal e integridad', 'concentrados' => 'Concentrados'];

        $out = [];
        foreach ($defs as $key => $def) {
            $out[$key] = ['key' => $key, 'category_label' => $labels[$def['category']]] + $def;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{key:string,title:string,description:string,category:string,category_label:string,landscape:bool,columns:list<array{key:string,label:string,type:string,sum?:bool}>}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
