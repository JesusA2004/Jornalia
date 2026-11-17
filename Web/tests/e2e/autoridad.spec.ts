import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, settle, shot, VIEWPORTS, watch } from './support';

/**
 * Rol "Autoridad laboral" (regla 15): sólo lectura.
 * 1) Como administrador crea (o reutiliza) el acceso qa.autoridad@mrlana.local con contraseña temporal.
 * 2) Inicia sesión como autoridad, recorre lo permitido y confirma que no hay acciones de escritura
 *    y que los módulos de administración responden 403.
 *
 * La contraseña temporal sólo se muestra una vez: si el acceso ya existe, define E2E_AUTHORITY_PASSWORD.
 */
const EMAIL = process.env.E2E_AUTHORITY_EMAIL ?? 'qa.autoridad@mrlana.local';

test.use({ viewport: VIEWPORTS.escritorio });
test.describe.configure({ mode: 'serial' });

let password = process.env.E2E_AUTHORITY_PASSWORD ?? '';

test('el administrador crea el acceso de autoridad laboral', async ({
    page,
}) => {
    await page.goto(`/usuarios?search=${encodeURIComponent(EMAIL)}`);
    await settle(page);

    if (
        await page
            .getByText(EMAIL)
            .first()
            .isVisible()
            .catch(() => false)
    ) {
        test.skip(
            !password,
            'El acceso ya existe; define E2E_AUTHORITY_PASSWORD para continuar.',
        );

        return;
    }

    const { issues } = watch(page);
    await page
        .getByRole('button', { name: /Crear acceso para autoridad laboral/ })
        .click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Nombre').fill('QA Autoridad laboral');
    await dialog.getByLabel('Correo').fill(EMAIL);
    await dialog.getByRole('radio', { name: /Contraseña temporal/ }).click();
    await shot(page, 'autoridad-alta', false);
    await dialog.getByRole('button', { name: 'Crear acceso' }).click();

    const secret = page.locator('code.select-all');
    await expect(secret).toBeVisible({ timeout: 20_000 });
    password = (await secret.innerText()).trim();
    await shot(page, 'autoridad-contrasena-temporal', false);
    expect(password.length).toBeGreaterThan(7);
    expect(issues.pageErrors).toEqual([]);
    expect(issues.badResponses).toEqual([]);
});

const LECTURA = [
    '/asistencias',
    '/calendario',
    '/colaboradores',
    '/horarios',
    '/horas-extra',
    '/vacaciones',
    '/incidencias',
    '/rectificaciones',
    '/reportes',
    '/reportes/inspeccion_laboral',
    '/cumplimiento',
    '/integridad',
];
const PROHIBIDAS = [
    '/usuarios',
    '/configuracion',
    '/organizacion',
    '/dispositivos',
    '/auditoria',
    '/colaboradores/nuevo',
    '/horarios/nuevo',
    '/ubicacion',
];
const ESCRITURA =
    /^(Nuevo|Nueva|Invitar|Importar|Agregar|Editar|Movimiento|Dar de baja|Aprobar|Rechazar|Registrar|Anclar|Guardar|Asignar|Crear|Desactivar)\b/;

async function writeActions(page: Page): Promise<string[]> {
    const names = await page
        .locator('main button:visible, main a:visible')
        .evaluateAll((els) =>
            els.map((e) =>
                (
                    e.getAttribute('aria-label') ??
                    (e as HTMLElement).innerText ??
                    ''
                )
                    .trim()
                    .replace(/\s+/g, ' '),
            ),
        );

    return names.filter((n) => ESCRITURA.test(n));
}

test('la autoridad laboral sólo ve lectura', async ({ browser }) => {
    test.skip(!password, 'Sin contraseña de autoridad.');
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
        viewport: VIEWPORTS.escritorio,
    });
    const page = await context.newPage();
    const { issues } = watch(page);
    await login(page, EMAIL, password);

    // Si la cuenta exige cambiar la contraseña temporal, la pantalla lo pedirá: se documenta con captura.
    await settle(page);
    await shot(page, 'autoridad-inicio', false);

    for (const url of LECTURA) {
        const res = await page.goto(url);
        await settle(page);
        expect.soft(res?.status(), `HTTP ${url}`).toBe(200);
        expect
            .soft(await writeActions(page), `Acciones de escritura en ${url}`)
            .toEqual([]);
        await shot(page, `autoridad${url.replaceAll('/', '-')}`, false);
    }

    for (const url of PROHIBIDAS) {
        const res = await page.goto(url);
        expect.soft(res?.status(), `Debe negar ${url}`).toBe(403);
    }

    const nav = await page
        .locator('[data-sidebar="sidebar"] a')
        .evaluateAll((as) =>
            as.map((a) => (a as HTMLElement).innerText.trim()),
        );
    expect
        .soft(nav.join(' | '), 'Menú sin administración')
        .not.toMatch(
            /Usuarios|Configuración|Organización|Dispositivos|Auditoría/,
        );

    expect.soft(issues.pageErrors).toEqual([]);
    expect.soft(issues.console.filter((c) => !/403/.test(c))).toEqual([]);
    await context.close();
});

test('baja lógica del acceso QA de autoridad', async ({ page }) => {
    test.skip(
        !!process.env.E2E_KEEP_AUTHORITY,
        'Se conserva el acceso (E2E_KEEP_AUTHORITY).',
    );
    await page.goto(`/usuarios?search=${encodeURIComponent(EMAIL)}`);
    await settle(page);
    const row = page.locator('tr', { hasText: EMAIL });
    test.skip((await row.count()) === 0, 'El acceso no existe.');
    await row.getByRole('button', { name: 'Acciones' }).click();
    const item = page.getByRole('menuitem', { name: 'Desactivar acceso' });
    test.skip((await item.count()) === 0, 'El acceso ya está inactivo.');
    await item.click();
    await page
        .getByRole('alertdialog')
        .or(page.getByRole('dialog'))
        .getByRole('button', { name: 'Desactivar' })
        .click();
    await expect(row.getByText(/Inactivo/)).toBeVisible();
});
