<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    CalendarCheck2,
    CheckCheck,
    CircleCheck,
    CircleMinus,
    Eye,
    Network,
    ShieldAlert,
    TriangleAlert,
    UserCheck,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ScheduleAssignmentController from '@/actions/App/Http/Controllers/Web/Admin/ScheduleAssignmentController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { HelpStep, SelectOption } from '@/types';
import type {
    AssignmentFilters,
    AssignmentMode,
    AssignmentPreview,
    AssignmentRow,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Horarios', href: '/horarios' },
            { title: 'Asignación masiva' },
        ],
    },
});

const props = defineProps<{
    filters: AssignmentFilters;
    preview: AssignmentPreview | null;
    options: {
        schedules: SelectOption[];
        branches: SelectOption[];
        departments: SelectOption[];
        employees: SelectOption[];
    };
    today: string;
}>();

const help: HelpStep[] = [
    {
        title: 'Elige horario y vigencia',
        text: 'La vigencia no puede ser retroactiva.',
        icon: CalendarCheck2,
    },
    {
        title: 'Selecciona a quién',
        text: 'Por sucursal, departamento o colaboradores específicos.',
        icon: Users,
    },
    {
        title: 'Revisa conflictos',
        text: 'Sólo se aplica a quienes aparecen como «Listo».',
        icon: TriangleAlert,
    },
    {
        title: 'Aplica',
        text: 'Se cierra la asignación vigente el día anterior; el historial no cambia.',
        icon: CheckCheck,
    },
];

const form = useForm({
    schedule_id: props.filters.schedule_id,
    effective_from: props.filters.effective_from ?? props.today,
    mode: props.filters.mode as AssignmentMode,
    branch_ids: [...props.filters.branch_ids],
    department_ids: [...props.filters.department_ids],
    employee_ids: [...props.filters.employee_ids],
    reason: '',
});

const loadingPreview = ref(false);

const targetCount = computed(() => {
    switch (form.mode) {
        case 'department':
            return form.department_ids.length;
        case 'selection':
            return form.employee_ids.length;
        default:
            return form.branch_ids.length;
    }
});

const canPreview = computed(
    () => !!form.schedule_id && !!form.effective_from && targetCount.value > 0,
);

function targetQuery(): Record<string, unknown> {
    return {
        schedule_id: form.schedule_id,
        effective_from: form.effective_from,
        mode: form.mode,
        branch_ids: form.mode === 'branch' ? form.branch_ids : [],
        department_ids: form.mode === 'department' ? form.department_ids : [],
        employee_ids: form.mode === 'selection' ? form.employee_ids : [],
    };
}

function loadPreview(): void {
    if (!canPreview.value) {
        return;
    }

    router.get(
        ScheduleAssignmentController.index.url(),
        targetQuery() as never,
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['preview', 'filters'],
            onStart: () => (loadingPreview.value = true),
            onFinish: () => (loadingPreview.value = false),
        },
    );
}

const previewStale = computed(() => {
    if (!props.preview) {
        return false;
    }

    return (
        String(props.preview.schedule.id) !== form.schedule_id ||
        props.preview.effective_from !== form.effective_from ||
        JSON.stringify(props.filters.branch_ids) !==
            JSON.stringify(form.mode === 'branch' ? form.branch_ids : []) ||
        JSON.stringify(props.filters.department_ids) !==
            JSON.stringify(
                form.mode === 'department' ? form.department_ids : [],
            ) ||
        JSON.stringify(props.filters.employee_ids) !==
            JSON.stringify(
                form.mode === 'selection' ? form.employee_ids : [],
            ) ||
        props.filters.mode !== form.mode
    );
});

function apply(): void {
    form.transform(() => ({
        ...targetQuery(),
        reason: form.reason || null,
    })).submit(ScheduleAssignmentController.store(), {
        preserveScroll: true,
        onSuccess: () => loadPreview(),
    });
}

const rowFilter = ref<'all' | 'ready' | 'conflict' | 'unchanged'>('all');
const visibleRows = computed<AssignmentRow[]>(() =>
    (props.preview?.rows ?? []).filter(
        (r) => rowFilter.value === 'all' || r.status === rowFilter.value,
    ),
);
const conflicts = computed(
    () =>
        props.preview?.rows.filter((r) => r.status === 'conflict').length ?? 0,
);
const unchanged = computed(
    () =>
        props.preview?.rows.filter((r) => r.status === 'unchanged').length ?? 0,
);

function onMode(value: string | number): void {
    form.mode = String(value) as AssignmentMode;
}

function onRowFilter(value: unknown): void {
    if (typeof value === 'string' && value) {
        rowFilter.value = value as typeof rowFilter.value;
    }
}

const STATUS = {
    ready: {
        label: 'Listo',
        icon: CircleCheck,
        class: 'bg-success-soft text-success-soft-foreground border-success/20',
    },
    conflict: {
        label: 'Conflicto',
        icon: ShieldAlert,
        class: 'bg-danger-soft text-danger-soft-foreground border-danger/20',
    },
    unchanged: {
        label: 'Sin cambio',
        icon: CircleMinus,
        class: 'bg-neutral-soft text-neutral-soft-foreground border-border',
    },
} as const;
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Asignación masiva"
            description="Asigna un horario con vigencia a varios colaboradores."
            :icon="Users"
            :help="help"
            back-href="/horarios"
            back-label="Horarios"
        />

        <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
            <!-- Paso 1–2: configuración -->
            <SectionCard
                title="Configuración"
                divided
                class="xl:sticky xl:top-20 xl:self-start"
            >
                <div class="space-y-5">
                    <FormField
                        label="Horario"
                        :error="form.errors.schedule_id"
                        required
                        v-slot="{ id }"
                    >
                        <ComboboxField
                            :id="id"
                            v-model="form.schedule_id"
                            :options="options.schedules"
                            placeholder="Selecciona un horario"
                            search-placeholder="Buscar horario…"
                            :invalid="!!form.errors.schedule_id"
                        />
                    </FormField>

                    <FormField
                        label="Vigente a partir de"
                        :error="form.errors.effective_from"
                        required
                        v-slot="{ id }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.effective_from"
                            :min="today"
                            :invalid="!!form.errors.effective_from"
                        />
                    </FormField>

                    <div class="space-y-3">
                        <p class="text-sm font-medium">Aplicar a</p>
                        <Tabs
                            :model-value="form.mode"
                            @update:model-value="onMode"
                        >
                            <TabsList class="grid w-full grid-cols-3">
                                <TabsTrigger value="branch"
                                    ><Building2
                                        aria-hidden="true"
                                    />Sucursal</TabsTrigger
                                >
                                <TabsTrigger value="department"
                                    ><Network
                                        aria-hidden="true"
                                    />Área</TabsTrigger
                                >
                                <TabsTrigger value="selection"
                                    ><UserCheck
                                        aria-hidden="true"
                                    />Personas</TabsTrigger
                                >
                            </TabsList>
                        </Tabs>

                        <ComboboxField
                            v-if="form.mode === 'branch'"
                            v-model="form.branch_ids"
                            multiple
                            :options="options.branches"
                            placeholder="Sucursales"
                            search-placeholder="Buscar sucursal…"
                            clearable
                        />
                        <ComboboxField
                            v-else-if="form.mode === 'department'"
                            v-model="form.department_ids"
                            multiple
                            :options="options.departments"
                            placeholder="Departamentos"
                            search-placeholder="Buscar departamento…"
                            clearable
                        />
                        <ComboboxField
                            v-else
                            v-model="form.employee_ids"
                            multiple
                            :options="options.employees"
                            placeholder="Colaboradores"
                            search-placeholder="Nombre o número…"
                            clearable
                        />
                    </div>

                    <Button
                        class="w-full"
                        :disabled="!canPreview || loadingPreview"
                        @click="loadPreview"
                    >
                        <Spinner v-if="loadingPreview" class="size-4" />
                        <Eye v-else aria-hidden="true" />
                        Vista previa
                    </Button>
                </div>
            </SectionCard>

            <!-- Paso 3: vista previa -->
            <div class="min-w-0 space-y-4">
                <EmptyState
                    v-if="!preview"
                    :icon="Eye"
                    tone="primary"
                    bordered
                    class="rounded-2xl py-16"
                    title="Configura y genera la vista previa"
                    description="Verás a quién se aplicará y los conflictos antes de confirmar."
                />

                <template v-else>
                    <div
                        v-if="previewStale"
                        class="flex items-center gap-3 rounded-xl border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning-soft-foreground"
                    >
                        <TriangleAlert
                            class="icon-sm shrink-0"
                            aria-hidden="true"
                        />
                        <span class="flex-1"
                            >Cambiaste la configuración. Actualiza la vista
                            previa.</span
                        >
                        <Button size="sm" variant="outline" @click="loadPreview"
                            >Actualizar</Button
                        >
                    </div>

                    <div
                        v-if="preview.legal_error"
                        role="alert"
                        class="space-y-1 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger-soft-foreground"
                    >
                        <p
                            v-for="(m, i) in preview.legal_error"
                            :key="i"
                            class="flex gap-2"
                        >
                            <ShieldAlert
                                class="mt-0.5 icon-sm shrink-0"
                                aria-hidden="true"
                            />
                            {{ m }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <KpiTile
                            label="Listos"
                            :value="preview.ready"
                            :icon="CircleCheck"
                            tone="success"
                            :hint="`de ${preview.total}`"
                        />
                        <KpiTile
                            label="Conflictos"
                            :value="conflicts"
                            :icon="ShieldAlert"
                            tone="danger"
                        />
                        <KpiTile
                            label="Sin cambio"
                            :value="unchanged"
                            :icon="CircleMinus"
                            tone="neutral"
                        />
                    </div>

                    <SectionCard padding="none">
                        <template #header>
                            <div
                                class="flex w-full flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <h2 class="font-display text-lg font-bold">
                                        {{ preview.schedule.name }}
                                    </h2>
                                    <p class="text-xs text-muted-foreground">
                                        desde
                                        {{
                                            formatDate(
                                                preview.effective_from,
                                                'date',
                                            )
                                        }}
                                    </p>
                                </div>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    size="sm"
                                    :model-value="rowFilter"
                                    @update:model-value="onRowFilter"
                                >
                                    <ToggleGroupItem value="all" class="px-3"
                                        >Todos</ToggleGroupItem
                                    >
                                    <ToggleGroupItem value="ready" class="px-3"
                                        >Listos</ToggleGroupItem
                                    >
                                    <ToggleGroupItem
                                        value="conflict"
                                        class="px-3"
                                        >Conflictos</ToggleGroupItem
                                    >
                                    <ToggleGroupItem
                                        value="unchanged"
                                        class="px-3"
                                        >Sin cambio</ToggleGroupItem
                                    >
                                </ToggleGroup>
                            </div>
                        </template>

                        <p
                            v-if="visibleRows.length === 0"
                            class="px-5 py-10 text-center text-sm text-muted-foreground"
                        >
                            {{
                                preview.total === 0
                                    ? 'Nadie coincide con la selección.'
                                    : 'Sin registros en este filtro.'
                            }}
                        </p>
                        <ul
                            v-else
                            class="max-h-[32rem] divide-y overflow-y-auto"
                        >
                            <li
                                v-for="row in visibleRows"
                                :key="row.employee_id"
                                class="flex items-center gap-3 px-5 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold">
                                        {{ row.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        <span class="font-mono">{{
                                            row.employee_number
                                        }}</span>
                                        · actual:
                                        {{
                                            row.current_schedule ??
                                            'sin horario'
                                        }}
                                    </p>
                                    <p
                                        v-if="row.message"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ row.message }}
                                    </p>
                                </div>
                                <span
                                    :class="
                                        cn(
                                            'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium',
                                            STATUS[row.status].class,
                                        )
                                    "
                                >
                                    <component
                                        :is="STATUS[row.status].icon"
                                        class="icon-xs"
                                        aria-hidden="true"
                                    />
                                    {{ STATUS[row.status].label }}
                                </span>
                            </li>
                        </ul>
                    </SectionCard>

                    <SectionCard title="Confirmar" padding="sm">
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-end"
                        >
                            <FormField
                                label="Motivo"
                                hint="Opcional"
                                :error="form.errors.reason"
                                class="flex-1"
                                v-slot="{ id }"
                            >
                                <Input
                                    :id="id"
                                    v-model="form.reason"
                                    placeholder="Cambio de temporada"
                                    maxlength="255"
                                />
                            </FormField>
                            <Button
                                :disabled="
                                    preview.ready === 0 ||
                                    !!preview.legal_error ||
                                    previewStale ||
                                    form.processing
                                "
                                @click="apply"
                            >
                                <Spinner
                                    v-if="form.processing"
                                    class="size-4"
                                />
                                <CheckCheck v-else aria-hidden="true" />
                                Aplicar a {{ preview.ready }}
                            </Button>
                        </div>
                    </SectionCard>
                </template>
            </div>
        </div>
    </div>
</template>
