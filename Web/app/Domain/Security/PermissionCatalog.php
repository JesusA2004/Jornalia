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
        'rectifications' => ['label' => 'Rectificaciones', 'actions' => ['view' => 'Ver', 'create' => 'Solicitar a nombre de un colaborador', 'approve' => 'Autorizar (jefe inmediato)', 'validate' => 'Validar y aplicar (RH)', 'apply_late' => 'Aplicar después del cierre de nómina']],
        'incidences' => ['label' => 'Incidencias', 'actions' => ['view' => 'Ver', 'create' => 'Registrar', 'approve' => 'Aprobar o rechazar', 'validate' => 'Validar (RH)', 'cancel' => 'Cancelar con motivo']],
        'vacations' => ['label' => 'Vacaciones', 'actions' => ['view' => 'Ver', 'create' => 'Registrar', 'approve' => 'Aprobar o rechazar', 'adjust' => 'Ajustar saldos']],
        'overtime' => ['label' => 'Horas extraordinarias', 'actions' => ['view' => 'Ver', 'approve' => 'Autorizar']],
        'calendar' => ['label' => 'Calendario', 'actions' => ['view' => 'Ver']],
        'employees' => ['label' => 'Colaboradores', 'actions' => ['view' => 'Ver', 'create' => 'Dar de alta', 'update' => 'Editar', 'terminate' => 'Dar de baja', 'view_sensitive' => 'Ver identificadores oficiales', 'import' => 'Importar']],
        'schedules' => ['label' => 'Horarios', 'actions' => ['view' => 'Ver', 'manage' => 'Administrar', 'assign' => 'Asignar', 'approve_changes' => 'Autorizar cambios de horario programados']],
        'devices' => ['label' => 'Dispositivos', 'actions' => ['view' => 'Ver', 'approve' => 'Autorizar', 'revoke' => 'Revocar', 'kiosk' => 'Administrar kioscos de sucursal']],
        'reports' => ['label' => 'Reportes', 'actions' => ['view' => 'Ver', 'export' => 'Exportar']],
        'compliance' => ['label' => 'Cumplimiento', 'actions' => ['view' => 'Ver alertas', 'resolve' => 'Atender alertas']],
        'integrity' => ['label' => 'Integridad', 'actions' => ['view' => 'Ver verificación', 'verify' => 'Ejecutar verificación']],
        'communications' => ['label' => 'Comunicados', 'actions' => ['view' => 'Ver comunicados', 'send' => 'Enviar comunicados']],
        'audit' => ['label' => 'Auditoría', 'actions' => ['view' => 'Ver bitácora']],
        'organization' => ['label' => 'Organización', 'actions' => ['view' => 'Ver estructura', 'manage' => 'Administrar estructura']],
        'users' => ['label' => 'Usuarios y roles', 'actions' => ['view' => 'Ver', 'manage' => 'Administrar usuarios', 'roles' => 'Administrar roles']],
        'settings' => ['label' => 'Configuración', 'actions' => ['view' => 'Ver', 'manage' => 'Administrar políticas', 'legal' => 'Consultar normativa', 'legal_manage' => 'Editar y verificar normativa (RH)', 'incidences' => 'Catálogo de incidencias (RH)', 'documents' => 'Publicar documentos']],
        'integrations' => ['label' => 'Integraciones', 'actions' => ['view' => 'Ver estado de People', 'sync' => 'Sincronizar con People']],
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

    /** Rol de Sistemas: administra la plataforma, nunca autoriza ni rectifica asistencia. */
    public const ROLE_PLATFORM_ADMIN = 'Administrador de plataforma';

    public const ROLE_HR_ADMIN = 'Administrador RH';

    public const ROLE_DIRECTION = 'Dirección';

    public const ROLE_REGIONAL = 'Responsable regional';

    public const ROLE_BRANCH_MANAGER = 'Gerente de sucursal';

    public const ROLE_COMPTROLLER = 'Contraloría';

    public const ROLE_AUTHORITY = 'Autoridad laboral';

    public const ROLE_EMPLOYEE = 'Colaborador';

    /**
     * Roles anteriores → rol equivalente vigente (migración 2026_11_24_120000).
     * «Administrador de empresa» pasa a «Administrador de plataforma» SIN permisos operativos
     * (separación de funciones, PDF pantalla 18).
     *
     * @var array<string, string>
     */
    public const LEGACY_ROLES = [
        'Administrador de empresa' => self::ROLE_PLATFORM_ADMIN,
        'Recursos Humanos' => self::ROLE_HR_ADMIN,
        'Auditor de cumplimiento' => self::ROLE_COMPTROLLER,
    ];

    /**
     * Permisos operativos sobre el registro de jornada: autorizar, validar, aplicar,
     * rectificar o registrar por cuenta de otro. `is_super_admin` y el administrador de
     * plataforma NUNCA los obtienen (regla dura de dominio).
     *
     * @var list<string>
     */
    public const OPERATIONAL = [
        'punches.record_web',
        'rectifications.create', 'rectifications.approve', 'rectifications.validate', 'rectifications.apply_late',
        'incidences.create', 'incidences.approve', 'incidences.validate', 'incidences.cancel',
        'vacations.create', 'vacations.approve', 'vacations.adjust',
        'overtime.approve',
        'schedules.approve_changes',
        'devices.approve',
        'compliance.resolve',
        'settings.legal_manage', 'settings.incidences',
    ];

    /**
     * Administración de usuarios y roles: incompatible con OPERATIONAL en un mismo usuario.
     *
     * @var list<string>
     */
    public const USER_ADMINISTRATION = ['users.manage', 'users.roles'];

    /**
     * Quien tenga cualquiera de estos permisos debe usar doble factor (PDF pantalla 18, punto 2).
     *
     * @var list<string>
     */
    public const TWO_FACTOR_REQUIRED = [
        'users.manage', 'users.roles',
        'settings.manage', 'settings.legal_manage', 'settings.incidences',
        'organization.manage', 'integrations.sync',
        'rectifications.validate', 'rectifications.apply_late',
        'employees.terminate', 'devices.kiosk',
    ];

    /**
     * Roles que exigen alcance por sucursal (no pueden tener «todas las sucursales»).
     *
     * @var list<string>
     */
    public const BRANCH_SCOPED_ROLES = [self::ROLE_REGIONAL, self::ROLE_BRANCH_MANAGER];

    /**
     * Roles iniciales (PDF pantalla 18). `*` = todos los permisos.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        $view = fn (array $modules): array => array_values(array_map(fn ($m) => "{$m}.view", $modules));
        $operationViews = $view(['dashboard', 'punches', 'locations', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees', 'schedules', 'reports', 'compliance']);

        return [
            // Sistemas: configuración técnica, usuarios, organización e integraciones.
            self::ROLE_PLATFORM_ADMIN => [
                ...$view(['dashboard', 'employees', 'devices', 'reports', 'compliance', 'integrity', 'audit', 'communications', 'organization', 'users', 'settings', 'integrations']),
                'users.manage', 'users.roles', 'organization.manage', 'settings.manage', 'settings.legal',
                'integrations.sync', 'devices.revoke', 'devices.kiosk', 'integrity.verify',
            ],
            // Operación completa, validación de rectificaciones y normativa; sin administrar usuarios.
            self::ROLE_HR_ADMIN => array_values(array_diff(self::all(), self::USER_ADMINISTRATION)),
            // PDF pantalla 04: la ubicación en tiempo real sólo la consultan RH, regional y gerente de sucursal.
            self::ROLE_DIRECTION => [...array_values(array_diff($operationViews, ['locations.view'])), 'reports.export', 'overtime.approve', 'communications.view'],
            self::ROLE_REGIONAL => [...$operationViews, 'reports.export', 'overtime.approve', 'communications.view', 'communications.send'],
            self::ROLE_BRANCH_MANAGER => [
                ...$view(['dashboard', 'punches', 'locations', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees', 'schedules', 'devices', 'reports']),
                'rectifications.approve', 'incidences.approve', 'incidences.create', 'vacations.approve', 'reports.export',
                'communications.view', 'communications.send',
            ],
            'Supervisor' => [
                ...$view(['dashboard', 'punches', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees']),
                'rectifications.approve', 'incidences.approve', 'vacations.approve',
            ],
            // Consulta, reportes, integridad y bitácora (sólo lectura).
            self::ROLE_COMPTROLLER => [
                ...$view(['dashboard', 'punches', 'rectifications', 'incidences', 'vacations', 'overtime', 'calendar', 'employees', 'schedules', 'reports', 'compliance', 'integrity', 'audit']),
                'reports.export', 'punches.export', 'settings.legal',
            ],
            self::ROLE_EMPLOYEE => [],
            // Inspectores (STPS / centros de conciliación): sólo lectura con vigencia.
            self::ROLE_AUTHORITY => [
                ...$view(['punches', 'calendar', 'employees', 'schedules', 'overtime', 'vacations', 'incidences', 'rectifications', 'reports', 'compliance', 'integrity', 'audit']),
                'reports.export', 'punches.export',
            ],
        ];
    }

    public static function isOperational(string $permission): bool
    {
        return in_array($permission, self::OPERATIONAL, true);
    }

    /**
     * Separación de funciones: administrar usuarios/roles y, a la vez, autorizar o
     * rectificar asistencia está prohibido.
     *
     * @param  iterable<string>  $permissions
     * @return list<string> permisos operativos en conflicto (vacío = sin conflicto)
     */
    public static function segregationConflicts(iterable $permissions): array
    {
        $list = is_array($permissions) ? array_values($permissions) : iterator_to_array($permissions, false);
        if (array_intersect($list, self::USER_ADMINISTRATION) === []) {
            return [];
        }

        return array_values(array_intersect($list, self::OPERATIONAL));
    }

    /**
     * @param  iterable<string>  $permissions
     */
    public static function requiresTwoFactor(iterable $permissions): bool
    {
        $list = is_array($permissions) ? array_values($permissions) : iterator_to_array($permissions, false);

        return array_intersect($list, self::TWO_FACTOR_REQUIRED) !== [];
    }
}
