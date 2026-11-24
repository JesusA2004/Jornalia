import { test } from './fixtures';
import type { RolePages } from './fixtures';
import { DETALLES, EVIDENCE_PROJECTS, PANTALLAS } from './pantallas';
import {
    assertClean,
    firstHref,
    inspectLayout,
    record,
    settle,
    shot,
    THEMES,
} from './support';
import type { RoleKey, Theme } from './support';

/**
 * Recorrido responsivo: cada pantalla, en claro y oscuro, en el navegador y
 * ancho del proyecto. Falla ante HTTP ≥ 400, errores JS/consola, solicitudes
 * fallidas, desborde horizontal, elementos fuera del viewport, textos
 * encimados, scroll doble, textos prohibidos u objetivos táctiles < 24 px.
 */

const detailCache = new Map<string, string | null>();

async function review(
    rolePages: RolePages,
    role: RoleKey,
    name: string,
    url: string,
    theme: Theme,
    project: string,
): Promise<void> {
    const { page, watched } = await rolePages.get(role);
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    const response = await page.goto(url);
    await settle(page);

    const layout = await inspectLayout(page);
    const status = response?.status() ?? null;
    const label = `${name} (${theme})`;
    const expectedStatus = name.startsWith('error-') ? 404 : null;

    record({
        project,
        name,
        theme,
        url,
        status,
        console: watched.console,
        pageErrors: watched.pageErrors,
        badResponses: watched.badResponses,
        ...layout,
    });

    if (EVIDENCE_PROJECTS.has(project)) {
        await shot(page, `${project}/${name}-${theme}`, true);
    }

    if (expectedStatus) {
        // La propia página 404 genera una respuesta 404 esperada.
        watched.badResponses.splice(
            0,
            watched.badResponses.length,
            ...watched.badResponses.filter((r) => !r.startsWith('404')),
        );
        test.expect.soft(status, `HTTP ${label}`).toBe(expectedStatus);
        assertClean(label, 200, watched, layout);

        return;
    }

    assertClean(label, status, watched, layout);
}

for (const pantalla of PANTALLAS) {
    for (const theme of THEMES) {
        test(`${pantalla.name} · ${theme}`, async ({ rolePages }, info) => {
            await review(
                rolePages,
                pantalla.role,
                pantalla.name,
                pantalla.url,
                theme,
                info.project.name,
            );
        });
    }
}

for (const detalle of DETALLES) {
    for (const theme of THEMES) {
        test(`${detalle.name} · ${theme}`, async ({ rolePages }, info) => {
            if (!detailCache.has(detalle.name)) {
                const { page } = await rolePages.get(detalle.role);
                await page.goto(detalle.from);
                await settle(page);
                detailCache.set(
                    detalle.name,
                    await firstHref(page, detalle.pattern),
                );
            }

            const href = detailCache.get(detalle.name);
            test.skip(!href, `Sin registros para ${detalle.name}`);
            await review(
                rolePages,
                detalle.role,
                detalle.name,
                href as string,
                theme,
                info.project.name,
            );
        });
    }
}
