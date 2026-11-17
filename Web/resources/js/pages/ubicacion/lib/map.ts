import type { StyleSpecification } from 'maplibre-gl';

/**
 * Utilidades del mapa de ubicación. Funciones puras, sin importar MapLibre en
 * tiempo de ejecución (no arrastran el chunk pesado a otras páginas).
 *
 * El proveedor de mosaicos está desacoplado: estilo desde `VITE_MAPA_ESTILO_URL`
 * si existe; si no, estilo raster de OpenStreetMap (sin clave) con atribución.
 */
export function mapStyle(): string | StyleSpecification {
    const env = (
        import.meta.env as Record<string, string | boolean | undefined>
    ).VITE_MAPA_ESTILO_URL;

    if (typeof env === 'string' && env.length > 0) {
        return env;
    }

    return {
        version: 8,
        sources: {
            osm: {
                type: 'raster',
                tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                tileSize: 256,
                attribution: '© OpenStreetMap',
            },
        },
        layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
    };
}

/** Vista por defecto (Ciudad de México). [lng, lat] */
export const DEFAULT_CENTER: [number, number] = [-99.1332, 19.4326];
export const DEFAULT_ZOOM = 5;

export type LngLat = [number, number];
export type Bounds = [number, number, number, number];

/** Escapa texto para HTML de popups: los nombres son datos, nunca marcado. */
export function escapeHtml(value: unknown): string {
    const text =
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
            ? String(value)
            : '';

    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/** Token de diseño (variable CSS en :root) como color que MapLibre entiende. */
export function cssToken(name: string, fallback = 'gray'): string {
    if (typeof window === 'undefined') {
        return fallback;
    }

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();

    return /^(#|rgb|hsl|oklch|oklab|lab|lch|color\()/i.test(value)
        ? value
        : fallback;
}

/** El usuario pidió menos movimiento: cámara sin animación. */
export function reducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ??
            false)
    );
}

/** Extensión [minLng, minLat, maxLng, maxLat] de un conjunto de puntos. */
export function boundsOf(points: LngLat[]): Bounds | null {
    if (points.length === 0) {
        return null;
    }

    return [
        Math.min(...points.map((p) => p[0])),
        Math.min(...points.map((p) => p[1])),
        Math.max(...points.map((p) => p[0])),
        Math.max(...points.map((p) => p[1])),
    ];
}

/**
 * Círculo (precisión o geocerca) como anillo GeoJSON. Aproximación
 * equirectangular, suficiente para estos radios.
 */
export function circleRing(
    center: LngLat,
    radiusMeters: number,
    sides = 48,
): LngLat[] {
    const [lng, lat] = center;
    const dLat = radiusMeters / 111_320;
    const dLng =
        radiusMeters /
        (111_320 * Math.max(Math.cos((lat * Math.PI) / 180), 1e-6));
    const ring: LngLat[] = [];

    for (let i = 0; i < sides; i++) {
        const a = (i / sides) * 2 * Math.PI;
        ring.push([lng + dLng * Math.cos(a), lat + dLat * Math.sin(a)]);
    }

    ring.push(ring[0] as LngLat);

    return ring;
}

/** Brecha (s) a partir de la cual el recorrido se corta en lugar de unir puntos. */
export const GAP_SECONDS = 300;

/**
 * Divide el recorrido en segmentos continuos: dos puntos separados más de
 * `gap` segundos inician un segmento nuevo (la brecha nunca se rellena).
 */
export function segmentTrack(
    points: Array<{ longitude: number; latitude: number; captured_at: string }>,
    gap = GAP_SECONDS,
): LngLat[][] {
    const segments: LngLat[][] = [];
    let current: LngLat[] = [];
    let previous: number | null = null;

    for (const p of points) {
        const t = Date.parse(p.captured_at);

        if (previous !== null && (t - previous) / 1000 > gap) {
            if (current.length >= 2) {
                segments.push(current);
            }

            current = [];
        }

        current.push([p.longitude, p.latitude]);
        previous = t;
    }

    if (current.length >= 2) {
        segments.push(current);
    }

    return segments;
}

/** «hace 3 min», «hace 2 h»; relativo a `now` (ms). */
export function agoText(iso: string | null | undefined, now: number): string {
    if (!iso) {
        return 'sin dato';
    }

    const t = Date.parse(iso);

    if (Number.isNaN(t)) {
        return 'sin dato';
    }

    const s = Math.max(0, Math.round((now - t) / 1000));

    if (s < 45) {
        return 'ahora';
    }

    const m = Math.round(s / 60);

    if (m < 60) {
        return `hace ${m} min`;
    }

    const h = Math.floor(m / 60);

    if (h < 24) {
        return `hace ${h} h`;
    }

    return `hace ${Math.floor(h / 24)} d`;
}

/** «±8 m» o «Sin dato». */
export function accuracyText(meters: number | null | undefined): string {
    return typeof meters === 'number' && Number.isFinite(meters)
        ? `±${Math.ceil(meters)} m`
        : 'Sin dato';
}

/** Clase visual de la precisión (nunca filtra puntos). */
export function accuracyClass(
    meters: number | null | undefined,
): 'good' | 'fair' | 'poor' | null {
    if (typeof meters !== 'number' || !Number.isFinite(meters)) {
        return null;
    }

    if (meters <= 25) {
        return 'good';
    }

    return meters <= 60 ? 'fair' : 'poor';
}

/** Iniciales para el marcador («Ana Pérez» → «AP»). */
export function initials(name: string | null | undefined): string {
    const parts = (name ?? '')
        .trim()
        .split(/\s+/)
        .filter((p) => /^\p{L}/u.test(p));

    if (parts.length === 0) {
        return '?';
    }

    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';

    return (first + last).toLocaleUpperCase('es-MX');
}

/** Distancia legible: «850 m», «12.4 km». */
export function distanceText(meters: number): string {
    return meters < 1000
        ? `${Math.round(meters)} m`
        : `${(meters / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 })} km`;
}

/** Hora corta en la zona indicada. */
export function timeIn(
    iso: string | null | undefined,
    timeZone: string,
): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat('es-MX', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone,
    }).format(new Date(iso));
}

/** Estado del marcador → token de color. */
export const STATE_TOKEN: Record<'on_shift' | 'stale' | 'off_shift', string> = {
    on_shift: '--success',
    stale: '--warning',
    off_shift: '--muted-foreground',
};

/** Tipo de marcaje → letra y token de color. */
export const PUNCH_GLYPH: Record<string, { letter: string; token: string }> = {
    clock_in: { letter: 'E', token: '--success' },
    clock_out: { letter: 'S', token: '--danger' },
    break_start: { letter: 'D', token: '--warning' },
    break_end: { letter: 'R', token: '--info' },
};
