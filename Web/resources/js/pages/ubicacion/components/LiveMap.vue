<script setup lang="ts">
/**
 * Mapa MapLibre de ubicación en tiempo real: marcadores de última posición,
 * círculos de precisión, sucursales con geocercas y recorrido del día con
 * marcajes superpuestos. Se carga de forma asíncrona (chunk propio).
 */
import 'maplibre-gl/dist/maplibre-gl.css';
import '../lib/maplibreWorker';
import * as maplibregl from 'maplibre-gl';
import type { GeoJSONSource } from 'maplibre-gl';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import type { BranchPin, LiveMarker, LocationTrack } from '@/types/operacion';
import {
    DEFAULT_CENTER,
    DEFAULT_ZOOM,
    PUNCH_GLYPH,
    STATE_TOKEN,
    accuracyText,
    boundsOf,
    circleRing,
    cssToken,
    escapeHtml,
    initials,
    mapStyle,
    reducedMotion,
    segmentTrack,
    timeIn,
} from '../lib/map';
import type { Bounds, LngLat } from '../lib/map';

const props = defineProps<{
    markers: LiveMarker[];
    branches: BranchPin[];
    track: LocationTrack | null;
    selectedId: number | null;
    timezone: string;
}>();

const emit = defineEmits<{ select: [employeeId: number] }>();

type Feature = {
    type: 'Feature';
    geometry: unknown;
    properties: Record<string, unknown>;
};
type FeatureCollection = { type: 'FeatureCollection'; features: Feature[] };

const EMPTY: FeatureCollection = { type: 'FeatureCollection', features: [] };

const container = ref<HTMLDivElement | null>(null);
const map = shallowRef<maplibregl.Map | null>(null);
const ready = ref(false);
let popup: maplibregl.Popup | null = null;
const liveMarkers = new Map<number, maplibregl.Marker>();
let branchMarkers: maplibregl.Marker[] = [];
let trackMarkers: maplibregl.Marker[] = [];
let framedOnce = false;
let themeObserver: MutationObserver | null = null;

function openPopup(coord: LngLat, html: string): void {
    const m = map.value;

    if (!m) {
        return;
    }

    popup?.remove();
    popup = new maplibregl.Popup({ offset: 16, maxWidth: '18rem' })
        .setLngLat(coord)
        .setHTML(
            `<div class="text-xs leading-relaxed text-foreground">${html}</div>`,
        )
        .addTo(m);
}

function fit(bounds: Bounds | null, maxZoom = 16): void {
    const m = map.value;

    if (!m || !bounds) {
        return;
    }

    const [a, b, c, d] = bounds;

    if (a === c && b === d) {
        m.jumpTo({ center: [a, b], zoom: Math.min(maxZoom, 15) });

        return;
    }

    m.fitBounds(
        [
            [a, b],
            [c, d],
        ],
        { padding: 56, maxZoom, duration: reducedMotion() ? 0 : 700 },
    );
}

/* ─────────────── Marcadores en vivo ─────────────── */

function markerPopup(mk: LiveMarker): string {
    const name = escapeHtml(mk.employee?.name ?? 'Colaborador');
    const branch = mk.employee?.branch
        ? `<br><span class="text-muted-foreground">${escapeHtml(mk.employee.branch)}</span>`
        : '';
    const punch = mk.last_punch
        ? `<br>Último marcaje: ${escapeHtml(mk.last_punch.type_label)} ${escapeHtml(timeIn(mk.last_punch.at, props.timezone))}`
        : '';
    const mocked = mk.is_mocked
        ? '<br><strong style="color:var(--danger)">Ubicación simulada detectada</strong>'
        : '';

    return (
        `<strong>${name}</strong>${branch}<br>` +
        `${escapeHtml(mk.state_label)} · ${escapeHtml(timeIn(mk.captured_at, props.timezone))} · ${escapeHtml(accuracyText(mk.accuracy_m))}` +
        punch +
        mocked
    );
}

function styleMarkerEl(el: HTMLElement, mk: LiveMarker): void {
    const color = cssToken(STATE_TOKEN[mk.state], '#6b7280');
    const selected = props.selectedId === mk.employee_id;
    el.style.background = color;
    el.style.boxShadow = selected
        ? `0 0 0 3px ${cssToken('--background', '#fff')}, 0 0 0 6px ${color}`
        : `0 0 0 2px ${cssToken('--background', '#fff')}, 0 4px 10px rgb(0 0 0 / 0.25)`;
    el.style.outline = mk.is_mocked
        ? `2px dashed ${cssToken('--danger', 'red')}`
        : 'none';
    el.style.outlineOffset = '3px';
    el.style.opacity = mk.state === 'stale' ? '0.75' : '1';
    el.style.zIndex = selected ? '5' : '1';
    el.textContent = initials(mk.employee?.name);
    el.setAttribute(
        'aria-label',
        `${mk.employee?.name ?? 'Colaborador'} · ${mk.state_label}`,
    );
}

function renderMarkers(): void {
    const m = map.value;

    if (!m) {
        return;
    }

    const seen = new Set<number>();

    for (const mk of props.markers) {
        seen.add(mk.employee_id);
        const coord: LngLat = [mk.longitude, mk.latitude];
        let marker = liveMarkers.get(mk.employee_id);

        if (!marker) {
            const el = document.createElement('button');
            el.type = 'button';
            el.className =
                'grid size-9 cursor-pointer place-items-center rounded-full text-[11px] font-bold text-white transition-transform hover:scale-110 focus-visible:outline-none';
            marker = new maplibregl.Marker({ element: el })
                .setLngLat(coord)
                .addTo(m);
            liveMarkers.set(mk.employee_id, marker);
        } else {
            marker.setLngLat(coord);
        }

        const el = marker.getElement();
        styleMarkerEl(el, mk);
        el.onclick = (event) => {
            event.stopPropagation();
            openPopup(coord, markerPopup(mk));
            emit('select', mk.employee_id);
        };
    }

    for (const [id, marker] of liveMarkers) {
        if (!seen.has(id)) {
            marker.remove();
            liveMarkers.delete(id);
        }
    }

    (m.getSource('precision') as GeoJSONSource | undefined)?.setData({
        type: 'FeatureCollection',
        features: props.markers
            .filter((mk) => (mk.accuracy_m ?? 0) > 0)
            .map((mk) => ({
                type: 'Feature',
                geometry: {
                    type: 'Polygon',
                    coordinates: [
                        circleRing(
                            [mk.longitude, mk.latitude],
                            mk.accuracy_m ?? 0,
                        ),
                    ],
                },
                properties: { state: mk.state },
            })),
    } as never);
}

/* ─────────────── Sucursales y geocercas ─────────────── */

function renderBranches(): void {
    const m = map.value;

    if (!m) {
        return;
    }

    branchMarkers.forEach((mk) => mk.remove());
    branchMarkers = [];

    const fences: Feature[] = [];

    for (const b of props.branches) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className =
            'grid size-6 place-items-center rounded-md border-2 text-[10px] font-bold shadow';
        el.style.background = cssToken('--card', '#fff');
        el.style.borderColor = cssToken('--primary', '#4f46e5');
        el.style.color = cssToken('--foreground', '#111');
        el.textContent = '⌂';
        el.setAttribute('aria-label', `Sucursal ${b.name}`);
        const coord: LngLat = [b.longitude, b.latitude];
        el.onclick = (event) => {
            event.stopPropagation();
            const fenceText = b.geofences.length
                ? `<br>${b.geofences.map((g) => `${escapeHtml(g.name)} · ${escapeHtml(g.radius_meters)} m`).join('<br>')}`
                : '<br><span class="text-muted-foreground">Sin geocerca</span>';
            openPopup(
                coord,
                `<strong>${escapeHtml(b.name)}</strong>${fenceText}`,
            );
        };
        branchMarkers.push(
            new maplibregl.Marker({ element: el }).setLngLat(coord).addTo(m),
        );

        for (const g of b.geofences) {
            const center: LngLat = [
                g.longitude ?? b.longitude,
                g.latitude ?? b.latitude,
            ];
            fences.push({
                type: 'Feature',
                geometry: {
                    type: 'Polygon',
                    coordinates: [circleRing(center, g.radius_meters)],
                },
                properties: { name: g.name },
            });
        }
    }

    (m.getSource('fences') as GeoJSONSource | undefined)?.setData({
        type: 'FeatureCollection',
        features: fences,
    } as never);
}

/* ─────────────── Recorrido del día ─────────────── */

function punchIcon(type: string): HTMLElement {
    const glyph = PUNCH_GLYPH[type] ?? { letter: '•', token: '--primary' };
    const el = document.createElement('button');
    el.type = 'button';
    el.className =
        'grid size-7 place-items-center rounded-full border-2 text-[11px] font-bold shadow-md';
    el.style.background = cssToken(glyph.token, '#2563eb');
    el.style.borderColor = cssToken('--background', '#fff');
    el.style.color = '#fff';
    el.textContent = glyph.letter;

    return el;
}

function endpointIcon(label: string): HTMLElement {
    const el = document.createElement('div');
    el.className = 'rounded-full px-2 py-0.5 text-[10px] font-semibold shadow';
    el.style.background = cssToken('--foreground', '#111');
    el.style.color = cssToken('--background', '#fff');
    el.textContent = label;

    return el;
}

function renderTrack(frame: boolean): void {
    const m = map.value;

    if (!m) {
        return;
    }

    trackMarkers.forEach((mk) => mk.remove());
    trackMarkers = [];

    const track = props.track;
    const segments = track ? segmentTrack(track.points) : [];

    (m.getSource('track') as GeoJSONSource | undefined)?.setData({
        type: 'FeatureCollection',
        features: segments.map((coords) => ({
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: coords },
            properties: {},
        })),
    } as never);

    (m.getSource('track-points') as GeoJSONSource | undefined)?.setData({
        type: 'FeatureCollection',
        features: (track?.points ?? []).map((p) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [p.longitude, p.latitude] },
            properties: {
                at: p.captured_at,
                accuracy: p.accuracy_m,
                mocked: p.is_mocked ? 1 : 0,
            },
        })),
    } as never);

    if (!track) {
        return;
    }

    const first = track.points[0];
    const last = track.points[track.points.length - 1];

    if (first) {
        trackMarkers.push(
            new maplibregl.Marker({
                element: endpointIcon(
                    `Inicio ${timeIn(first.captured_at, props.timezone)}`,
                ),
                anchor: 'bottom',
                offset: [0, -8],
            })
                .setLngLat([first.longitude, first.latitude])
                .addTo(m),
        );
    }

    if (last && last !== first) {
        trackMarkers.push(
            new maplibregl.Marker({
                element: endpointIcon(
                    `Fin ${timeIn(last.captured_at, props.timezone)}`,
                ),
                anchor: 'bottom',
                offset: [0, -8],
            })
                .setLngLat([last.longitude, last.latitude])
                .addTo(m),
        );
    }

    for (const p of track.punches) {
        if (p.latitude === null || p.longitude === null) {
            continue;
        }

        const coord: LngLat = [p.longitude, p.latitude];
        const el = punchIcon(p.type);
        el.setAttribute(
            'aria-label',
            `${p.type_label} ${timeIn(p.server_time, props.timezone)}`,
        );
        el.onclick = (event) => {
            event.stopPropagation();
            openPopup(
                coord,
                `<strong>${escapeHtml(p.type_label)}</strong> · ${escapeHtml(timeIn(p.server_time, props.timezone))}<br>` +
                    `${escapeHtml(p.auth_method_label)} · ${escapeHtml(p.source_label)}<br>` +
                    `Precisión ${escapeHtml(accuracyText(p.accuracy_meters))}` +
                    (p.geofence
                        ? `<br>Geocerca: ${escapeHtml(p.geofence)}`
                        : '') +
                    (p.status === 'flagged'
                        ? '<br><strong style="color:var(--warning)">En revisión</strong>'
                        : ''),
            );
        };
        trackMarkers.push(
            new maplibregl.Marker({ element: el }).setLngLat(coord).addTo(m),
        );
    }

    if (frame) {
        const coords: LngLat[] = [
            ...track.points.map((p) => [p.longitude, p.latitude] as LngLat),
            ...track.punches
                .filter((p) => p.latitude !== null && p.longitude !== null)
                .map(
                    (p) =>
                        [p.longitude as number, p.latitude as number] as LngLat,
                ),
        ];
        fit(boundsOf(coords), 17);
    }
}

/* ─────────────── Tema ─────────────── */

function applyTheme(): void {
    const m = map.value;

    if (!m || !ready.value) {
        return;
    }

    const primary = cssToken('--primary', '#4f46e5');
    const info = cssToken('--info', '#2563eb');
    const bg = cssToken('--background', '#fff');
    m.setPaintProperty('fences-fill', 'fill-color', primary);
    m.setPaintProperty('fences-line', 'line-color', primary);
    m.setPaintProperty('precision-fill', 'fill-color', [
        'match',
        ['get', 'state'],
        'on_shift',
        cssToken('--success', '#16a34a'),
        'stale',
        cssToken('--warning', '#d97706'),
        cssToken('--muted-foreground', '#6b7280'),
    ]);
    m.setPaintProperty('track-casing', 'line-color', bg);
    m.setPaintProperty('track-line', 'line-color', info);
    m.setPaintProperty('track-points', 'circle-color', [
        'case',
        ['==', ['get', 'mocked'], 1],
        cssToken('--danger', '#dc2626'),
        info,
    ]);
    m.setPaintProperty('track-points', 'circle-stroke-color', bg);
    renderMarkers();
    renderBranches();
}

/* ─────────────── Ciclo de vida ─────────────── */

function create(): void {
    if (!container.value) {
        return;
    }

    const m = new maplibregl.Map({
        container: container.value,
        style: mapStyle(),
        center: DEFAULT_CENTER,
        zoom: DEFAULT_ZOOM,
        attributionControl: { compact: true },
    });
    map.value = m;
    m.addControl(
        new maplibregl.NavigationControl({ showCompass: false }),
        'top-right',
    );
    m.addControl(
        new maplibregl.ScaleControl({ unit: 'metric' }),
        'bottom-right',
    );

    m.on('load', () => {
        m.addSource('fences', { type: 'geojson', data: EMPTY as never });
        m.addSource('precision', { type: 'geojson', data: EMPTY as never });
        m.addSource('track', { type: 'geojson', data: EMPTY as never });
        m.addSource('track-points', { type: 'geojson', data: EMPTY as never });

        m.addLayer({
            id: 'fences-fill',
            type: 'fill',
            source: 'fences',
            paint: { 'fill-opacity': 0.08 },
        });
        m.addLayer({
            id: 'fences-line',
            type: 'line',
            source: 'fences',
            paint: {
                'line-width': 2,
                'line-opacity': 0.6,
                'line-dasharray': [2, 2],
            },
        });
        m.addLayer({
            id: 'precision-fill',
            type: 'fill',
            source: 'precision',
            paint: { 'fill-opacity': 0.14 },
        });
        m.addLayer({
            id: 'track-casing',
            type: 'line',
            source: 'track',
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-width': 7, 'line-opacity': 0.9 },
        });
        m.addLayer({
            id: 'track-line',
            type: 'line',
            source: 'track',
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-width': 4, 'line-opacity': 0.95 },
        });
        m.addLayer({
            id: 'track-points',
            type: 'circle',
            source: 'track-points',
            minzoom: 13,
            paint: { 'circle-radius': 3, 'circle-stroke-width': 1 },
        });

        m.on('click', 'track-points', (event) => {
            const f = event.features?.[0];

            if (!f) {
                return;
            }

            const p = f.properties as {
                at?: string;
                accuracy?: number | null;
                mocked?: number;
            };
            openPopup(
                [event.lngLat.lng, event.lngLat.lat],
                `<strong>${escapeHtml(timeIn(p.at ?? null, props.timezone))}</strong> · ${escapeHtml(accuracyText(p.accuracy ?? null))}` +
                    (p.mocked
                        ? '<br><strong style="color:var(--danger)">Simulada</strong>'
                        : ''),
            );
        });
        m.on(
            'mouseenter',
            'track-points',
            () => (m.getCanvas().style.cursor = 'pointer'),
        );
        m.on(
            'mouseleave',
            'track-points',
            () => (m.getCanvas().style.cursor = ''),
        );

        ready.value = true;
        applyTheme();
        renderTrack(true);

        if (!props.track) {
            frameAll();
        }
    });
}

function frameAll(): void {
    const coords: LngLat[] = [
        ...props.markers.map((mk) => [mk.longitude, mk.latitude] as LngLat),
        ...props.branches.map((b) => [b.longitude, b.latitude] as LngLat),
    ];

    if (coords.length > 0) {
        framedOnce = true;
        fit(boundsOf(coords), 15);
    }
}

/** Centra el mapa en un colaborador (desde la lista lateral). */
function focusEmployee(employeeId: number): void {
    const mk = props.markers.find((x) => x.employee_id === employeeId);
    const m = map.value;

    if (!mk || !m) {
        return;
    }

    const coord: LngLat = [mk.longitude, mk.latitude];
    m.easeTo({
        center: coord,
        zoom: Math.max(m.getZoom(), 15),
        duration: reducedMotion() ? 0 : 600,
    });
    openPopup(coord, markerPopup(mk));
}

defineExpose({ focusEmployee, frameAll });

watch(
    () => props.markers,
    () => {
        if (!ready.value) {
            return;
        }

        renderMarkers();

        if (!framedOnce && !props.track) {
            frameAll();
        }
    },
);
watch(
    () => props.selectedId,
    () => ready.value && renderMarkers(),
);
watch(
    () => props.branches,
    () => ready.value && renderBranches(),
);
watch(
    () => props.track,
    (next, previous) => {
        if (!ready.value) {
            return;
        }

        const changed =
            next?.employee?.id !== previous?.employee?.id ||
            next?.date !== previous?.date;
        renderTrack(changed && next !== null);
    },
);

onMounted(() => {
    create();
    themeObserver = new MutationObserver(() => applyTheme());
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class', 'data-theme'],
    });
});

onBeforeUnmount(() => {
    themeObserver?.disconnect();
    popup?.remove();
    liveMarkers.forEach((mk) => mk.remove());
    branchMarkers.forEach((mk) => mk.remove());
    trackMarkers.forEach((mk) => mk.remove());
    map.value?.remove();
    map.value = null;
});
</script>

<template>
    <div class="relative size-full overflow-hidden">
        <!-- Posición en línea: el CSS de MapLibre (sin capa) fija `.maplibregl-map { position: relative }` y anularía `absolute`. -->
        <div
            ref="container"
            style="position: absolute; inset: 0"
            role="region"
            aria-label="Mapa de ubicación"
        />
        <div
            v-if="!ready"
            class="absolute inset-0 grid place-items-center bg-muted/40 text-sm text-muted-foreground"
        >
            Cargando mapa…
        </div>
    </div>
</template>
