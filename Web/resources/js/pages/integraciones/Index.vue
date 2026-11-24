<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CloudOff,
    FlaskConical,
    History,
    Link2,
    RefreshCw,
    UserMinus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import IntegrationController from '@/actions/App/Http/Controllers/Web/Admin/IntegrationController';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { HelpStep, Tone } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Integraciones', href: '/integraciones' },
        ],
    },
});

type Issue = {
    entity?: string;
    external_id?: string | null;
    type: string;
    message: string;
    line?: number | null;
    name?: string | null;
};

type Run = {
    id: number;
    source: string;
    kind: string;
    status: string;
    stats: Record<string, number | boolean>;
    issues: Issue[];
    issues_count: number;
    started_by: string | null;
    started_at: string;
    finished_at: string | null;
};

const props = defineProps<{
    people: { configured: boolean; last_success_at: string | null };
    runs: Run[];
    can: { sync: boolean };
}>();

const busy = ref(false);
const expanded = ref<number | null>(null);

const SOURCES: Record<string, string> = {
    people_structure: 'Estructura y bajas',
    people_api: 'Colaboradores (API)',
    csv: 'Colaboradores (CSV)',
};

const STATUS: Record<string, { label: string; tone: Tone }> = {
    completed: { label: 'Completa', tone: 'success' },
    partial: { label: 'Con errores', tone: 'warning' },
    failed: { label: 'Fallida', tone: 'danger' },
    simulated: { label: 'Simulación', tone: 'info' },
};

const dateFmt = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const fmt = (iso: string | null): string =>
    iso ? dateFmt.format(new Date(iso)) : '—';

const lastSuccess = computed(() => fmt(props.people.last_success_at));

function sync(dryRun: boolean): void {
    busy.value = true;
    router.post(
        IntegrationController.sync.url(),
        { dry_run: dryRun },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function summary(run: Run): string {
    const s = run.stats;
    const n = (k: string): number => Number(s[k] ?? 0);

    return [
        n('created') ? `${n('created')} nuevos` : null,
        n('updated') ? `${n('updated')} actualizados` : null,
        n('terminated') ? `${n('terminated')} bajas` : null,
        n('conflicts') ? `${n('conflicts')} conflictos` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

const help: HelpStep[] = [
    {
        title: 'Origen de datos',
        text: 'People es el origen de altas, bajas, departamentos, puestos y sucursales.',
        icon: Link2,
    },
    {
        title: 'Sin duplicados',
        text: 'Cada registro se vincula por su identificador de People; repetir la sincronización no duplica nada.',
        icon: RefreshCw,
    },
    {
        title: 'Bajas',
        text: 'Una baja en People desactiva el acceso a la app el mismo día.',
        icon: UserMinus,
    },
    {
        title: 'Datos incompletos',
        text: 'No se crean: aparecen en el detalle para corregirlos en People.',
        icon: AlertTriangle,
    },
    {
        title: 'Simular',
        text: 'Muestra qué cambiaría sin guardar nada.',
        icon: FlaskConical,
    },
];
</script>

<template>
    <PageHeader
        title="Integraciones"
        description="Sincronización con Mr. Lana People."
        :icon="Link2"
        :help="help"
        help-title="Integración con People"
    >
        <template v-if="can.sync && people.configured" #actions>
            <Button variant="outline" :disabled="busy" @click="sync(true)">
                <FlaskConical />
                Simular
            </Button>
            <Button :disabled="busy" @click="sync(false)">
                <RefreshCw :class="busy ? 'animate-spin' : ''" />
                Sincronizar ahora
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-5">
        <SectionCard title="Mr. Lana People" :icon="Link2">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-3 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-muted-foreground">Conexión</span>
                    <StatusBadge
                        v-if="people.configured"
                        label="Configurada"
                        tone="success"
                    />
                    <StatusBadge v-else label="Sin configurar" tone="warning" />
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-muted-foreground">Última sincronización</span>
                    <span class="numeric font-medium">{{ lastSuccess }}</span>
                </div>
            </div>
        </SectionCard>

        <SectionCard title="Historial" :icon="History" padding="none">
            <EmptyState
                v-if="runs.length === 0"
                :icon="people.configured ? History : CloudOff"
                title="Sin sincronizaciones"
                :description="
                    people.configured
                        ? 'Ejecuta la primera sincronización.'
                        : 'Configura PEOPLE_API_URL y PEOPLE_API_TOKEN.'
                "
                class="py-12"
            />
            <ul v-else class="divide-y divide-border">
                <li v-for="run in runs" :key="run.id" class="px-5 py-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold">
                                {{ SOURCES[run.source] ?? run.source }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ fmt(run.started_at) }}
                                <template v-if="run.started_by">
                                    · {{ run.started_by }}</template
                                >
                                <template v-if="summary(run)">
                                    · {{ summary(run) }}</template
                                >
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge
                                size="sm"
                                :label="STATUS[run.status]?.label ?? run.status"
                                :tone="STATUS[run.status]?.tone ?? 'neutral'"
                            />
                            <Button
                                v-if="run.issues_count > 0"
                                variant="ghost"
                                size="sm"
                                @click="
                                    expanded =
                                        expanded === run.id ? null : run.id
                                "
                            >
                                <AlertTriangle />
                                {{ run.issues_count }}
                            </Button>
                        </div>
                    </div>
                    <ul
                        v-if="expanded === run.id"
                        class="mt-3 space-y-1 rounded-lg bg-surface-2 p-3 text-xs"
                    >
                        <li
                            v-for="(issue, i) in run.issues"
                            :key="i"
                            class="flex gap-2"
                        >
                            <span class="font-mono text-muted-foreground">{{
                                issue.external_id ??
                                issue.line ??
                                issue.entity ??
                                '—'
                            }}</span>
                            <span>{{ issue.message }}</span>
                        </li>
                    </ul>
                </li>
            </ul>
        </SectionCard>
    </div>
</template>
