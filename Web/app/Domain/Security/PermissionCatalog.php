<?php

namespace App\Domain\Security;

/**
 * Catálogo de permisos `modulo.accion` y roles iniciales (configurables desde la web).
 */
final class PermissionCatalog
{
    /** @var array<string, array{label:string, actions:array<string,string>}> */
    public const MODULES = [
        'dashboard' => ['label' => 'Panel', 'actions' => ['view' => 'Ver panel']],
        'punches' => ['label' => 'Marcajes', 'actions' => ['view' => 'Ver marcajes', 'export' => 'Exportar marcajes', 'record_web' => 'Marcar desde web']],
        'locations' => ['label' => 'Ubicación en tiempo real', 'actions' => ['view' => 'Ver mapa y recorridos']],
        'rectifications' => ['label' => 'Rectificaciones', 'actions' => ['view' => 'Ver', 'create' => 'Solicitar a nombre de un colaborador', 'approve' => 'Aprobar o rechazar']],
        'incidences' => ['label' => 'Incidencias', 'actions' => ['view' => 'Ver', 'create' => 'Registrar', 'approve' => 'Aprobar o rechazar']],
        'vacations' => ['label' => 'Vacaciones', 'actions' => ['view' => 'Ver', 'create' => 'Registrar', 'approve' => 'Aprobar o rechazar', 'adjust' => 'Ajustar saldos']],
        'overtime' => ['label' => 'Horas extraordinarias', 'actions' => ['view' => 'Ver', 'approve' => 'Autorizar']],
        'calendar' => ['label' => 'Calendario', 'actions' => ['view' => 'Ver']],
        'employees' => ['label' => 'Colaboradores', 'actions' => ['view' => 'Ver', 'create' => 'Dar de alta', 'update' => 'Editar', 'terminate' => 'Dar de baja', 'view_sensitive' => 'Ver identificadores oficiales', 'import' => 'Importar']],
        'schedules' => ['label' => 'Horarios', 'actions' => ['view' => 'Ver', 'manage' => 'Administrar', 'assign' => 'Asignar']],
        'devices' => ['label' => 'Dispositivos', 'actions' => ['view' => 'Ver', 'approve' => 'Autorizar', 'revoke' => 'Revocar']],
        'reports' => ['label' => 'Reportes', 'actions' => ['view' => 'Ver', 'export' => 'Exportar']],
        'compliance' => ['label' => 'Cumplimiento', 'actions' => ['view' => 'Ver alertas', 'resolve' => 'Atender alertas']],
        'integrity' => ['label' => 'Integridad', 'actions' => ['view' => 'Ver verificación', 'verify' => 'Ejecutar verificación']],
        'communications' => ['label' => 'Comunicados', 'actions' => ['view' => 'Ver comunicados', 'send' => 'Enviar comunicados']],
        'audit' => ['label' => 'Auditoría', 'actions' => ['view' => 'Ver bitácora']],
        'organization' => ['label' => 'Organización', 'actions' => ['view' => 'Ver estructura', 'manage' => 'Administrar estructura']],
        'users' => ['label' => 'Usuarios y roles', 'actions' => ['view' => 'Ver', 'manage' => 'Administrar usuarios', 'roles' => 'Administrar roles']],
        'settings' => ['label' => 'Configuración', 'actions' => ['view' => 'Ver', 'manage' => 'Administrar políticas', 'legal' => 'Consultar normativa', 'documents' => 'Publicar documentos']],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::MODULES as $module => $def) {
            foreach (array_keys($def['actions']) as $action) {
                $all[] = "{$module}.{$action}";
            }
        }

        return $all;
    }

    /**
     * Roles iniciales. `*` = todos los permisos.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        $view = fn (array $modules) => array_map(fn ($m) => "{$m}.view", $modules);

        return [
            'Administrador de empresa' => ['*'],
            'Recursos Humanos' => array_values(array_diff(self::all(), ['users.roles', 'integrity.verify'])),
            'Responsable regional' => [
                ...$view(['dashboard', 'punches', 'locations', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees', 'schedules', 'devices', 'reports', 'compliance']),
                'rectifications.approve', 'incidences.approve', 'vacations.approve', 'overtime.approve', 'reports.export', 'devices.approve', 'compliance.resolve',
                'communications.view', 'communications.send',
            ],
            'Gerente de sucursal' => [
                ...$view(['dashboard', 'punches', 'locations', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees', 'schedules', 'devices', 'reports']),
                'rectifications.approve', 'incidences.approve', 'incidences.create', 'vacations.approve', 'overtime.approve', 'reports.export', 'devices.approve', 'schedules.assign',
                'communications.view', 'communications.send',
            ],
            'Supervisor' => [
                ...$view(['dashboard', 'punches', 'locations', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees']),
                'rectifications.approve', 'incidences.approve', 'vacations.approve',
            ],
            'Colaborador' => [],
            // Acceso de sólo lectura para inspectores de la autoridad laboral (STPS / centros
            // de conciliación): consultan y exportan registros de asistencia y su verificación.
            'Autoridad laboral' => [
                ...$view(['punches', 'calendar', 'employees', 'schedules', 'overtime', 'vacations', 'incidences', 'rectifications', 'reports', 'compliance', 'integrity']),
                'reports.export', 'punches.export',
            ],
            'Auditor de cumplimiento' => [
                ...$view(['dashboard', 'punches', 'locations', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees', 'schedules', 'reports', 'compliance', 'integrity', 'audit']),
                'reports.export', 'punches.export', 'integrity.verify', 'settings.legal',
            ],
        ];
    }
}
