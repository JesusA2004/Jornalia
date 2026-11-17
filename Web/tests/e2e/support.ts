import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect } from '@playwright/test';
import type {
    BrowserContext,
    ConsoleMessage,
    Page,
    Response,
} from '@playwright/test';

export const VIEWPORTS = {
    escritorio: { width: 1440, height: 900 },
    movil: { width: 390, height: 844 },
} as const;

export type Viewport = keyof typeof VIEWPORTS;
export type Theme = 'light' | 'dark';

export const SHOTS_DIR = process.env.E2E_SHOTS ?? './tests/e2e/.capturas';
const REPORT_FILE = join(SHOTS_DIR, 'hallazgos.jsonl');

export function credentials(): { email: string; password: string } {
    const email = process.env.E2E_EMAIL ?? 'admin@mrlana.local';
    let password = process.env.E2E_PASSWORD ?? '';

    if (
        !password &&
        process.env.E2E_PASSWORD_FILE &&
        existsSync(process.env.E2E_PASSWORD_FILE)
    ) {
        password = readFileSync(process.env.E2E_PASSWORD_FILE, 'utf8').trim();
    }

    if (!password) {
        throw new Error(
            'Define E2E_PASSWORD o E2E_PASSWORD_FILE para las pruebas E2E.',
        );
    }

    return { email, password };
}

export async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 30_000,
    });
}

/** Fija el tema antes de que cargue la página (misma clave que useAppearance). */
export async function setTheme(
    context: BrowserContext,
    theme: Theme,
): Promise<void> {
    await context.addInitScript((t) => {
        try {
            localStorage.setItem('appearance', t);
        } catch {
            /* sin almacenamiento */
        }
        document.cookie = `appearance=${t};path=/`;
    }, theme);
}

/** Mensajes de consola que no son defectos de la app (ruido del entorno). */
const IGNORED_CONSOLE = [
    /\[vite\] (connecting|connected)/i,
    /Download the Vue Devtools/i,
    // Ruido del GPU emulado de Chromium headless (no es defecto de la app).
    /GL Driver Message|GPU stall due to ReadPixels/i,
    /Browser logger active/i,
    /Failed to load resource: the server responded with a status of 404 \(Not Found\).*favicon/i,
];

export type PageIssues = {
    url: string;
    status: number | null;
    console: string[];
    pageErrors: string[];
    badResponses: string[];
    overflow: {
        scrollWidth: number;
        innerWidth: number;
        culprits: string[];
    } | null;
    forbiddenWords: string[];
    smallTargets: string[];
    truncated: string[];
};

/** Escucha consola, errores y respuestas de red de una página. */
export function watch(page: Page): {
    issues: Omit<
        PageIssues,
        | 'url'
        | 'status'
        | 'overflow'
        | 'forbiddenWords'
        | 'smallTargets'
        | 'truncated'
    >;
} {
    const issues = {
        console: [] as string[],
        pageErrors: [] as string[],
        badResponses: [] as string[],
    };

    page.on('console', (msg: ConsoleMessage) => {
        if (msg.type() !== 'error' && msg.type() !== 'warning') {
            return;
        }

        const text = `${msg.type()}: ${msg.text()}`;

        if (IGNORED_CONSOLE.some((re) => re.test(text))) {
            return;
        }

        issues.console.push(text.slice(0, 600));
    });
    page.on('pageerror', (err) =>
        issues.pageErrors.push(String(err?.stack ?? err).slice(0, 600)),
    );
    page.on('response', (res: Response) => {
        const status = res.status();

        if (status >= 400 && !res.url().includes('favicon')) {
            issues.badResponses.push(
                `${status} ${res.request().method()} ${res.url()}`,
            );
        }
    });

    return { issues };
}

/** Revisa la página ya cargada: desbordes, palabras prohibidas, targets pequeños y textos cortados. */
export async function inspect(
    page: Page,
    viewport: Viewport,
): Promise<
    Pick<
        PageIssues,
        'overflow' | 'forbiddenWords' | 'smallTargets' | 'truncated'
    >
> {
    return page.evaluate((isMobile) => {
        const el = document.scrollingElement ?? document.documentElement;
        let overflow = null as null | {
            scrollWidth: number;
            innerWidth: number;
            culprits: string[];
        };

        if (el.scrollWidth > window.innerWidth) {
            const culprits: string[] = [];
            document.querySelectorAll<HTMLElement>('body *').forEach((node) => {
                const r = node.getBoundingClientRect();

                if (
                    r.right > window.innerWidth + 1 &&
                    r.width > 0 &&
                    culprits.length < 8
                ) {
                    culprits.push(
                        `${node.tagName.toLowerCase()}.${String(node.className).slice(0, 80)} right=${Math.round(r.right)}`,
                    );
                }
            });
            overflow = {
                scrollWidth: el.scrollWidth,
                innerWidth: window.innerWidth,
                culprits,
            };
        }

        const text = document.body.innerText;
        const forbiddenWords = Array.from(
            new Set(text.match(/\b(Eliminar|Borrar|Delete|Laravel)\b/gi) ?? []),
        );

        const isVisible = (n: HTMLElement) => {
            const r = n.getBoundingClientRect();
            const s = getComputedStyle(n);

            return (
                r.width > 0 &&
                r.height > 0 &&
                s.visibility !== 'hidden' &&
                s.display !== 'none' &&
                n.closest('[aria-hidden="true"]') === null
            );
        };

        const smallTargets: string[] = [];

        if (isMobile) {
            document
                .querySelectorAll<HTMLElement>(
                    'button, a[href], [role="button"], [role="tab"], [role="checkbox"], [role="switch"], [role="combobox"], input:not([type="hidden"])',
                )
                .forEach((n) => {
                    if (!isVisible(n)) {
                        return;
                    }

                    const r = n.getBoundingClientRect();
                    // Checkbox/switch pueden tener su hit-area ampliada por el label; se reporta el control.
                    if (r.height < 40 || r.width < 24) {
                        const label = (
                            n.getAttribute('aria-label') ??
                            n.innerText ??
                            n.getAttribute('name') ??
                            n.tagName
                        )
                            .trim()
                            .replace(/\s+/g, ' ')
                            .slice(0, 40);
                        smallTargets.push(
                            `${label || n.tagName.toLowerCase()} (${Math.round(r.width)}×${Math.round(r.height)})`,
                        );
                    }
                });
        }

        const truncated: string[] = [];
        document
            .querySelectorAll<HTMLElement>(
                'h1, h2, h3, button, label, th, [role="tab"], a',
            )
            .forEach((n) => {
                if (!isVisible(n)) {
                    return;
                }

                const s = getComputedStyle(n);

                if (
                    (s.textOverflow === 'ellipsis' ||
                        s.overflow === 'hidden') &&
                    n.scrollWidth > n.clientWidth + 1 &&
                    n.innerText.trim()
                ) {
                    truncated.push(
                        n.innerText.trim().replace(/\s+/g, ' ').slice(0, 60),
                    );
                }
            });

        return {
            overflow,
            forbiddenWords,
            smallTargets: Array.from(new Set(smallTargets)).slice(0, 40),
            truncated: Array.from(new Set(truncated)).slice(0, 20),
        };
    }, viewport === 'movil');
}

export async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.evaluate(() => document.fonts?.ready).catch(() => undefined);
    await page.waitForTimeout(300);
}

export async function shot(
    page: Page,
    name: string,
    fullPage = true,
): Promise<string> {
    mkdirSync(SHOTS_DIR, { recursive: true });
    const path = join(SHOTS_DIR, `${name}.png`);
    await page.screenshot({ path, fullPage });

    return path;
}

export function record(issues: PageIssues & { name: string }): void {
    mkdirSync(SHOTS_DIR, { recursive: true });
    appendFileSync(REPORT_FILE, JSON.stringify(issues) + '\n');
}

/** Aserciones duras compartidas: sin errores, sin desbordes ni textos prohibidos. */
export function assertClean(issues: PageIssues): void {
    expect.soft(issues.status, `HTTP ${issues.url}`).toBeLessThan(400);
    expect.soft(issues.pageErrors, `Errores JS en ${issues.url}`).toEqual([]);
    expect.soft(issues.console, `Consola en ${issues.url}`).toEqual([]);
    expect
        .soft(issues.badResponses, `Respuestas con error en ${issues.url}`)
        .toEqual([]);
    expect
        .soft(issues.overflow, `Desborde horizontal en ${issues.url}`)
        .toBeNull();
    expect
        .soft(issues.forbiddenWords, `Textos prohibidos en ${issues.url}`)
        .toEqual([]);
}

/** Primer href del documento que cumpla el patrón (para descubrir detalles con datos reales). */
export async function firstHref(
    page: Page,
    pattern: RegExp,
): Promise<string | null> {
    const hrefs = await page
        .locator('a[href]')
        .evaluateAll((as) =>
            as.map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? ''),
        );
    const found = hrefs
        .map((h) => h.replace(/^https?:\/\/[^/]+/, ''))
        .find((h) => pattern.test(h));

    return found ?? null;
}
