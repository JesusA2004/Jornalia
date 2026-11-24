import { mkdirSync } from 'node:fs';
import { chromium } from '@playwright/test';
import type { FullConfig } from '@playwright/test';
import { accounts, AUTH_DIR, loginAs, storageFor } from './support';
import type { RoleKey } from './support';

/**
 * Inicia sesión una vez por rol (incluido el reto TOTP real de los roles con
 * doble factor) y guarda cada sesión para reutilizarla en todos los navegadores.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0].use.baseURL ?? 'http://127.0.0.1:8020';
    mkdirSync(AUTH_DIR, { recursive: true });

    const probe = await fetch(`${baseURL}/login`).catch(() => null);

    if (!probe || probe.status >= 500) {
        throw new Error(
            `El servidor E2E no responde en ${baseURL}. Inícialo con: cd Web && APP_ENV=e2e php artisan serve --port=8020`,
        );
    }

    const browser = await chromium.launch();

    try {
        for (const key of Object.keys(accounts().accounts) as RoleKey[]) {
            const context = await browser.newContext({ baseURL });
            const page = await context.newPage();
            await loginAs(page, key);
            await context.storageState({ path: storageFor(key) });
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
