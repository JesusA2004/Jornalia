import { defineConfig, devices } from '@playwright/test';
import type { PlaywrightTestConfig } from '@playwright/test';

/**
 * Pruebas E2E (navegador real) de la web de MR LANA Asistencias.
 *
 * Entorno aislado (BD `jornalia_e2e`, puerto 8020; nunca la BD de desarrollo):
 *   1. cd Web && APP_ENV=e2e php artisan e2e:preparar      (migra y siembra datos de demostración)
 *   2. APP_ENV=e2e php artisan serve --port=8020           (lee .env.e2e)
 *   3. npm run build   (o `npm run dev` para ver además los avisos de Vue)
 *   4. npx playwright test                                 (todo)
 *      npx playwright test --project="chromium-*"         (un navegador)
 *      npx playwright test responsive --project=webkit-390
 *
 * Proyectos:
 *  - `<navegador>-<ancho>`: recorrido responsivo de todas las pantallas
 *    (claro y oscuro) en 320 · 360 · 390 · 430 · 768 · 1024 · 1280 · 1366 · 1440 · 1920.
 *  - `<navegador>-escritorio` / `<navegador>-movil`: flujos, permisos,
 *    accesibilidad (axe) y acceso (incluye doble factor TOTP real).
 *
 * Variables: E2E_BASE_URL (por defecto http://127.0.0.1:8020), E2E_WORKERS,
 * E2E_SHOTS (capturas; por defecto tests/e2e/.capturas).
 */

type Browser = 'chromium' | 'firefox' | 'webkit';

const BROWSERS: Record<Browser, PlaywrightTestConfig['use']> = {
    chromium: devices['Desktop Chrome'],
    firefox: devices['Desktop Firefox'],
    webkit: devices['Desktop Safari'],
};

/** Anchos revisados con una altura típica de cada clase de dispositivo. */
const VIEWPORTS: Array<[number, number]> = [
    [320, 568],
    [360, 740],
    [390, 844],
    [430, 932],
    [768, 1024],
    [1024, 768],
    [1280, 800],
    [1366, 768],
    [1440, 900],
    [1920, 1080],
];

const FUNCTIONAL = /(acceso|flujos|permisos|accesibilidad)\.spec\.ts/;

const projects: NonNullable<PlaywrightTestConfig['projects']> = [];

for (const [browser, device] of Object.entries(BROWSERS) as Array<
    [Browser, PlaywrightTestConfig['use']]
>) {
    for (const [width, height] of VIEWPORTS) {
        projects.push({
            name: `${browser}-${width}`,
            testMatch: /responsive\.spec\.ts/,
            use: {
                ...device,
                viewport: { width, height },
                deviceScaleFactor: 1,
            },
        });
    }

    projects.push(
        {
            name: `${browser}-escritorio`,
            testMatch: FUNCTIONAL,
            use: {
                ...device,
                viewport: { width: 1366, height: 768 },
                deviceScaleFactor: 1,
            },
        },
        {
            name: `${browser}-movil`,
            testMatch: FUNCTIONAL,
            use: {
                ...device,
                viewport: { width: 390, height: 844 },
                deviceScaleFactor: 1,
            },
        },
    );
}

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './tests/e2e/.resultados',
    timeout: 90_000,
    expect: { timeout: 10_000 },
    fullyParallel: true,
    workers: Number(process.env.E2E_WORKERS ?? 2),
    retries: 0,
    reporter: [
        ['list'],
        ['json', { outputFile: './tests/e2e/.resultados/resultados.json' }],
    ],
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8020',
        locale: 'es-MX',
        timezoneId: 'America/Mexico_City',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        acceptDownloads: true,
        navigationTimeout: 45_000,
    },
    projects,
});
