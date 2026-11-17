import { mkdirSync } from 'node:fs';
import { chromium } from '@playwright/test';
import type { FullConfig } from '@playwright/test';
import { credentials, login } from './support';

/** Inicia sesión una vez como administrador y guarda la sesión para todos los specs. */
export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0].use.baseURL ?? 'http://127.0.0.1:8000';
    mkdirSync('./tests/e2e/.auth', { recursive: true });

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });
    const { email, password } = credentials();
    await login(page, email, password);
    await page.context().storageState({ path: './tests/e2e/.auth/admin.json' });
    await browser.close();
}
