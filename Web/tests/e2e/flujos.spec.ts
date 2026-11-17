import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { inspect, settle, shot, VIEWPORTS, watch } from './support';

/**
 * Interacciones principales (escritorio): menú ⌘K, tema, menú de usuario, integridad, reportes
 * (exportar y descargar XLSX/PDF/CSV), rectificación con selector de marcajes, calendario,
 * alta de colaborador (sin guardar), registro facial sin cámara y cierre de sesión.
 *
 * Nota: exportar crea registros de exportación (auditados), no marcajes ni catálogos.
 */
test.use({ viewport: VIEWPORTS.escritorio });

function clean(issues: ReturnType<typeof watch>['issues']): void {
    expect.soft(issues.pageErrors, 'Errores JS').toEqual([]);
    expect.soft(issues.console, 'Consola').toEqual([]);
    expect.soft(issues.badResponses, 'Respuestas con error').toEqual([]);
}

async function open(page: Page, url: string): Promise<void> {
    await page.goto(url);
    await settle(page);
}

test('menú ⌘K navega a una pantalla', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/dashboard');
    await page.keyboard.press('Control+k');
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await page.keyboard.type('Colaboradores');
    await shot(page, 'flujo-cmdk', false);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/colaboradores/);
    clean(issues);
});

test('cambio de tema y menú de usuario', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/dashboard');
    const html = page.locator('html');
    const wasDark = await html.evaluate((el) => el.classList.contains('dark'));
    await page.getByRole('button', { name: 'Cambiar tema' }).click();
    const item = page
        .getByRole('menuitemradio', { name: wasDark ? /Claro/i : /Oscuro/i })
        .or(
            page.getByRole('menuitem', {
                name: wasDark ? /Claro/i : /Oscuro/i,
            }),
        );

    if (await item.count()) {
        await item.first().click();
    }

    await expect
        .poll(() => html.evaluate((el) => el.classList.contains('dark')))
        .toBe(!wasDark);
    await shot(page, 'flujo-tema', false);

    await page.locator('[data-test="sidebar-menu-button"]').click();
    await expect(page.locator('[data-test="logout-button"]')).toBeVisible();
    await shot(page, 'flujo-menu-usuario', false);
    await page.keyboard.press('Escape');
    clean(issues);
});

test('integridad: verificar cadena', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/integridad');
    await page
        .getByRole('button', { name: /Verificar ahora/ })
        .first()
        .click();
    await settle(page);
    await page.waitForTimeout(1500);
    await shot(page, 'flujo-integridad-verificar');
    await expect(
        page.getByText(/íntegr|verificad|correct/i).first(),
    ).toBeVisible();
    clean(issues);
});

test('reportes: vista previa de varios reportes', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/reportes');
    const hrefs = await page
        .locator('a[href^="/reportes/"], a[href*="/reportes/"]')
        .evaluateAll((as) =>
            Array.from(
                new Set(
                    as.map(
                        (a) => new URL((a as HTMLAnchorElement).href).pathname,
                    ),
                ),
            ).filter((p) => /^\/reportes\/[a-z_]+$/.test(p)),
        );
    expect(hrefs.length).toBeGreaterThan(2);

    for (const href of hrefs) {
        await open(page, href);
        const found = await inspect(page, 'escritorio');
        expect.soft(found.overflow, `Desborde en ${href}`).toBeNull();
        await shot(page, `flujo-reporte-${href.split('/').pop()}`, false);
    }

    clean(issues);
});

test('reportes: exportar y descargar inspección laboral (XLSX, PDF, CSV)', async ({
    page,
}) => {
    const { issues } = watch(page);
    await open(page, '/reportes/inspeccion_laboral');

    for (const [label, ext] of [
        ['Excel', 'xlsx'],
        ['PDF', 'pdf'],
        ['CSV', 'csv'],
    ] as const) {
        await page
            .getByRole('button', { name: label, exact: true })
            .first()
            .click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Generar' }).click();
        await expect(dialog).toBeHidden({ timeout: 60_000 });
        await settle(page);

        const link = page.locator(`a[href*="/reportes/descargas/"]`).first();
        await expect(link).toBeVisible();
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            link.click(),
        ]);
        expect(download.suggestedFilename()).toMatch(new RegExp(`\\.${ext}$`));
        const path = await download.path();
        expect(path).toBeTruthy();
    }

    await shot(page, 'flujo-reporte-exportaciones');
    clean(issues);
});

test('rectificación: formulario con selector de marcajes del día', async ({
    page,
}) => {
    const { issues } = watch(page);
    await open(page, '/rectificaciones');
    await page.getByRole('button', { name: /Nueva solicitud/ }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await sheet.getByText('Busca por nombre o número').click();
    await page.getByRole('option').first().click();
    await settle(page);
    await page.waitForTimeout(800);
    // Con colaborador y fecha elegidos, el selector carga los marcajes del día (o su estado vacío).
    await expect(
        sheet.getByText('Elige colaborador y fecha para ver sus marcajes.'),
    ).toBeHidden();
    const found = await inspect(page, 'escritorio');
    expect.soft(found.overflow, 'Desborde en formulario').toBeNull();
    const spill = await sheet.evaluate((root) => {
        const edge = root.getBoundingClientRect().right + 1;

        return Array.from(root.querySelectorAll<HTMLElement>('*'))
            .filter((n) => n.getBoundingClientRect().right > edge)
            .map((n) => n.tagName.toLowerCase());
    });
    expect.soft(spill, 'Elementos fuera del panel').toEqual([]);
    await shot(page, 'flujo-rectificacion-form', false);
    await page.keyboard.press('Escape');
    clean(issues);
});

test('calendario: mes, semana y día', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/calendario');

    for (const view of ['Semana', 'Día', 'Mes']) {
        await page
            .getByRole('radio', { name: view, exact: true })
            .or(page.getByRole('button', { name: view, exact: true }))
            .first()
            .click();
        await settle(page);
        const found = await inspect(page, 'escritorio');
        expect
            .soft(found.overflow, `Desborde en calendario ${view}`)
            .toBeNull();
        await shot(page, `flujo-calendario-${view.toLowerCase()}`);
    }

    clean(issues);
});

test('alta de colaborador en 3 pasos (sin guardar)', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/colaboradores/nuevo');
    await page.getByLabel('Nombre(s)').fill('QA');
    await page.getByLabel('Número de empleado').fill('QA-0001');
    await page.getByLabel('Apellido paterno').fill('Prueba');
    await page.getByRole('button', { name: 'Continuar' }).click();
    await settle(page);
    await shot(page, 'flujo-alta-paso2', false);
    await expect(page.getByText('Tipo de contrato').first()).toBeVisible();
    clean(issues);
});

test('registro facial sin cámara muestra el aviso', async ({ page }) => {
    const { issues } = watch(page);
    await open(page, '/colaboradores');
    await page.locator('[data-row-link]:visible').first().click();
    await page.waitForURL(/\/colaboradores\/\d+$/);
    await settle(page);
    await page.getByRole('link', { name: /Rostro/ }).click();
    await page.waitForURL(/\/rostro$/);
    await settle(page);
    const camera = page.getByRole('button', { name: /Abrir cámara/ });

    if (await camera.isEnabled()) {
        await camera.click();
        await expect(page.getByText(/cámara/i).nth(1)).toBeVisible();
    }

    await shot(page, 'flujo-rostro-sin-camara', false);
    clean(issues);
});

test('cerrar sesión', async ({ browser }) => {
    // Sesión propia para no invalidar la compartida.
    const { credentials, login } = await import('./support');
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
        viewport: VIEWPORTS.escritorio,
    });
    const page = await context.newPage();
    const { email, password } = credentials();
    await login(page, email, password);
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="logout-button"]').click();
    await page.waitForURL(/\/login/);
    await context.close();
});
