<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CloudDownload,
    Download,
    FileSpreadsheet,
    FileUp,
    FlaskConical,
    RefreshCw,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import EmployeeImportController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeImportController';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FileDropzone from '@/components/app/FileDropzone.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { DataTableColumn, HelpStep } from '@/types';
import { CONFLICT_LABELS, RUN_STATUS } from './lib';
import type { EmployeeImportProps, ImportConflict } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Importar' },
        ],
    },
});

const props = defineProps<EmployeeImportProps>();

const form = useForm({
    file: null as File | null,
    dry_run: true,
});
const files = ref<File[]>([]);
const syncing = ref(false);
const syncDryRun = ref(true);

const errors = computed(() => form.errors as Record<string, string>);

function upload(): void {
    form.file = files.value[0] ?? null;

    if (!form.file) {
        return;
    }

    form.transform((data) => ({
        ...data,
        dry_run: data.dry_run ? 1 : 0,
    })).submit(EmployeeImportController.store(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            files.value = [];
            form.file = null;
        },
    });
}

function sync(): void {
    router.post(
        EmployeeImportController.sync.url(),
        { dry_run: syncDryRun.value ? 1 : 0 },
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => (syncing.value = false),
        },
    );
}

function openRun(id: number): void {
    router.get(
        EmployeeImportController.create.url({ query: { run: id } }),
        {},
        { preserveScroll: true, preserveState: true, only: ['run'] },
    );
}

const conflictColumns: DataTableColumn<ImportConflict>[] = [
    { key: 'line', label: 'Línea', class: 'w-20', numeric: true },
    { key: 'employee_number', label: 'Número', class: 'w-32', mobile: 'title' },
    { key: 'name', label: 'Nombre', hideBelow: 'md' },
    { key: 'type', label: 'Tipo', class: 'w-44' },
    { key: 'message', label: 'Detalle', mobile: 'full' },
];

const runStatus = (status: string) =>
    RUN_STATUS[status] ?? { label: status, tone: 'neutral' as const };

const sourceLabel = (source: string) =>
    source === 'csv' ? 'CSV' : source === 'people_api' ? 'People' : source;

const help: HelpStep[] = [
    {
        title: 'Descarga la plantilla',
        text: 'Columnas mínimas: número, nombre, apellido paterno y fecha de ingreso.',
        icon: Download,
    },
    {
        title: 'Simula primero',
        text: 'La simulación valida todo sin guardar y muestra los conflictos.',
        icon: FlaskConical,
    },
    {
        title: 'Duplicados',
        text: 'Se detectan por número de empleado e identificador externo; nunca se sobrescriben.',
        icon: TriangleAlert,
    },
    {
        title: 'People',
        text: 'Si está configurado, sincroniza datos de contacto por identificador externo.',
        icon: CloudDownload,
    },
];
</script>

<template>
    <PageHeader
        title="Importar colaboradores"
        description="CSV o sincronización con People."
        :icon="FileUp"
        :back-href="EmployeeController.index.url()"
        back-label="Colaboradores"
        :help="help"
        help-title="Importación"
    >
        <template #actions>
            <Button variant="outline" as-child>
                <a :href="EmployeeImportController.template.url()" download>
                    <Download aria-hidden="true" />
                    Plantilla CSV
                </a>
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <SectionCard title="Archivo CSV" :icon="FileSpreadsheet" divided>
            <form class="grid gap-5" novalidate @submit.prevent="upload">
                <FileDropzone
                    v-model="files"
                    accept=".csv,text/csv,text/plain"
                    :multiple="false"
                    :max-files="1"
                    :max-size-mb="5"
                    :invalid="Boolean(errors.file)"
                    title="Arrastra tu CSV aquí"
                    description="UTF-8, separado por comas o punto y coma · máx. 5 MB"
                />
                <p
                    v-if="errors.file"
                    class="text-sm text-danger-soft-foreground"
                >
                    {{ errors.file }}
                </p>

                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Label
                        for="dry-run"
                        class="flex items-center gap-3 text-sm"
                    >
                        <Switch id="dry-run" v-model="form.dry_run" />
                        <span>
                            <span class="font-semibold">Sólo simular</span>
                            <span
                                class="block text-xs font-normal text-muted-foreground"
                                >Valida sin guardar cambios.</span
                            >
                        </span>
                    </Label>
                    <Button
                        type="submit"
                        :disabled="form.processing || files.length === 0"
                    >
                        <Spinner v-if="form.processing" />
                        <FlaskConical
                            v-else-if="form.dry_run"
                            aria-hidden="true"
                        />
                        <FileUp v-else aria-hidden="true" />
                        {{ form.dry_run ? 'Simular importación' : 'Importar' }}
                    </Button>
                </div>
            </form>
        </SectionCard>

        <div class="grid h-fit gap-6">
            <SectionCard title="MR LANA People" :icon="CloudDownload" divided>
                <template v-if="people.available">
                    <Label
                        for="sync-dry"
                        class="mb-4 flex items-center gap-3 text-sm"
                    >
                        <Switch id="sync-dry" v-model="syncDryRun" />
                        Sólo simular
                    </Label>
                    <Button class="w-full" :disabled="syncing" @click="sync">
                        <Spinner v-if="syncing" />
                        <RefreshCw v-else aria-hidden="true" />
                        Sincronizar ahora
                    </Button>
                </template>
                <EmptyState
                    v-else
                    size="sm"
                    :icon="CloudDownload"
                    title="No configurado"
                    description="Requiere URL y token del servicio."
                />
            </SectionCard>

            <SectionCard title="Columnas" padding="sm" variant="muted">
                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        v-for="c in columns"
                        :key="c.key"
                        :variant="c.required ? 'default' : 'outline'"
                        class="font-mono"
                    >
                        {{ c.header }}
                    </Badge>
                </div>
            </SectionCard>
        </div>
    </div>

    <template v-if="run">
        <SectionCard divided>
            <template #header>
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground"
                    >
                        <CheckCircle2 class="size-5" aria-hidden="true" />
                    </span>
                    <div>
                        <p class="font-display text-lg font-semibold">
                            Resultado · {{ sourceLabel(run.source) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <DateTimeDisplay :value="run.started_at" />
                            <template v-if="run.started_by">
                                · {{ run.started_by }}</template
                            >
                        </p>
                    </div>
                    <StatusBadge
                        class="ml-auto"
                        :status="run.status"
                        :label="runStatus(run.status).label"
                        :tone="runStatus(run.status).tone"
                    />
                </div>
            </template>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiTile
                    label="Registros"
                    :value="run.stats.total ?? 0"
                    :icon="Users"
                    tone="neutral"
                />
                <KpiTile
                    :label="
                        run.status === 'simulated' ? 'Se crearían' : 'Creados'
                    "
                    :value="run.stats.created ?? 0"
                    tone="success"
                />
                <KpiTile
                    :label="
                        run.status === 'simulated'
                            ? 'Se actualizarían'
                            : 'Actualizados'
                    "
                    :value="run.stats.updated ?? 0"
                    tone="info"
                />
                <KpiTile
                    label="Conflictos"
                    :value="run.stats.conflicts ?? 0"
                    :icon="TriangleAlert"
                    :tone="
                        (run.stats.conflicts ?? 0) > 0 ? 'warning' : 'neutral'
                    "
                />
            </div>
        </SectionCard>

        <DataTable
            v-if="run.conflicts.length"
            :rows="run.conflicts"
            :columns="conflictColumns"
            :row-key="(row) => `${row.line}-${row.employee_number}-${row.type}`"
            :server="false"
            caption="Conflictos de la importación"
        >
            <template #cell-employee_number="{ row }">
                <span class="font-mono text-xs">{{
                    row.employee_number ?? '—'
                }}</span>
            </template>
            <template #cell-type="{ row }">
                <StatusBadge
                    :label="CONFLICT_LABELS[row.type] ?? row.type"
                    :tone="
                        row.type === 'invalid' || row.type === 'source_error'
                            ? 'danger'
                            : 'warning'
                    "
                    size="sm"
                />
            </template>
        </DataTable>
        <EmptyState
            v-else-if="run.status !== 'failed'"
            :icon="CheckCircle2"
            tone="success"
            bordered
            class="rounded-2xl"
            title="Sin conflictos"
        >
            <template v-if="run.status === 'simulated'" #actions>
                <p class="text-sm text-muted-foreground">
                    Desactiva «Sólo simular» y vuelve a cargar el archivo.
                </p>
            </template>
        </EmptyState>
    </template>

    <SectionCard
        title="Ejecuciones recientes"
        :icon="RefreshCw"
        divided
        padding="none"
    >
        <ul v-if="runs.length" class="divide-y">
            <li v-for="r in runs" :key="r.id">
                <button
                    type="button"
                    :class="
                        cn(
                            'flex w-full items-center gap-4 px-5 py-3 text-left text-sm transition-standard hover:bg-accent',
                            run?.id === r.id && 'bg-brand-soft/60',
                        )
                    "
                    @click="openRun(r.id)"
                >
                    <Badge variant="outline">{{ sourceLabel(r.source) }}</Badge>
                    <span class="min-w-0 flex-1">
                        <DateTimeDisplay
                            :value="r.started_at"
                            :tooltip="false"
                        />
                        <span v-if="r.started_by" class="text-muted-foreground">
                            · {{ r.started_by }}</span
                        >
                    </span>
                    <span
                        class="hidden numeric text-xs text-muted-foreground sm:inline"
                    >
                        +{{ r.stats.created ?? 0 }} · ~{{
                            r.stats.updated ?? 0
                        }}
                        · !{{ r.stats.conflicts ?? 0 }}
                    </span>
                    <StatusBadge
                        :status="r.status"
                        :label="runStatus(r.status).label"
                        :tone="runStatus(r.status).tone"
                        size="sm"
                    />
                </button>
            </li>
        </ul>
        <EmptyState
            v-else
            size="sm"
            :icon="FileUp"
            title="Aún no hay importaciones"
        />
    </SectionCard>

    <div class="flex justify-end">
        <Button variant="ghost" as-child>
            <Link :href="EmployeeController.index.url()"
                >Ver colaboradores</Link
            >
        </Button>
    </div>
</template>
