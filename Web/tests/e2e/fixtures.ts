import { test as base } from '@playwright/test';
import type { BrowserContext, Page } from '@playwright/test';
import { storageFor, watch } from './support';
import type { RoleKey, Watched } from './support';

type RolePage = { context: BrowserContext; page: Page; watched: Watched };

export type RolePages = {
    /** Página reutilizable del rol (misma caché HTTP durante todo el worker). */
    get(role: RoleKey): Promise<RolePage>;
};

/**
 * Fixture de worker: una sesión por rol, reutilizada entre pruebas del mismo
 * worker para no descargar los assets en cada pantalla. Cada prueba sigue
 * siendo independiente (una falla no detiene a las demás).
 */
export const test = base.extend<
    Record<string, never>,
    { rolePages: RolePages }
>({
    rolePages: [
        async ({ browser }, use, workerInfo) => {
            const cache = new Map<RoleKey, RolePage>();
            const u = workerInfo.project.use;

            await use({
                async get(role) {
                    const found = cache.get(role);

                    if (found && !found.page.isClosed()) {
                        found.watched.reset();

                        return found;
                    }

                    const context = await browser.newContext({
                        baseURL: u.baseURL,
                        viewport: u.viewport,
                        userAgent: u.userAgent,
                        deviceScaleFactor: u.deviceScaleFactor,
                        locale: u.locale,
                        timezoneId: u.timezoneId,
                        storageState: storageFor(role),
                    });
                    const page = await context.newPage();
                    const watched = watch(page);
                    const created = { context, page, watched };
                    cache.set(role, created);

                    return created;
                },
            });

            for (const { context } of cache.values()) {
                await context.close().catch(() => undefined);
            }
        },
        { scope: 'worker' },
    ],
});

export { expect } from '@playwright/test';
