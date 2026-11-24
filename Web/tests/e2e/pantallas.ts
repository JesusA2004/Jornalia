import type { RoleKey } from './support';

/**
 * Catálogo de pantallas de la web y el rol con el que se revisan.
 * RH opera; Sistemas («plataforma») administra usuarios, organización y
 * configuración técnica; «rh_vacia» es una organización sin datos (estados vacíos).
 */
export type Pantalla = {
    name: string;
    url: string;
    role: RoleKey;
};

export const PANTALLAS: Pantalla[] = [
    { name: 'panel', url: '/dashboard', role: 'rh' },
    { name: 'asistencias', url: '/asistencias', role: 'rh' },
    {
        name: 'asistencias-marcajes',
        url: '/asistencias?tab=marcajes',
        role: 'rh',
    },
    { name: 'ubicacion', url: '/ubicacion', role: 'rh' },
    { name: 'rectificaciones', url: '/rectificaciones', role: 'rh' },
    { name: 'incidencias', url: '/incidencias', role: 'rh' },
    { name: 'vacaciones', url: '/vacaciones', role: 'rh' },
    { name: 'horas-extra', url: '/horas-extra', role: 'rh' },
    { name: 'calendario', url: '/calendario', role: 'rh' },
    { name: 'colaboradores', url: '/colaboradores', role: 'rh' },
    { name: 'colaboradores-nuevo', url: '/colaboradores/nuevo', role: 'rh' },
    {
        name: 'colaboradores-importar',
        url: '/colaboradores/importar',
        role: 'rh',
    },
    { name: 'horarios', url: '/horarios', role: 'rh' },
    { name: 'horarios-nuevo', url: '/horarios/nuevo', role: 'rh' },
    { name: 'horarios-asignacion', url: '/horarios/asignacion', role: 'rh' },
    { name: 'horarios-cambios', url: '/horarios/cambios', role: 'rh' },
    { name: 'dispositivos', url: '/dispositivos', role: 'rh' },
    { name: 'comunicados', url: '/comunicados', role: 'rh' },
    { name: 'comunicados-nuevo', url: '/comunicados/nuevo', role: 'rh' },
    { name: 'reportes', url: '/reportes', role: 'rh' },
    { name: 'cumplimiento', url: '/cumplimiento', role: 'rh' },
    {
        name: 'cumplimiento-integridad',
        url: '/cumplimiento/integridad',
        role: 'rh',
    },
    {
        name: 'cumplimiento-bitacora',
        url: '/cumplimiento/bitacora',
        role: 'rh',
    },
    { name: 'notificaciones', url: '/notificaciones', role: 'rh' },
    { name: 'organizacion', url: '/organizacion', role: 'plataforma' },
    { name: 'usuarios', url: '/usuarios', role: 'plataforma' },
    { name: 'usuarios-roles', url: '/usuarios/roles', role: 'plataforma' },
    {
        name: 'usuarios-revision',
        url: '/usuarios/revision',
        role: 'plataforma',
    },
    { name: 'integraciones', url: '/integraciones', role: 'plataforma' },
    { name: 'configuracion', url: '/configuracion', role: 'plataforma' },
    {
        name: 'configuracion-sistema',
        url: '/configuracion/sistema',
        role: 'plataforma',
    },
    {
        name: 'configuracion-asistencia',
        url: '/configuracion/asistencia',
        role: 'rh',
    },
    {
        name: 'configuracion-autorizaciones',
        url: '/configuracion/autorizaciones',
        role: 'rh',
    },
    {
        name: 'configuracion-incidencias',
        url: '/configuracion/incidencias',
        role: 'rh',
    },
    {
        name: 'configuracion-vacaciones',
        url: '/configuracion/vacaciones',
        role: 'rh',
    },
    {
        name: 'configuracion-festivos',
        url: '/configuracion/festivos',
        role: 'rh',
    },
    {
        name: 'configuracion-documentos',
        url: '/configuracion/documentos',
        role: 'rh',
    },
    {
        name: 'configuracion-normativa',
        url: '/configuracion/normativa',
        role: 'rh',
    },
    {
        name: 'configuracion-ubicacion',
        url: '/configuracion/ubicacion',
        role: 'rh',
    },
    { name: 'cuenta-perfil', url: '/settings/profile', role: 'rh' },
    { name: 'cuenta-seguridad', url: '/settings/security', role: 'rh' },
    { name: 'cuenta-apariencia', url: '/settings/appearance', role: 'rh' },
    { name: 'error-404', url: '/no-existe-esta-pagina', role: 'rh' },

    // Estados vacíos (organización sin datos).
    { name: 'vacio-panel', url: '/dashboard', role: 'rh_vacia' },
    { name: 'vacio-asistencias', url: '/asistencias', role: 'rh_vacia' },
    {
        name: 'vacio-rectificaciones',
        url: '/rectificaciones',
        role: 'rh_vacia',
    },
    { name: 'vacio-colaboradores', url: '/colaboradores', role: 'rh_vacia' },
    { name: 'vacio-horas-extra', url: '/horas-extra', role: 'rh_vacia' },
    { name: 'vacio-reportes', url: '/reportes', role: 'rh_vacia' },
    { name: 'vacio-cumplimiento', url: '/cumplimiento', role: 'rh_vacia' },

    // Alcance restringido.
    { name: 'gerente-panel', url: '/dashboard', role: 'gerente' },
    { name: 'gerente-asistencias', url: '/asistencias', role: 'gerente' },
    { name: 'autoridad-reportes', url: '/reportes', role: 'autoridad' },
    {
        name: 'autoridad-asistencias',
        url: '/asistencias',
        role: 'autoridad',
    },
];

/** Detalles descubiertos a partir de los listados con datos reales. */
export type Detalle = {
    name: string;
    from: string;
    pattern: RegExp;
    role: RoleKey;
};

export const DETALLES: Detalle[] = [
    {
        name: 'asistencias-detalle',
        from: '/asistencias',
        pattern: /^\/asistencias\/\d+\/\d{4}-\d{2}-\d{2}/,
        role: 'rh',
    },
    {
        name: 'colaborador-expediente',
        from: '/colaboradores',
        pattern: /^\/colaboradores\/\d+$/,
        role: 'rh',
    },
    {
        name: 'horario-editar',
        from: '/horarios',
        pattern: /^\/horarios\/\d+\/editar$/,
        role: 'rh',
    },
    {
        name: 'reporte-inspeccion',
        from: '/reportes',
        pattern: /^\/reportes\/inspeccion_laboral/,
        role: 'rh',
    },
    {
        name: 'rectificacion-detalle',
        from: '/rectificaciones',
        pattern: /^\/rectificaciones\/\d+$/,
        role: 'rh',
    },
    {
        name: 'incidencia-detalle',
        from: '/incidencias',
        pattern: /^\/incidencias\/\d+$/,
        role: 'rh',
    },
    {
        name: 'vacaciones-detalle',
        from: '/vacaciones',
        pattern: /^\/vacaciones\/\d+$/,
        role: 'rh',
    },
    {
        name: 'comunicado-detalle',
        from: '/comunicados',
        pattern: /^\/comunicados\/\d+$/,
        role: 'rh',
    },
];

/** Proyectos que guardan capturas de evidencia (página completa). */
export const EVIDENCE_PROJECTS = new Set([
    'chromium-320',
    'chromium-390',
    'chromium-768',
    'chromium-1440',
    'firefox-1366',
    'webkit-390',
    'webkit-1280',
]);
