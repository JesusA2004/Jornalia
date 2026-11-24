import { createHmac } from 'node:crypto';
import {
    appendFileSync,
    existsSync,
    mkdirSync,
    readFileSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';
import type { ConsoleMessage, Page, Response } from '@playwright/test';

/**
 * Utilidades compartidas de las pruebas E2E (navegador real).
 *
 * Las cuentas por rol las genera `APP_ENV=e2e php artisan e2e:preparar` en
 * `tests/e2e/.auth/cuentas.json` (carpeta ignorada por git): contraseña común
 * y, para los roles con doble factor obligatorio, el secreto TOTP.
 */

export const AUTH_DIR = './tests/e2e/.auth';
export const SHOTS_DIR = process.env.E2E_SHOTS ?? './tests/e2e/.capturas';
const REPORT_FILE = join(SHOTS_DIR, 'hallazgos.jsonl');
const TOTP_LOG = join(AUTH_DIR, 'totp-usados.json');

export type Theme = 'light' | 'dark';
export const THEMES: Theme[] = ['light', 'dark'];

export type RoleKey =
    | 'plataforma'
    | 'rh'
    | 'direccion'
    | 'regional'
    | 'gerente'
    | 'contraloria'
    | 'autoridad'
    | 'rh_vacia';

export type Account = {
    email: string;
    name: string;
    role: string;
    totpSecret: string | null;
    organization?: string;
};

type AccountsFile = {
    password: string;
    accounts: Partial<Record<RoleKey, Account>>;
};

let cachedAccounts: AccountsFile | null = null;

/** Lee las cuentas sembradas por `e2e:preparar`. */
export function accounts(): AccountsFile {
    if (cachedAccounts) {
        return cachedAccounts;
    }

    const file = join(AUTH_DIR, 'cuentas.json');

    if (!existsSync(file)) {
        throw new Error(
            `No existe ${file}. Corre primero: cd Web && APP_ENV=e2e php artisan e2e:preparar`,
        );
    }

    cachedAccounts = JSON.parse(readFileSync(file, 'utf8')) as AccountsFile;

    return cachedAccounts;
}

export function account(key: RoleKey): Account {
    const found = accounts().accounts[key];

    if (!found) {
        throw new Error(`La cuenta «${key}» no está en cuentas.json`);
    }

    return found;
}

/** Ruta del estado de sesión guardado por `global-setup` para un rol. */
export function storageFor(key: RoleKey): string {
    return join(AUTH_DIR, `${key}.json`);
}

/* ------------------------------------------------------------- TOTP -- */

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const clean = input.replace(/=+$/u, '').replace(/\s+/gu, '').toUpperCase();
    let bits = 0;
    let value = 0;
    const out: number[] = [];

    for (const char of clean) {
        const index = alphabet.indexOf(char);

        if (index === -1) {
            throw new Error('Secreto TOTP inválido');
        }

        value = (value << 5) | index;
        bits += 5;

        if (bits >= 8) {
            out.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return Buffer.from(out);
}

/** Código TOTP (RFC 6238: SHA-1, 6 dígitos, 30 s), igual que Google2FA/Fortify. */
export function totp(secret: string, at = Date.now()): string {
    const counter = Math.floor(at / 1000 / 30);
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(counter));
    const hmac = createHmac('sha1', base32Decode(secret))
        .update(message)
        .digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}

/**
 * Fortify rechaza reutilizar un código dentro de su ventana. Si el código
 * vigente ya se usó, espera al siguiente periodo de 30 s.
 */
async function freshTotp(page: Page, secret: string): Promise<string> {
    const used: Record<string, number> = existsSync(TOTP_LOG)
        ? (JSON.parse(readFileSync(TOTP_LOG, 'utf8')) as Record<string, number>)
        : {};

    let code = totp(secret);
    const key = () => `${secret.slice(0, 6)}:${code}`;

    while (used[key()] && Date.now() - used[key()] < 120_000) {
        const wait = 30_000 - (Date.now() % 30_000) + 500;
        await page.waitForTimeout(wait);
        code = totp(secret);
    }

    used[key()] = Date.now();
    mkdirSync(AUTH_DIR, { recursive: true });
    writeFileSync(TOTP_LOG, JSON.stringify(used));

    return code;
}

/* ------------------------------------------------------------ Sesión -- */

/** Inicia sesión con correo y contraseña; resuelve el reto TOTP si aparece. */
export async function loginAs(page: Page, key: RoleKey): Promise<void> {
    const { email, totpSecret } = account(key);
    const { password } = accounts();

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 30_000,
    });

    if (new URL(page.url()).pathname.startsWith('/two-factor-challenge')) {
        if (!totpSecret) {
            throw new Error(
                `«${key}» pide doble factor pero no tiene secreto.`,
            );
        }

        await page.locator('#code').fill(await freshTotp(page, totpSecret));
        await page.getByRole('button', { name: 'Continuar' }).click();
        await page.waitForURL(
            (url) => !url.pathname.startsWith('/two-factor-challenge'),
            { timeout: 30_000 },
        );
    }
}

/* ------------------------------------------------------- Observación -- */

/** Mensajes de consola que no son defectos de la app (ruido del entorno). */
const IGNORED_CONSOLE = [
    /\[vite\] (connecting|connected)/i,
    /Download the Vue Devtools/i,
    /GL Driver Message|GPU stall due to ReadPixels/i,
    /Browser logger active/i,
    // WebGL de MapLibre en navegadores sin GPU (headless).
    /WebGL|WEBGL|Failed to initialize WebGL/i,
    // Firefox avisa de fuentes y de propiedades CSS que no reconoce.
    /downloadable font|Unknown property|Error in parsing value|Declaration dropped|Ruleset ignored/i,
    /Layout was forced before the page was fully loaded/i,
];

export type Watched = {
    console: string[];
    pageErrors: string[];
    badResponses: string[];
    reset: () => void;
};

/** Escucha consola, errores JS y respuestas fallidas de una página. */
export function watch(
    page: Page,
    options: { allowStatus?: number[] } = {},
): Watched {
    const watched: Watched = {
        console: [],
        pageErrors: [],
        badResponses: [],
        reset() {
            watched.console.length = 0;
            watched.pageErrors.length = 0;
            watched.badResponses.length = 0;
        },
    };

    page.on('console', (msg: ConsoleMessage) => {
        if (msg.type() !== 'error' && msg.type() !== 'warning') {
            return;
        }

        const text = `${msg.type()}: ${msg.text()}`;

        if (IGNORED_CONSOLE.some((re) => re.test(text))) {
            return;
        }

        // Los recursos con error se cuentan en badResponses (con su URL).
        if (/Failed to load resource/i.test(text)) {
            return;
        }

        watched.console.push(text.slice(0, 500));
    });
    page.on('pageerror', (err) =>
        watched.pageErrors.push(String(err?.stack ?? err).slice(0, 500)),
    );
    page.on('response', (res: Response) => {
        const status = res.status();
        const url = res.url();

        if (
            status >= 400 &&
            !url.includes('favicon') &&
            !(options.allowStatus ?? []).includes(status) &&
            // Teselas de mapa externas: no dependen de la app.
            !/tile|basemaps|openstreetmap|cartocdn/i.test(url)
        ) {
            watched.badResponses.push(
                `${status} ${res.request().method()} ${url}`,
            );
        }
    });
    page.on('requestfailed', (req) => {
        const url = req.url();
        const failure = req.failure()?.errorText ?? '';

        // Navegaciones canceladas por otra navegación no son fallas.
        if (
            /ERR_ABORTED|NS_BINDING_ABORTED|cancelled|Load request cancelled/i.test(
                failure,
            ) ||
            /tile|basemaps|openstreetmap|cartocdn/i.test(url)
        ) {
            return;
        }

        watched.badResponses.push(`FALLÓ ${req.method()} ${url} ${failure}`);
    });

    return watched;
}

/** Espera a que la página termine de pintar (red, fuentes y transiciones). */
export async function settle(page: Page): Promise<void> {
    await page
        .waitForLoadState('networkidle', { timeout: 15_000 })
        .catch(() => undefined);
    await page.evaluate(() => document.fonts?.ready).catch(() => undefined);
    await page.waitForTimeout(250);
}

/* --------------------------------------------------- Inspección visual -- */

export type LayoutReport = {
    overflow: { scrollWidth: number; innerWidth: number } | null;
    offscreen: string[];
    overlaps: string[];
    nestedScroll: string[];
    forbiddenWords: string[];
    smallTargets: string[];
    truncated: string[];
};

/**
 * Revisa la página ya cargada: desborde horizontal, elementos fuera del
 * viewport (no recortados por un contenedor con scroll), textos encimados,
 * scroll doble, palabras prohibidas y objetivos táctiles < 24 px.
 */
export async function inspectLayout(page: Page): Promise<LayoutReport> {
    return page.evaluate(() => {
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const doc = document.scrollingElement ?? document.documentElement;

        const describe = (n: Element): string => {
            const id = n.id ? `#${n.id}` : '';
            const slot = n.getAttribute('data-slot');
            const cls = String(n.getAttribute('class') ?? '')
                .split(/\s+/)
                .slice(0, 4)
                .join('.');
            const text = (n as HTMLElement).innerText
                ?.trim()
                .replace(/\s+/g, ' ')
                .slice(0, 40);

            return `${n.tagName.toLowerCase()}${id}${slot ? `[${slot}]` : ''}${cls ? `.${cls}` : ''}${text ? ` «${text}»` : ''}`;
        };

        const isHidden = (n: Element): boolean => {
            const s = getComputedStyle(n);

            return (
                s.display === 'none' ||
                s.visibility === 'hidden' ||
                Number(s.opacity) === 0 ||
                n.closest(
                    '[aria-hidden="true"], .sr-only, [inert], [data-state="closed"]',
                ) !== null
            );
        };

        /** ¿Algún ancestro recorta horizontalmente (overflow ≠ visible)? */
        const clippedX = (n: Element): boolean => {
            let p = n.parentElement;

            while (p && p !== document.body) {
                const s = getComputedStyle(p);

                if (s.overflowX !== 'visible' || s.position === 'fixed') {
                    return true;
                }

                p = p.parentElement;
            }

            return false;
        };

        const overflow =
            doc.scrollWidth > vw + 1
                ? { scrollWidth: doc.scrollWidth, innerWidth: vw }
                : null;

        const offscreen: string[] = [];
        document.querySelectorAll<HTMLElement>('body *').forEach((n) => {
            if (offscreen.length >= 10) {
                return;
            }

            const r = n.getBoundingClientRect();

            if (r.width === 0 || r.height === 0) {
                return;
            }

            if ((r.right > vw + 1 || r.left < -1) && !clippedX(n)) {
                if (!isHidden(n)) {
                    offscreen.push(
                        `${describe(n)} [${Math.round(r.left)}→${Math.round(r.right)}]`,
                    );
                }
            }
        });

        // Textos encimados: rectángulos de nodos de texto visibles que se cruzan.
        type Box = { x: number; y: number; w: number; h: number; el: Element };
        const boxes: Box[] = [];
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
        );
        const range = document.createRange();
        let node: Node | null;

        while ((node = walker.nextNode())) {
            const text = node.textContent?.trim() ?? '';
            const parent = node.parentElement;

            if (!text || !parent || isHidden(parent)) {
                continue;
            }

            if (
                parent.closest(
                    'svg, canvas, [data-overlap-ok], .maplibregl-map',
                )
            ) {
                continue;
            }

            range.selectNodeContents(node);

            for (const r of Array.from(range.getClientRects())) {
                if (
                    r.width > 1 &&
                    r.height > 1 &&
                    r.bottom > 0 &&
                    r.top < vh * 3
                ) {
                    boxes.push({
                        x: r.left,
                        y: r.top,
                        w: r.width,
                        h: r.height,
                        el: parent,
                    });
                }
            }
        }

        const overlaps: string[] = [];
        const sameLayer = (a: Element, b: Element): boolean => {
            // Elementos superpuestos a propósito (popovers, tooltips, toasts) se ignoran.
            const layer = (e: Element) =>
                e.closest(
                    '[role="dialog"], [role="tooltip"], [role="menu"], [role="listbox"], [data-sonner-toaster], [data-slot="popover-content"], [data-slot="tooltip-content"]',
                );

            return layer(a) === layer(b);
        };

        for (let i = 0; i < boxes.length && overlaps.length < 8; i++) {
            const a = boxes[i];

            for (let j = i + 1; j < boxes.length; j++) {
                const b = boxes[j];

                if (
                    a.el === b.el ||
                    a.el.contains(b.el) ||
                    b.el.contains(a.el)
                ) {
                    continue;
                }

                const ix = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
                const iy = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);

                if (ix <= 2 || iy <= 2) {
                    continue;
                }

                const smaller = Math.min(a.w * a.h, b.w * b.h);

                if ((ix * iy) / smaller > 0.3 && sameLayer(a.el, b.el)) {
                    overlaps.push(
                        `«${a.el.textContent?.trim().slice(0, 30)}» × «${b.el.textContent?.trim().slice(0, 30)}»`,
                    );
                    break;
                }
            }
        }

        // Scroll doble: un contenedor alto con scroll vertical propio además del de la página.
        const pageScrolls = doc.scrollHeight > vh + 1;
        const nestedScroll: string[] = [];
        document.querySelectorAll<HTMLElement>('body *').forEach((n) => {
            const s = getComputedStyle(n);

            if (!/(auto|scroll)/.test(s.overflowY) || isHidden(n)) {
                return;
            }

            if (n.scrollHeight <= n.clientHeight + 1) {
                return;
            }

            // La barra lateral fija y los paneles flotantes tienen su propio scroll legítimo.
            if (
                n.closest(
                    '[data-sidebar="sidebar"], [role="dialog"], [role="menu"], [role="listbox"], [data-slot="popover-content"], [data-slot="command-list"]',
                )
            ) {
                return;
            }

            if (n === document.body && pageScrolls) {
                nestedScroll.push('body y html desplazan a la vez');

                return;
            }

            if (n.clientHeight >= vh * 0.6 && pageScrolls) {
                nestedScroll.push(describe(n));
            }
        });

        const text = document.body.innerText;
        const forbiddenWords = Array.from(
            new Set(
                text.match(
                    /\b(Laravel|Eliminar|Borrar|Delete|Lorem|Próximamente|undefined|NaN|\[object Object\])\b/gi,
                ) ?? [],
            ),
        );

        const smallTargets: string[] = [];

        if (vw < 768) {
            document
                .querySelectorAll<HTMLElement>(
                    'button, a[href], [role="button"], [role="tab"], [role="checkbox"], [role="switch"], [role="combobox"], input:not([type="hidden"])',
                )
                .forEach((n) => {
                    if (isHidden(n)) {
                        return;
                    }

                    const r = n.getBoundingClientRect();

                    if (r.width === 0 || r.height === 0) {
                        return;
                    }

                    // Enlaces dentro de un párrafo están exentos (WCAG 2.5.8).
                    if (
                        n.tagName === 'A' &&
                        getComputedStyle(n).display === 'inline'
                    ) {
                        return;
                    }

                    if (r.height < 24 || r.width < 24) {
                        smallTargets.push(
                            `${describe(n)} (${Math.round(r.width)}×${Math.round(r.height)})`,
                        );
                    }
                });
        }

        const truncated: string[] = [];
        document
            .querySelectorAll<HTMLElement>(
                'h1, h2, h3, button, label, th, [role="tab"]',
            )
            .forEach((n) => {
                if (isHidden(n)) {
                    return;
                }

                const s = getComputedStyle(n);

                if (
                    (s.textOverflow === 'ellipsis' ||
                        s.overflow === 'hidden') &&
                    n.scrollWidth > n.clientWidth + 1 &&
                    n.innerText.trim() &&
                    !n.title
                ) {
                    truncated.push(
                        n.innerText.trim().replace(/\s+/g, ' ').slice(0, 50),
                    );
                }
            });

        return {
            overflow,
            offscreen,
            overlaps,
            nestedScroll: Array.from(new Set(nestedScroll)).slice(0, 6),
            forbiddenWords,
            smallTargets: Array.from(new Set(smallTargets)).slice(0, 20),
            truncated: Array.from(new Set(truncated)).slice(0, 12),
        };
    });
}

/** Comprueba que un elemento flotante (modal, menú) quede dentro del viewport. */
export async function expectInViewport(
    page: Page,
    selector: string,
    label: string,
): Promise<void> {
    const box = await page.locator(selector).last().boundingBox();
    const size = page.viewportSize();

    expect(box, `${label}: no está visible`).not.toBeNull();

    if (!box || !size) {
        return;
    }

    expect
        .soft(box.x, `${label}: sale por la izquierda`)
        .toBeGreaterThanOrEqual(-1);
    expect.soft(box.y, `${label}: sale por arriba`).toBeGreaterThanOrEqual(-1);
    expect
        .soft(box.x + box.width, `${label}: sale por la derecha`)
        .toBeLessThanOrEqual(size.width + 1);
    expect
        .soft(box.y + box.height, `${label}: sale por abajo`)
        .toBeLessThanOrEqual(size.height + 1);
}

/* -------------------------------------------------------- Evidencias -- */

export async function shot(
    page: Page,
    name: string,
    fullPage = false,
): Promise<string> {
    mkdirSync(SHOTS_DIR, { recursive: true });
    const path = join(SHOTS_DIR, `${name}.png`);
    await page.screenshot({ path, fullPage, animations: 'disabled' });

    return path;
}

export function record(entry: Record<string, unknown>): void {
    mkdirSync(SHOTS_DIR, { recursive: true });
    appendFileSync(REPORT_FILE, JSON.stringify(entry) + '\n');
}

/** Aserciones duras compartidas para una página visitada. */
export function assertClean(
    label: string,
    status: number | null,
    watched: Watched,
    layout: LayoutReport,
): void {
    expect.soft(status, `HTTP ${label}`).toBeLessThan(400);
    expect.soft(watched.pageErrors, `Errores JS en ${label}`).toEqual([]);
    expect.soft(watched.console, `Consola en ${label}`).toEqual([]);
    expect
        .soft(watched.badResponses, `Solicitudes fallidas en ${label}`)
        .toEqual([]);
    expect.soft(layout.overflow, `Desborde horizontal en ${label}`).toBeNull();
    expect
        .soft(layout.offscreen, `Elementos fuera del viewport en ${label}`)
        .toEqual([]);
    expect.soft(layout.overlaps, `Textos encimados en ${label}`).toEqual([]);
    expect.soft(layout.nestedScroll, `Scroll doble en ${label}`).toEqual([]);
    expect
        .soft(layout.forbiddenWords, `Textos prohibidos en ${label}`)
        .toEqual([]);
    expect
        .soft(layout.smallTargets, `Objetivos táctiles < 24 px en ${label}`)
        .toEqual([]);
}

/* ------------------------------------------------------ Accesibilidad -- */

/** Violaciones axe graves o críticas (WCAG 2.x A/AA). */
export async function axeViolations(page: Page): Promise<string[]> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        // Las teselas del mapa son de un proveedor externo.
        .exclude('.maplibregl-canvas')
        .analyze();

    return results.violations
        .filter((v) => v.impact === 'serious' || v.impact === 'critical')
        .map(
            (v) =>
                `${v.id} (${v.impact}): ${v.nodes
                    .slice(0, 3)
                    .map((n) => n.target.join(' '))
                    .join(' | ')}`,
        );
}

/** Primer href del documento que cumpla el patrón (descubre detalles con datos reales). */
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
