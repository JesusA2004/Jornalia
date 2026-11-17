<script setup lang="ts">
import {
    BookOpenCheck,
    CalendarClock,
    ExternalLink,
    Moon,
    ShieldAlert,
    ShieldCheck,
    Sun,
    Sunset,
    TimerReset,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';
import SettingsNav from './components/SettingsNav.vue';
import { civilDate, hours } from './format';
import type {
    LegalSource,
    LegalVacationTableRow,
    LegalVersionRow,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Normativa' },
        ],
    },
});

const props = defineProps<{
    versions: LegalVersionRow[];
    vacation_table: LegalVacationTableRow[];
    today: string;
}>();

const VERIFICATION: Record<string, { label: string; tone: Tone }> = {
    verified: { label: 'Verificada', tone: 'success' },
    unverified: { label: 'Sin verificar', tone: 'warning' },
    pending: { label: 'Pendiente', tone: 'warning' },
    provisional: { label: 'Provisional', tone: 'info' },
};

const verification = (status: string) =>
    VERIFICATION[status] ?? { label: status, tone: 'neutral' as Tone };

const currentId = computed(
    () => props.versions.find((v) => v.is_current)?.id ?? null,
);
const selectedId = ref<number | null>(
    currentId.value ?? props.versions[0]?.id ?? null,
);
const selected = computed(
    () => props.versions.find((v) => v.id === selectedId.value) ?? null,
);

function sourceTitle(source: LegalSource | string): string {
    return typeof source === 'string'
        ? source
        : String(
              source.title ??
                  source.name ??
                  source.reference ??
                  source.url ??
                  'Fuente',
          );
}

function sourceUrl(source: LegalSource | string): string | null {
    return typeof source === 'string'
        ? /^https?:\/\//.test(source)
            ? source
            : null
        : (source.url ?? null);
}

function sourceDate(source: LegalSource | string): string | null {
    return typeof source === 'string'
        ? null
        : ((source.published_on as string | undefined) ?? null);
}

const overtime = computed(() => {
    const o = (selected.value?.parameters.overtime ?? {}) as Record<
        string,
        unknown
    >;

    return {
        daily: Number(o.daily_max_minutes ?? 0),
        weekly: Number(o.weekly_max_minutes ?? 0),
        days: Number(o.max_days_per_week ?? 0),
        double: String(o.double_rate_multiplier ?? '—'),
        excess: String(o.excess_rate_multiplier ?? '—'),
    };
});

const vacationGroups = computed(() => {
    const map = new Map<string, LegalVacationTableRow[]>();

    for (const row of props.vacation_table) {
        const key = `${row.code}|${String(row.valid_from).slice(0, 10)}`;
        map.set(key, [...(map.get(key) ?? []), row]);
    }

    return [...map.values()];
});

const help = [
    {
        title: 'Versiones',
        text: 'Cada cálculo usa la versión vigente en la fecha del registro, también para fechas pasadas.',
        icon: CalendarClock,
    },
    {
        title: 'Verificación',
        text: 'Indica si los parámetros fueron contrastados con la publicación oficial.',
        icon: ShieldCheck,
    },
    {
        title: 'Integridad',
        text: 'Una huella detecta cualquier alteración de los parámetros fuera del procedimiento.',
        icon: ShieldAlert,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Normativa laboral vigente (sólo lectura)."
        :icon="BookOpenCheck"
        :help="help"
        help-title="Normativa"
    />

    <SettingsNav />

    <div class="grid gap-6 xl:grid-cols-[20rem_minmax(0,1fr)]">
        <nav aria-label="Versiones legales" class="grid content-start gap-2">
            <button
                v-for="version in versions"
                :key="version.id"
                type="button"
                :class="
                    cn(
                        'relative rounded-xl border bg-card px-4 py-3 text-left transition-standard hover:border-border-strong',
                        selectedId === version.id &&
                            'border-primary ring-1 ring-primary/30',
                    )
                "
                @click="selectedId = version.id"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-xs font-semibold">{{
                        version.code
                    }}</span>
                    <StatusBadge
                        v-if="version.is_current"
                        status="active"
                        label="Vigente"
                        tone="success"
                        size="sm"
                    />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ civilDate(version.valid_from) }} –
                    {{
                        version.valid_to
                            ? civilDate(version.valid_to)
                            : 'en adelante'
                    }}
                </p>
                <p class="mt-1 text-sm font-semibold tabular-nums">
                    {{ hours(version.parameters.weekly_ordinary_max_minutes) }}
                    / semana
                </p>
            </button>
        </nav>

        <div v-if="selected" class="grid content-start gap-6">
            <SectionCard :title="selected.name" :icon="BookOpenCheck" divided>
                <template #actions>
                    <div class="flex flex-wrap gap-1.5">
                        <StatusBadge
                            :status="selected.verification_status"
                            :label="
                                verification(selected.verification_status).label
                            "
                            :tone="
                                verification(selected.verification_status).tone
                            "
                            size="sm"
                        />
                        <StatusBadge
                            :status="selected.checksum_ok ? 'valid' : 'invalid'"
                            :label="
                                selected.checksum_ok ? 'Íntegra' : 'Alterada'
                            "
                            size="sm"
                        />
                    </div>
                </template>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-brand-soft/60 p-4">
                        <p class="text-xs text-brand-soft-foreground">
                            Jornada semanal
                        </p>
                        <p class="font-display text-2xl font-bold tabular-nums">
                            {{
                                hours(
                                    selected.parameters
                                        .weekly_ordinary_max_minutes,
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-4">
                        <p
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Sun class="icon-xs" /> Diurna
                        </p>
                        <p class="font-display text-xl font-bold tabular-nums">
                            {{
                                hours(
                                    Number(
                                        selected.parameters.daily_max_minutes
                                            .day ?? 0,
                                    ),
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-4">
                        <p
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Moon class="icon-xs" /> Nocturna
                        </p>
                        <p class="font-display text-xl font-bold tabular-nums">
                            {{
                                hours(
                                    Number(
                                        selected.parameters.daily_max_minutes
                                            .night ?? 0,
                                    ),
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-4">
                        <p
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Sunset class="icon-xs" /> Mixta
                        </p>
                        <p class="font-display text-xl font-bold tabular-nums">
                            {{
                                hours(
                                    Number(
                                        selected.parameters.daily_max_minutes
                                            .mixed ?? 0,
                                    ),
                                )
                            }}
                        </p>
                    </div>
                </div>

                <dl
                    class="mt-5 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div class="flex justify-between gap-2 border-b pb-2">
                        <dt class="text-muted-foreground">Ventana nocturna</dt>
                        <dd class="font-medium tabular-nums">
                            {{
                                selected.parameters.night_window.start ?? '—'
                            }}–{{ selected.parameters.night_window.end ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b pb-2">
                        <dt class="text-muted-foreground">Descanso mínimo</dt>
                        <dd class="font-medium">
                            {{ selected.parameters.min_break_minutes ?? '—' }}
                            min
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b pb-2">
                        <dt class="text-muted-foreground">Descanso semanal</dt>
                        <dd class="font-medium">
                            {{ selected.parameters.rest_days_per_week ?? '—' }}
                            día(s)
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b pb-2">
                        <dt class="text-muted-foreground">Prima dominical</dt>
                        <dd class="font-medium">
                            {{
                                selected.parameters
                                    .sunday_premium_min_percent ?? '—'
                            }}
                            %
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b pb-2">
                        <dt class="text-muted-foreground">Prima vacacional</dt>
                        <dd class="font-medium">
                            {{
                                selected.parameters
                                    .vacation_premium_min_percent ?? '—'
                            }}
                            %
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="selected.notes"
                    class="mt-4 rounded-xl bg-surface-2 p-3 text-xs text-muted-foreground"
                >
                    {{ selected.notes }}
                </p>
            </SectionCard>

            <SectionCard
                title="Horas extraordinarias"
                :icon="TimerReset"
                divided
            >
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs text-muted-foreground">Máx. diario</p>
                        <p class="font-semibold tabular-nums">
                            {{ hours(overtime.daily) }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs text-muted-foreground">
                            Máx. semanal
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ hours(overtime.weekly) }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs text-muted-foreground">
                            Días por semana
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ overtime.days }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs text-muted-foreground">
                            Pago dentro del límite
                        </p>
                        <p class="font-semibold tabular-nums">
                            ×{{ overtime.double }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs text-muted-foreground">
                            Pago excedente
                        </p>
                        <p class="font-semibold tabular-nums">
                            ×{{ overtime.excess }}
                        </p>
                    </div>
                </div>
            </SectionCard>

            <SectionCard
                title="Fuentes"
                :icon="ExternalLink"
                divided
                padding="none"
            >
                <ul class="divide-y">
                    <li
                        v-for="(source, i) in selected.sources"
                        :key="i"
                        class="flex items-start justify-between gap-3 px-5 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm">{{ sourceTitle(source) }}</p>
                            <p
                                v-if="sourceDate(source)"
                                class="text-xs text-muted-foreground"
                            >
                                Publicado {{ civilDate(sourceDate(source)) }}
                            </p>
                        </div>
                        <Button
                            v-if="sourceUrl(source)"
                            as="a"
                            :href="sourceUrl(source) ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Abrir fuente"
                        >
                            <ExternalLink class="icon-sm" />
                        </Button>
                    </li>
                    <li
                        v-if="selected.sources.length === 0"
                        class="px-5 py-4 text-sm text-muted-foreground"
                    >
                        Sin fuentes registradas.
                    </li>
                </ul>
            </SectionCard>

            <SectionCard
                title="Tabla de vacaciones (LFT art. 76)"
                :icon="CalendarClock"
                divided
                padding="none"
            >
                <div
                    v-for="(group, gi) in vacationGroups"
                    :key="gi"
                    class="border-b last:border-b-0"
                >
                    <p class="px-5 pt-3 text-xs text-muted-foreground">
                        {{ group[0].code }} · desde
                        {{ civilDate(String(group[0].valid_from)) }}
                    </p>
                    <div
                        class="grid grid-cols-3 gap-2 p-5 pt-2 sm:grid-cols-6 lg:grid-cols-8"
                    >
                        <div
                            v-for="row in group"
                            :key="row.from_year"
                            class="rounded-lg bg-surface-2 px-2 py-2 text-center"
                        >
                            <p class="text-[0.6875rem] text-muted-foreground">
                                {{
                                    row.to_year === null
                                        ? `${row.from_year}+`
                                        : row.from_year === row.to_year
                                          ? row.from_year
                                          : `${row.from_year}–${row.to_year}`
                                }}
                                a
                            </p>
                            <p class="font-semibold tabular-nums">
                                {{ row.days }} d
                            </p>
                        </div>
                    </div>
                </div>
            </SectionCard>
        </div>
    </div>
</template>
