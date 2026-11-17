import { test } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
    assertClean,
    firstHref,
    inspect,
    record,
    setTheme,
    settle,
    shot,
    VIEWPORTS,
    watch,
} from './support';
import type { Theme, Viewport } from './support';

/**
 * Recorrido completo: cada pantalla en escritorio y móvil, modo claro y oscuro.
 * Verifica HTTP, consola (errores + warnings de Vue), desbordes, textos prohibidos; guarda capturas.
 */
const RUTAS: Array<[string, string]> = [
    ['dashboard', '/dashboard'],
    ['asistencias', '/asistencias'],
    ['ubicacion', '/ubicacion'],
    ['rectificaciones', '/rectificaciones'],
    ['incidencias', '/incidencias'],
    ['vacaciones', '/vacaciones'],
    ['horas-extra', '/horas-extra'],
    ['calendario', '/calendario'],
    ['reportes', '/reportes'],
    ['cumplimiento', '/cumplimiento'],
    ['integridad', '/integridad'],
    ['auditoria', '/auditoria'],
    ['colaboradores', '/colaboradores'],
    ['colaboradores-nuevo', '/colaboradores/nuevo'],
    ['colaboradores-importar', '/colaboradores/importar'],
    ['horarios', '/horarios'],
    ['horarios-nuevo', '/horarios/nuevo'],
    ['horarios-asignacion', '/horarios/asignacion'],
    ['horarios-excepciones', '/horarios/excepciones'],
    ['dispositivos', '/dispositivos'],
    ['organizacion', '/organizacion'],
    ['usuarios', '/usuarios'],
    ['usuarios-roles', '/usuarios/roles'],
    ['configuracion', '/configuracion'],
    ['configuracion-asistencia', '/configuracion/asistencia'],
    ['configuracion-autorizaciones', '/configuracion/autorizaciones'],
    ['configuracion-documentos', '/configuracion/documentos'],
    ['configuracion-festivos', '/configuracion/festivos'],
    ['configuracion-incidencias', '/configuracion/incidencias'],
    ['configuracion-normativa', '/configuracion/normativa'],
    ['configuracion-vacaciones', '/configuracion/vacaciones'],
    ['notificaciones', '/notificaciones'],
    ['settings-profile', '/settings/profile'],
    ['settings-security', '/settings/security'],
    ['settings-appearance', '/settings/appearance'],
];

/** Detalles descubiertos a partir de los listados con datos reales (en orden: `@clave` usa uno previo). */
const DETALLES: Array<[string, string, RegExp]> = [
    [
        'asistencias-detalle',
        '/asistencias',
        /^\/asistencias\/\d+\/\d{4}-\d{2}-\d{2}/,
    ],
    ['colaboradores-expediente', '/colaboradores', /^\/colaboradores\/\d+$/],
    [
        'colaboradores-rostro',
        '@colaboradores-expediente',
        /^\/colaboradores\/\d+\/rostro$/,
    ],
    ['horarios-editar', '/horarios', /^\/horarios\/\d+\/editar$/],
    ['reportes-inspeccion', '/reportes', /^\/reportes\/inspeccion_laboral/],
    ['rectificaciones-detalle', '/rectificaciones', /^\/rectificaciones\/\d+$/],
    ['incidencias-detalle', '/incidencias', /^\/incidencias\/\d+$/],
    ['vacaciones-detalle', '/vacaciones', /^\/vacaciones\/\d+$/],
];

const COMBOS: Array<[Viewport, Theme]> = [
    ['escritorio', 'light'],
    ['escritorio', 'dark'],
    ['movil', 'light'],
    ['movil', 'dark'],
];

async function visit(
    page: Page,
    name: string,
    url: string,
    viewport: Viewport,
    theme: Theme,
): Promise<void> {
    const { issues } = watch(page);
    const response = await page.goto(url);
    await settle(page);
    const found = await inspect(page, viewport);
    const full = {
        name: `${name}--${viewport}-${theme}`,
        url,
        status: response?.status() ?? null,
        ...issues,
        ...found,
    };
    await shot(page, full.name);
    record(full);
    assertClean(full);
}

const detailCache = new Map<string, string | null>();

for (const [viewport, theme] of COMBOS) {
    test.describe(`${viewport} · ${theme}`, () => {
        test.use({ viewport: VIEWPORTS[viewport] });

        test.beforeEach(async ({ context }) => {
            await setTheme(context, theme);
        });

        for (const [name, url] of RUTAS) {
            test(name, async ({ page }) => {
                await visit(page, name, url, viewport, theme);
            });
        }

        for (const [name, from, pattern] of DETALLES) {
            test(name, async ({ page }) => {
                if (!detailCache.has(name)) {
                    // `@clave` = partir de un detalle ya descubierto.
                    const origin = from.startsWith('@')
                        ? detailCache.get(from.slice(1))
                        : from;
                    test.skip(!origin, `Sin origen para ${name}`);
                    await page.goto(origin as string);
                    await settle(page);
                    let href = await firstHref(page, pattern);
                    const rowLink = page
                        .locator('[data-row-link]:visible')
                        .first();

                    if (!href && (await rowLink.count()) > 0) {
                        await rowLink.click();
                        await page
                            .waitForURL((u) => pattern.test(u.pathname), {
                                timeout: 10_000,
                            })
                            .catch(() => undefined);
                        href = pattern.test(new URL(page.url()).pathname)
                            ? new URL(page.url()).pathname
                            : null;
                    }

                    detailCache.set(name, href);
                }

                const href = detailCache.get(name);
                test.skip(!href, `Sin registros para ${name}`);
                await visit(page, name, href as string, viewport, theme);
            });
        }
    });
}

test.describe('Inicio de sesión (sin sesión)', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    for (const [viewport, theme] of COMBOS) {
        test(`login ${viewport} ${theme}`, async ({ page, context }) => {
            await page.setViewportSize(VIEWPORTS[viewport]);
            await setTheme(context, theme);
            await visit(page, 'login', '/login', viewport, theme);
        });
    }
});
