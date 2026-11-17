<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import {
    Activity,
    Crosshair,
    Footprints,
    LocateFixed,
    MapPin,
    MapPinOff,
    Pause,
    Play,
    Route,
    TriangleAlert,
    UserRound,
    Users,
    WifiOff,
    X,
} from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';
import {
    ComboboxField,
    DatePicker,
    EmptyState,
    FilterBar,
    PageHeader,
    SearchInput,
    SectionCard,
    SelectField,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { useFilters } from '@/composables/useFilters';
import { cn } from '@/lib/utils';
import type { HelpStep } from '@/types';
import type { LiveMarker, LocationIndexProps } from '@/types/operacion';
import {
    STATE_TOKEN,
    accuracyClass,
    accuracyText,
    agoText,
    distanceText,
    initials,
    timeIn,
} from './lib/map';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Ubicación', href: '/ubicacion' },
        ],
    },
});

const props = defineProps<LocationIndexProps>();

// MapLibre en su propio chunk: sólo se descarga en esta página.
const LiveMap = defineAsyncComponent(() => import('./components/LiveMap.vue'));

const mapRef = ref<{
    focusEmployee: (id: number) => void;
    frameAll: () => void;
} | null>(null);

const { filters, activeCount, reset, processing } = useFilters(
    {
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        status: props.filters.status as string | null,
        employee_id: props.filters.employee_id,
        date: props.filters.date,
    },
    {
        only: ['markers', 'track', 'filters', 'server_time'],
        defaults: { date: props.filters.date },
    },
);

const { start, stop, polling } = usePoll(30000, {
    only: ['markers', 'track', 'server_time'],
});

// Reloj local para «hace X min», sincronizado con la hora del servidor.
const offset = ref(0);
const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

function syncClock(): void {
    const server = Date.parse(props.server_time);
    offset.value = Number.isNaN(server) ? 0 : server - Date.now();
}

onMounted(() => {
    syncClock();
    ticker = setInterval(() => {
        now.value = Date.now() + offset.value;
    }, 15000);
    now.value = Date.now() + offset.value;
});
onBeforeUnmount(() => clearInterval(ticker));

const counts = computed(() => {
    const c = {
        total: props.markers.length,
        on_shift: 0,
        stale: 0,
        off_shift: 0,
        mocked: 0,
    };

    for (const m of props.markers) {
        c[m.state]++;

        if (m.is_mocked) {
            c.mocked++;
        }
    }

    return c;
});

const selectedId = computed(() =>
    filters.employee_id ? Number(filters.employee_id) : null,
);

const sortedMarkers = computed(() =>
    [...props.markers].sort((a, b) => {
        const order = { on_shift: 0, stale: 1, off_shift: 2 } as const;

        return (
            order[a.state] - order[b.state] ||
            (a.employee?.name ?? '').localeCompare(b.employee?.name ?? '', 'es')
        );
    }),
);

function selectEmployee(id: number): void {
    filters.employee_id = String(id);
    mapRef.value?.focusEmployee(id);
}

function clearTrack(): void {
    filters.employee_id = null;
}

function togglePolling(): void {
    if (polling.value) {
        stop();
    } else {
        start();
    }
}

function stateColor(state: LiveMarker['state']): string {
    return `var(${STATE_TOKEN[state]})`;
}

const accuracyTone: Record<'good' | 'fair' | 'poor', string> = {
    good: 'text-success-soft-foreground',
    fair: 'text-warning-soft-foreground',
    poor: 'text-danger-soft-foreground',
};

const today = new Date().toISOString().slice(0, 10);

const help: HelpStep[] = [
    {
        icon: MapPin,
        title: 'Última posición',
        text: 'Cada punto es la ubicación más reciente enviada por la app del colaborador.',
    },
    {
        icon: WifiOff,
        title: 'Sin señal',
        text: `Sin reportes en más de ${props.stale_minutes} min. El círculo indica la precisión del GPS.`,
    },
    {
        icon: Route,
        title: 'Recorrido',
        text: 'Elige un colaborador y un día para ver su trayecto y los marcajes registrados.',
    },
    {
        icon: TriangleAlert,
        title: 'Ubicación simulada',
        text: 'El borde punteado rojo señala que el dispositivo reportó una ubicación falsa.',
    },
    {
        icon: Activity,
        title: 'Actualización',
        text: 'El mapa se actualiza cada 30 s. Puedes pausarlo.',
    },
];
</script>

<template>
    <PageHeader
        title="Ubicación"
        description="Posición en tiempo real y recorridos del día."
        :icon="LocateFixed"
        :help="help"
        help-title="Ubicación en tiempo real"
    >
        <template #actions>
            <Button
                variant="outline"
                size="sm"
                :aria-pressed="polling"
                @click="togglePolling"
            >
                <component :is="polling ? Pause : Play" aria-hidden="true" />
                {{ polling ? 'Pausar' : 'Reanudar' }}
            </Button>
        </template>
    </PageHeader>

    <!-- Indicadores rápidos -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div
            v-for="item in [
                {
                    key: 'total',
                    label: 'Con posición',
                    value: counts.total,
                    icon: Users,
                    color: 'var(--primary)',
                },
                {
                    key: 'on_shift',
                    label: 'En turno',
                    value: counts.on_shift,
                    icon: Footprints,
                    color: stateColor('on_shift'),
                },
                {
                    key: 'stale',
                    label: 'Sin señal',
                    value: counts.stale,
                    icon: WifiOff,
                    color: stateColor('stale'),
                },
                {
                    key: 'off_shift',
                    label: 'Fuera de turno',
                    value: counts.off_shift,
                    icon: UserRound,
                    color: stateColor('off_shift'),
                },
            ]"
            :key="item.key"
            class="flex items-center gap-3 rounded-xl border bg-card px-4 py-3 shadow-xs"
        >
            <span
                class="grid size-9 shrink-0 place-items-center rounded-lg"
                :style="{
                    background: `color-mix(in oklab, ${item.color} 16%, transparent)`,
                    color: item.color,
                }"
            >
                <component :is="item.icon" class="size-4" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="numeric text-xl leading-none font-semibold">
                    {{ item.value }}
                </p>
                <p class="mt-1 truncate text-xs text-muted-foreground">
                    {{ item.label }}
                </p>
            </div>
        </div>
    </div>

    <FilterBar :active-count="activeCount" class="mt-4" @reset="reset">
        <template #search>
            <SearchInput
                v-model="filters.search"
                placeholder="Buscar colaborador…"
                :loading="processing"
            />
        </template>
        <SelectField
            v-model="filters.branch_id"
            :options="options.branches"
            placeholder="Sucursal"
            prefix="Sucursal:"
            size="sm"
            clearable
            class="w-full sm:w-52"
        />
        <SelectField
            v-model="filters.status"
            :options="options.statuses"
            placeholder="Estado"
            prefix="Estado:"
            size="sm"
            clearable
            class="w-full sm:w-44"
        />
        <template #actions>
            <span
                v-if="counts.mocked > 0"
                class="inline-flex items-center gap-1.5 rounded-full bg-danger-soft px-2.5 py-1 text-xs font-medium text-danger-soft-foreground"
            >
                <TriangleAlert class="size-3.5" aria-hidden="true" />
                {{ counts.mocked }} con ubicación simulada
            </span>
        </template>
    </FilterBar>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <!-- Mapa -->
        <div
            class="relative h-[62vh] min-h-[420px] overflow-hidden rounded-xl border bg-muted/30 shadow-xs lg:h-[calc(100vh-18rem)]"
        >
            <Suspense>
                <LiveMap
                    ref="mapRef"
                    :markers="markers"
                    :branches="branches"
                    :track="track"
                    :selected-id="selectedId"
                    :timezone="timezone"
                    @select="selectEmployee"
                />
                <template #fallback>
                    <Skeleton class="size-full" />
                </template>
            </Suspense>

            <div
                v-if="markers.length === 0 && !track"
                class="pointer-events-none absolute inset-x-4 top-4 flex justify-center"
            >
                <div
                    class="pointer-events-auto flex items-center gap-2 rounded-full border bg-card/95 px-4 py-2 text-sm shadow-md backdrop-blur"
                >
                    <MapPinOff
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Sin posiciones en las últimas 24 h
                </div>
            </div>

            <div
                class="absolute bottom-3 left-3 flex flex-wrap gap-1.5 rounded-lg border bg-card/95 px-2.5 py-1.5 text-[11px] shadow-sm backdrop-blur"
            >
                <span
                    v-for="s in ['on_shift', 'stale', 'off_shift'] as const"
                    :key="s"
                    class="inline-flex items-center gap-1"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: stateColor(s) }"
                    />
                    {{
                        {
                            on_shift: 'En turno',
                            stale: 'Sin señal',
                            off_shift: 'Fuera de turno',
                        }[s]
                    }}
                </span>
            </div>

            <Button
                v-if="markers.length > 0"
                variant="secondary"
                size="icon"
                class="absolute top-3 left-3 shadow-md"
                aria-label="Ver todos"
                @click="mapRef?.frameAll()"
            >
                <Crosshair aria-hidden="true" />
            </Button>
        </div>

        <!-- Panel lateral -->
        <div class="flex min-w-0 flex-col gap-4">
            <SectionCard title="Recorrido" :icon="Route" padding="sm">
                <template #actions>
                    <Button
                        v-if="filters.employee_id"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Quitar recorrido"
                        @click="clearTrack"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </template>
                <div class="grid gap-2">
                    <ComboboxField
                        v-model="filters.employee_id"
                        :options="options.employees"
                        placeholder="Elegir colaborador…"
                        search-placeholder="Buscar por nombre o número…"
                        clearable
                    />
                    <DatePicker v-model="filters.date" :max="today" />
                </div>

                <template v-if="track">
                    <div
                        v-if="
                            track.points.length === 0 &&
                            track.punches.length === 0
                        "
                        class="mt-3"
                    >
                        <EmptyState
                            size="sm"
                            :icon="MapPinOff"
                            title="Sin recorrido"
                            description="No hay ubicaciones ni marcajes ese día."
                            bordered
                        />
                    </div>
                    <template v-else>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div class="rounded-lg bg-muted/60 px-3 py-2">
                                <dt class="text-xs text-muted-foreground">
                                    Distancia
                                </dt>
                                <dd class="numeric font-semibold">
                                    {{ distanceText(track.summary.distance_m) }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-muted/60 px-3 py-2">
                                <dt class="text-xs text-muted-foreground">
                                    Puntos
                                </dt>
                                <dd class="numeric font-semibold">
                                    {{ track.summary.points }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-muted/60 px-3 py-2">
                                <dt class="text-xs text-muted-foreground">
                                    Primero
                                </dt>
                                <dd class="numeric font-semibold">
                                    {{
                                        timeIn(track.summary.first_at, timezone)
                                    }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-muted/60 px-3 py-2">
                                <dt class="text-xs text-muted-foreground">
                                    Último
                                </dt>
                                <dd class="numeric font-semibold">
                                    {{
                                        timeIn(track.summary.last_at, timezone)
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="track.summary.mocked > 0"
                            class="mt-2 flex items-center gap-1.5 text-xs font-medium text-danger-soft-foreground"
                        >
                            <TriangleAlert
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ track.summary.mocked }} puntos con ubicación
                            simulada
                        </p>

                        <ol
                            v-if="track.punches.length > 0"
                            class="mt-3 grid gap-1.5"
                        >
                            <li
                                v-for="p in track.punches"
                                :key="p.uuid"
                                class="flex items-center gap-2.5 rounded-lg border px-2.5 py-1.5 text-sm"
                            >
                                <span
                                    class="grid size-6 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white"
                                    :style="{
                                        background: `var(${{ clock_in: '--success', clock_out: '--danger', break_start: '--warning', break_end: '--info' }[p.type]})`,
                                    }"
                                    >{{
                                        {
                                            clock_in: 'E',
                                            clock_out: 'S',
                                            break_start: 'D',
                                            break_end: 'R',
                                        }[p.type]
                                    }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">
                                        {{ p.type_label }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ p.auth_method_label
                                        }}<template v-if="p.geofence">
                                            · {{ p.geofence }}</template
                                        >
                                        <template v-if="p.latitude === null">
                                            · sin ubicación</template
                                        >
                                    </p>
                                </div>
                                <span class="numeric text-xs font-semibold">{{
                                    timeIn(p.server_time, timezone)
                                }}</span>
                            </li>
                        </ol>
                    </template>
                </template>
                <p v-else class="mt-3 text-xs text-muted-foreground">
                    Elige a alguien en el mapa o en la lista.
                </p>
            </SectionCard>

            <SectionCard
                :title="`Colaboradores (${markers.length})`"
                :icon="Users"
                padding="none"
                class="min-h-0 flex-1"
            >
                <EmptyState
                    v-if="markers.length === 0"
                    size="sm"
                    :icon="MapPinOff"
                    title="Sin posiciones"
                    :description="
                        activeCount > 0
                            ? 'Prueba quitando filtros.'
                            : 'Aparecerán cuando la app reporte ubicación.'
                    "
                    class="py-8"
                />
                <ScrollArea v-else class="h-[26rem]">
                    <ul class="divide-y">
                        <li v-for="m in sortedMarkers" :key="m.employee_id">
                            <button
                                type="button"
                                :class="
                                    cn(
                                        'flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none',
                                        selectedId === m.employee_id &&
                                            'bg-primary-soft/60',
                                    )
                                "
                                @click="selectEmployee(m.employee_id)"
                            >
                                <span
                                    class="relative grid size-9 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white"
                                    :style="{ background: stateColor(m.state) }"
                                >
                                    {{ initials(m.employee?.name) }}
                                    <TriangleAlert
                                        v-if="m.is_mocked"
                                        class="absolute -right-1 -bottom-1 size-4 rounded-full bg-card p-0.5 text-danger"
                                        aria-label="Ubicación simulada"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{
                                            m.employee?.name ?? 'Colaborador'
                                        }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            m.employee?.branch ?? 'Sin sucursal'
                                        }}
                                        <template v-if="m.last_punch">
                                            · {{ m.last_punch.type_label }}
                                            {{
                                                timeIn(
                                                    m.last_punch.at,
                                                    timezone,
                                                )
                                            }}</template
                                        >
                                    </span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span
                                        class="block text-xs font-medium"
                                        :style="{ color: stateColor(m.state) }"
                                        >{{ agoText(m.captured_at, now) }}</span
                                    >
                                    <span
                                        :class="
                                            cn(
                                                'block numeric text-[11px]',
                                                accuracyClass(m.accuracy_m)
                                                    ? accuracyTone[
                                                          accuracyClass(
                                                              m.accuracy_m,
                                                          )!
                                                      ]
                                                    : 'text-muted-foreground',
                                            )
                                        "
                                        >{{ accuracyText(m.accuracy_m) }}</span
                                    >
                                </span>
                            </button>
                        </li>
                    </ul>
                </ScrollArea>
            </SectionCard>
        </div>
    </div>
</template>
