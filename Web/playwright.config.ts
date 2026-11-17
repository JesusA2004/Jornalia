import { defineConfig, devices } from '@playwright/test';

/**
 * Pruebas E2E (navegador real) de la web de MR LANA Asistencias.
 *
 * Requisitos: servidor en E2E_BASE_URL (por defecto http://127.0.0.1:8000) con assets compilados
 * (`npm run build`) o `npm run dev` activo (este último además expone los warnings de Vue).
 *
 * Variables:
 *  - E2E_EMAIL / E2E_PASSWORD (o E2E_PASSWORD_FILE): cuenta administradora.
 *  - E2E_SHOTS: carpeta de capturas (por defecto tests/e2e/.capturas).
 */
export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './tests/e2e/.resultados',
    timeout: 120_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        locale: 'es-MX',
        timezoneId: 'America/Mexico_City',
        storageState: './tests/e2e/.auth/admin.json',
        trace: 'retain-on-failure',
        acceptDownloads: true,
        navigationTimeout: 30_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
