import {
    Building2,
    CalendarDays,
    ChartColumnBig,
    ClipboardCheck,
    Clock3,
    FileClock,
    Fingerprint,
    LayoutDashboard,
    Megaphone,
    MapPin,
    ScrollText,
    Settings2,
    ShieldCheck,
    Smartphone,
    Sunrise,
    TimerReset,
    TriangleAlert,
    UserCog,
    Users,
} from '@lucide/vue';
import type { NavGroup, NavItem } from '@/types';

/**
 * Navegación principal de la aplicación.
 *
 * Las URL son cadenas (no Wayfinder) para no depender de rutas que otros
 * módulos aún no registran. `permission` se compara contra
 * `page.props.auth.permissions`.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Operación',
        items: [
            {
                title: 'Panel',
                href: '/dashboard',
                icon: LayoutDashboard,
                permission: 'dashboard.view',
                description: 'Resumen del día y alertas',
                keywords: ['inicio', 'dashboard', 'resumen'],
            },
            {
                title: 'Asistencias',
                href: '/asistencias',
                icon: Fingerprint,
                permission: 'punches.view',
                description: 'Marcajes de entrada y salida',
                keywords: ['marcajes', 'checadas', 'entradas', 'salidas'],
            },
            {
                title: 'Ubicación en tiempo real',
                href: '/ubicacion',
                icon: MapPin,
                permission: 'locations.view',
                description: 'Posición del personal en jornada',
                keywords: ['mapa', 'gps', 'recorrido', 'ubicación'],
            },
            {
                title: 'Rectificaciones',
                href: '/rectificaciones',
                icon: FileClock,
                permission: 'rectifications.view',
                description: 'Solicitudes de corrección de marcajes',
                keywords: ['correcciones', 'ajustes'],
            },
            {
                title: 'Incidencias',
                href: '/incidencias',
                icon: TriangleAlert,
                permission: 'incidences.view',
                description: 'Faltas, retardos, permisos e incapacidades',
                keywords: ['faltas', 'retardos', 'permisos', 'incapacidades'],
            },
            {
                title: 'Vacaciones',
                href: '/vacaciones',
                icon: Sunrise,
                permission: 'vacations.view',
                description: 'Saldos y solicitudes de vacaciones',
                keywords: ['descanso', 'prima vacacional'],
            },
            {
                title: 'Horas extra',
                href: '/horas-extra',
                icon: TimerReset,
                permission: 'overtime.view',
                description: 'Tiempo extraordinario dobles y triples',
                keywords: ['tiempo extra', 'dobles', 'triples'],
            },
            {
                title: 'Calendario',
                href: '/calendario',
                icon: CalendarDays,
                permission: 'calendar.view',
                description: 'Días festivos y de descanso',
                keywords: ['festivos', 'feriados', 'descansos'],
            },
        ],
    },
    {
        title: 'Personal',
        items: [
            {
                title: 'Colaboradores',
                href: '/colaboradores',
                icon: Users,
                permission: 'employees.view',
                description: 'Expedientes del personal',
                keywords: ['empleados', 'trabajadores', 'personal'],
            },
            {
                title: 'Horarios y turnos',
                href: '/horarios',
                icon: Clock3,
                permission: 'schedules.view',
                description: 'Jornadas, turnos y asignaciones',
                keywords: ['jornadas', 'turnos', 'roles'],
            },
            {
                title: 'Dispositivos',
                href: '/dispositivos',
                icon: Smartphone,
                permission: 'devices.view',
                description: 'Terminales y teléfonos autorizados',
                keywords: ['terminales', 'checadores', 'móviles'],
            },
            {
                title: 'Comunicados',
                href: '/comunicados',
                icon: Megaphone,
                permission: 'communications.view',
                description: 'Mensajes y avisos a la app',
                keywords: [
                    'notificaciones',
                    'push',
                    'avisos',
                    'mensajes',
                    'recordatorios',
                ],
            },
        ],
    },
    {
        title: 'Análisis',
        items: [
            {
                title: 'Reportes',
                href: '/reportes',
                icon: ChartColumnBig,
                permission: 'reports.view',
                description: 'Exportaciones y reportes de nómina',
                keywords: ['exportar', 'nómina', 'excel', 'pdf'],
            },
            {
                title: 'Cumplimiento',
                href: '/cumplimiento',
                icon: ClipboardCheck,
                permission: 'compliance.view',
                description: 'Indicadores de la Ley Federal del Trabajo',
                keywords: ['lft', 'legal', 'stps', 'norma'],
            },
            {
                title: 'Integridad',
                href: '/integridad',
                icon: ShieldCheck,
                permission: 'integrity.view',
                description: 'Verificación de la cadena de marcajes',
                keywords: ['hash', 'cadena', 'verificación'],
            },
            {
                title: 'Auditoría',
                href: '/auditoria',
                icon: ScrollText,
                permission: 'audit.view',
                description: 'Bitácora de acciones del sistema',
                keywords: ['bitácora', 'logs', 'registro'],
            },
        ],
    },
    {
        title: 'Administración',
        items: [
            {
                title: 'Organización',
                href: '/organizacion',
                icon: Building2,
                permission: 'organization.view',
                description: 'Empresa, centros de trabajo y áreas',
                keywords: ['empresa', 'centros de trabajo', 'sucursales'],
            },
            {
                title: 'Usuarios y roles',
                href: '/usuarios',
                icon: UserCog,
                permission: 'users.view',
                description: 'Accesos, roles y permisos',
                keywords: ['permisos', 'roles', 'accesos'],
            },
            {
                title: 'Configuración',
                href: '/configuracion',
                icon: Settings2,
                permission: 'settings.view',
                description: 'Parámetros generales de la plataforma',
                keywords: ['ajustes', 'parámetros', 'preferencias'],
            },
        ],
    },
];

/** URL de cuenta (perfil, seguridad, apariencia). */
export const accountNavigation: NavItem[] = [
    { title: 'Perfil', href: '/settings/profile' },
    { title: 'Seguridad', href: '/settings/security' },
    { title: 'Apariencia', href: '/settings/appearance' },
];

/**
 * Endpoint para cambiar la organización activa (PUT con `organization_id`).
 * Debe registrarlo el agente de rutas web; mientras no exista, el selector
 * mostrará un aviso de error.
 */
export const ORGANIZATION_SWITCH_URL = '/organizacion/activa';

/** Bandeja de notificaciones (enlace "Ver todas" de la campana). */
export const NOTIFICATIONS_URL = '/notificaciones';

/**
 * Comprueba un permiso. Si el backend aún no comparte
 * `auth.permissions` (undefined) no se filtra nada; si comparte un
 * arreglo, se exige el permiso. El comodín `*` concede todo.
 */
export function hasPermission(
    permissions: readonly string[] | undefined | null,
    permission: string | undefined,
): boolean {
    if (!permission || permissions === undefined || permissions === null) {
        return true;
    }

    return permissions.includes('*') || permissions.includes(permission);
}

/** Devuelve la navegación visible para el usuario (sin grupos vacíos). */
export function filterNavigation(
    groups: NavGroup[],
    permissions: readonly string[] | undefined | null,
): NavGroup[] {
    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) =>
                hasPermission(permissions, item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
}

/** Localiza el grupo y elemento que corresponden a una ruta. */
export function findNavigationMatch(
    pathname: string,
    groups: NavGroup[] = navigation,
): { group: NavGroup; item: NavItem } | null {
    let best: { group: NavGroup; item: NavItem; length: number } | null = null;

    for (const group of groups) {
        for (const item of group.items) {
            const href =
                typeof item.href === 'string' ? item.href : item.href.url;

            if (pathname === href || pathname.startsWith(`${href}/`)) {
                if (!best || href.length > best.length) {
                    best = { group, item, length: href.length };
                }
            }
        }
    }

    return best ? { group: best.group, item: best.item } : null;
}
