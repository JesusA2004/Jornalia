<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarCheck2,
    Clock3,
    FileBarChart2,
    Fingerprint,
    LayoutGrid,
    Palmtree,
    Scale,
    SearchX,
    ShieldCheck,
    Wallet,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import ReportController from '@/actions/App/Http/Controllers/Web/Operacion/ReportController';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import { Button } from '@/components/ui/button';
import type { ReportCatalogItem, ReportIndexProps } from '@/types/operacion';
import ExportsList from './components/ExportsList.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Reportes', href: '/reportes' },
        ],
    },
});

const props = defineProps<ReportIndexProps>();

const CATEGORY: Record<
    string,
    { icon: LucideIcon; tone: string; ring: string }
> = {
    asistencia: {
        icon: CalendarCheck2,
        tone: 'bg-primary-soft text-primary-soft-foreground',
        ring: 'group-hover:ring-primary/30',
    },
    tiempo: {
        icon: Clock3,
        tone: 'bg-info-soft text-info-soft-foreground',
        ring: 'group-hover:ring-info/30',
    },
    ausencias: {
        icon: Palmtree,
        tone: 'bg-success-soft text-success-soft-foreground',
        ring: 'group-hover:ring-success/30',
    },
    legal: {
        icon: Scale,
        tone: 'bg-brand-soft text-brand-soft-foreground',
        ring: 'group-hover:ring-brand/30',
    },
    concentrados: {
        icon: LayoutGrid,
        tone: 'bg-warning-soft text-warning-soft-foreground',
        ring: 'group-hover:ring-warning/30',
    },
    nomina: {
        icon: Wallet,
        tone: 'bg-info-soft text-info-soft-foreground',
        ring: 'group-hover:ring-info/30',
    },
};

const fallback = {
    icon: FileBarChart2,
    tone: 'bg-surface-3 text-muted-foreground',
    ring: '',
};
const styleFor = (category: string) => CATEGORY[category] ?? fallback;

const search = ref('');

const normalize = (s: string) =>
    s
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

const featured = computed(() => props.catalog.find((r) => r.featured) ?? null);

const filtered = computed(() => {
    const term = normalize(search.value.trim());

    return props.catalog.filter(
        (r) =>
            !r.featured &&
            (term === '' ||
                normalize(
                    `${r.title} ${r.description} ${r.category_label}`,
                ).includes(term)),
    );
});

const groups = computed(() => {
    const map = new Map<
        string,
        { key: string; label: string; items: ReportCatalogItem[] }
    >();
    for (const item of filtered.value) {
        const group = map.get(item.category) ?? {
            key: item.category,
            label: item.category_label,
            items: [],
        };
        group.items.push(item);
        map.set(item.category, group);
    }

    return [...map.values()];
});

const help = [
    {
        title: 'Elige un reporte',
        text: 'Abre la vista previa con datos reales de tu alcance.',
        icon: FileBarChart2,
    },
    {
        title: 'Filtra el periodo',
        text: 'Sucursal, departamento, colaborador y estado.',
    },
    {
        title: 'Exporta',
        text: 'Excel, PDF o CSV en cualquier momento, con encabezado institucional, folio y SHA-256. Queda en la bitácora.',
        icon: Fingerprint,
    },
    {
        title: 'Volúmenes grandes',
        text: 'Se generan en segundo plano; el estado aparece en «Exportaciones recientes».',
    },
    {
        title: 'Inspección laboral',
        text: 'Incluye la verificación de la cadena de integridad al momento de generarlo.',
        icon: ShieldCheck,
    },
];
</script>

<template>
    <PageHeader
        title="Reportes"
        description="Vista previa y exportación con datos verificables."
        :icon="FileBarChart2"
        :help="help"
    />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="min-w-0 space-y-8">
            <!-- Reporte destacado: inspección laboral -->
            <Link
                v-if="featured"
                :href="ReportController.show(featured.key).url"
                class="group relative block overflow-hidden rounded-2xl border border-l-4 border-l-gold bg-card p-6 shadow-elev-1 transition-standard hover:border-border-strong hover:border-l-gold hover:shadow-elev-2 focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none sm:p-7"
            >
                <div
                    class="relative flex flex-col gap-5 sm:flex-row sm:items-center"
                >
                    <span
                        class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                    >
                        <Scale class="size-7" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <p
                            class="text-[0.6875rem] font-semibold tracking-[0.18em] text-gold-strong uppercase"
                        >
                            Para la autoridad laboral
                        </p>
                        <h2
                            class="font-display text-xl leading-tight font-bold sm:text-2xl"
                        >
                            {{ featured.title }}
                        </h2>
                        <p class="max-w-2xl text-sm text-muted-foreground">
                            {{ featured.description }}
                        </p>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <span
                                class="inline-flex items-center gap-1 rounded-full bg-card/80 px-2.5 py-1 text-xs font-medium ring-1 ring-border"
                            >
                                <Fingerprint
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Cadena de hash verificada
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-full bg-card/80 px-2.5 py-1 text-xs font-medium ring-1 ring-border"
                            >
                                Excel · PDF · CSV
                            </span>
                        </div>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 self-start rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-transform group-hover:translate-x-0.5 sm:self-center"
                    >
                        Generar
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </span>
                </div>
            </Link>

            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <h2 class="font-display text-lg font-bold">Catálogo</h2>
                <SearchInput
                    v-model="search"
                    placeholder="Buscar reporte…"
                    class="sm:w-72"
                />
            </div>

            <EmptyState
                v-if="groups.length === 0"
                :icon="SearchX"
                bordered
                class="rounded-2xl"
                title="Sin coincidencias"
                description="Prueba con otro término."
            >
                <template #actions>
                    <Button variant="outline" size="sm" @click="search = ''"
                        >Limpiar búsqueda</Button
                    >
                </template>
            </EmptyState>

            <section v-for="group in groups" :key="group.key" class="space-y-3">
                <div class="flex items-center gap-2">
                    <component
                        :is="styleFor(group.key).icon"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h3
                        class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </h3>
                    <span class="text-xs text-muted-foreground/70">{{
                        group.items.length
                    }}</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                    <Link
                        v-for="item in group.items"
                        :key="item.key"
                        :href="ReportController.show(item.key).url"
                        class="group flex min-w-0 items-start gap-3.5 rounded-xl border bg-card p-4 shadow-elev-1 transition-all hover:-translate-y-px hover:border-border-strong hover:shadow-elev-2 focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none"
                    >
                        <span
                            :class="[
                                'flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-transparent transition-shadow',
                                styleFor(item.category).tone,
                                styleFor(item.category).ring,
                            ]"
                        >
                            <component
                                :is="styleFor(item.category).icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0 flex-1 space-y-1">
                            <span
                                class="flex items-center gap-1 leading-tight font-semibold"
                            >
                                {{ item.title }}
                                <ArrowRight
                                    class="size-3.5 -translate-x-1 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100"
                                    aria-hidden="true"
                                />
                            </span>
                            <span
                                class="line-clamp-2 block text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ item.description }}
                            </span>
                        </span>
                    </Link>
                </div>
            </section>
        </div>

        <aside class="min-w-0 xl:sticky xl:top-4 xl:self-start">
            <ExportsList :exports="exports" :timezone="timezone" />
        </aside>
    </div>
</template>
