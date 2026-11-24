<script setup lang="ts">
import {
    CalendarClock,
    FilePen,
    FileSearch,
    Fingerprint,
    Lock,
    Paperclip,
    Plus,
    ShieldCheck,
    UserCheck,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import RectificationController from '@/actions/App/Http/Controllers/Web/Operacion/RectificationController';
import {
    ComboboxField,
    DataTable,
    DateRangePicker,
    DateTimeDisplay,
    FilterBar,
    PageHeader,
    SearchInput,
    SelectField,
    StatusBadge,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import { currentQuery, toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep } from '@/types';
import type {
    RectificationIndexProps,
    RectificationRow,
} from '@/types/operacion';
import EmployeeCell from './components/EmployeeCell.vue';
import FlowSteps from './components/FlowSteps.vue';
import RectificationFormSheet from './components/RectificationFormSheet.vue';
import { sortFromQuery, todayIn } from './components/shared';
import StageTabs from './components/StageTabs.vue';
import {
    SIGNATURE_STATUS,
    rejectionLabel,
    stageTone,
} from './components/stages';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Rectificaciones', href: '/rectificaciones' },
        ],
    },
});

const props = defineProps<RectificationIndexProps>();

const { filters, activeCount, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        employee_id: props.filters.employee_id,
        from: props.filters.from,
        to: props.filters.to,
    },
    { keepQuery: ['tab', 'sort', 'direction', 'per_page'] },
);

const columns: DataTableColumn<RectificationRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'date', label: 'Fecha', sortable: true, class: 'w-28' },
    { key: 'kind', label: 'Tipo', hideBelow: 'lg' },
    { key: 'original', label: 'Registro original', hideBelow: 'md' },
    { key: 'proposal', label: 'Propuesta' },
    {
        key: 'attachments',
        label: 'Evidencia',
        align: 'center',
        hideBelow: 'sm',
    },
    { key: 'signature', label: 'Firma colab.', hideBelow: 'md' },
    { key: 'stage', label: 'Estado' },
];

const sheetOpen = ref(false);
const prefill = ref<{ employee: string | null; date: string | null }>({
    employee: null,
    date: null,
});
const today = computed(() => todayIn(props.timezone));

onMounted(() => {
    const q = currentQuery();
    if (q.nuevo === '1' && props.can.create) {
        prefill.value = {
            employee: typeof q.employee_id === 'string' ? q.employee_id : null,
            date: typeof q.date === 'string' ? q.date : null,
        };
        sheetOpen.value = true;
    }
});

const empty = computed(() =>
    ['por_autorizar', 'por_firmar', 'por_validar'].includes(props.tab)
        ? {
              title: 'Bandeja al día',
              text: 'No hay solicitudes en esta etapa.',
          }
        : {
              title: 'Sin solicitudes',
              text: 'Ajusta los filtros para ver otros resultados.',
          },
);

const help: HelpStep[] = [
    {
        title: 'Solicitud con evidencia',
        text: 'Sin foto, documento o constancia no se registra.',
        icon: FileSearch,
    },
    {
        title: 'Autoriza el jefe inmediato',
        text: 'Nadie autoriza su propio registro ni su propia captura.',
        icon: UserCheck,
    },
    {
        title: 'Firma el colaborador',
        text: `En la app, con NIP o biometría. Plazo: ${props.settings.business_days} días hábiles; vencido, se rechaza y el día queda como omisión.`,
        icon: Fingerprint,
    },
    {
        title: 'Valida RH y se aplica',
        text: 'Se crea un ajuste encadenado; el marcaje original nunca cambia.',
        icon: ShieldCheck,
    },
    {
        title: 'Extemporáneas',
        text: 'Después del cierre de nómina sólo RH con permiso las aplica.',
        icon: CalendarClock,
    },
];
</script>

<template>
    <PageHeader
        title="Rectificaciones"
        description="Correcciones con evidencia y firma del colaborador. El original nunca se modifica."
        :icon="FilePen"
        :help="help"
    >
        <template #actions>
            <Button v-if="can.create" @click="sheetOpen = true">
                <Plus aria-hidden="true" />
                Nueva solicitud
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <FlowSteps />
        <StageTabs :tab="tab" :counts="counts" />

        <DataTable
            :rows="requests.data"
            :columns="columns"
            :pagination="toPaginationMeta(requests)"
            :sort="sortFromQuery()"
            :row-href="(row) => RectificationController.show.url(row.id)"
            caption="Solicitudes de rectificación"
            :empty-icon="FilePen"
            :empty-title="empty.title"
            :empty-description="empty.text"
        >
            <template #toolbar>
                <FilterBar :active-count="activeCount" @reset="reset">
                    <template #search>
                        <SearchInput
                            v-model="filters.search"
                            placeholder="Colaborador o número…"
                        />
                    </template>
                    <SelectField
                        v-model="filters.branch_id"
                        :options="options.branches"
                        prefix="Sucursal:"
                        placeholder="Todas"
                        clearable
                        size="sm"
                        class="w-52"
                    />
                    <SelectField
                        v-model="filters.department_id"
                        :options="options.departments"
                        prefix="Depto.:"
                        placeholder="Todos"
                        clearable
                        size="sm"
                        class="w-48"
                    />
                    <ComboboxField
                        v-model="filters.employee_id"
                        :options="options.employees"
                        placeholder="Colaborador"
                        clearable
                        class="w-56"
                    />
                    <DateRangePicker
                        v-model:start="filters.from"
                        v-model:end="filters.to"
                        placeholder="Periodo"
                        class="w-60"
                    />
                </FilterBar>
            </template>

            <template #cell-employee="{ row }">
                <EmployeeCell :employee="row.employee" />
            </template>
            <template #cell-date="{ row }">
                <span class="numeric text-sm font-medium">{{
                    formatDate(row.date)
                }}</span>
            </template>
            <template #cell-kind="{ row }">
                <span class="text-sm">{{ row.kind_label }}</span>
            </template>
            <template #cell-original="{ row }">
                <span class="text-sm text-muted-foreground">{{
                    row.original_summary
                }}</span>
            </template>
            <template #cell-proposal="{ row }">
                <span class="text-sm font-semibold">{{
                    row.proposal_summary
                }}</span>
            </template>
            <template #cell-attachments="{ row }">
                <span
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${row.attachments_count} evidencias`"
                >
                    <Paperclip class="size-3.5" aria-hidden="true" />{{
                        row.attachments_count
                    }}
                </span>
            </template>
            <template #cell-signature="{ row }">
                <div class="grid gap-0.5">
                    <StatusBadge
                        :status="row.signature_status"
                        :label="SIGNATURE_STATUS[row.signature_status].label"
                        :tone="SIGNATURE_STATUS[row.signature_status].tone"
                        size="sm"
                    />
                    <span
                        v-if="
                            row.stage === 'por_firmar' && row.signature_due_at
                        "
                        class="text-[0.6875rem] text-muted-foreground"
                    >
                        Vence
                        <DateTimeDisplay
                            :value="row.signature_due_at"
                            :timezone="timezone"
                            format="datetime"
                        />
                    </span>
                </div>
            </template>
            <template #cell-stage="{ row }">
                <div class="grid justify-items-start gap-1">
                    <StatusBadge
                        :status="row.stage"
                        :label="row.stage_label"
                        :tone="stageTone(row.stage)"
                        size="sm"
                    />
                    <span
                        v-if="row.is_late"
                        class="inline-flex items-center gap-1 text-[0.6875rem] font-semibold text-warning-soft-foreground"
                    >
                        <Lock class="size-3" aria-hidden="true" />
                        Extemporánea
                    </span>
                    <span
                        v-if="row.stage === 'rechazada'"
                        class="text-[0.6875rem] text-muted-foreground"
                        >{{ rejectionLabel(row.rejection_stage) }}</span
                    >
                </div>
            </template>
        </DataTable>

        <p class="text-xs text-muted-foreground">
            Plazo para firmar: {{ settings.business_days }} días hábiles. Sin
            firma del colaborador la rectificación
            <strong class="font-semibold text-foreground">no se aplica</strong>
            y el día queda como omisión.
        </p>
    </div>

    <RectificationFormSheet
        v-if="can.create"
        v-model:open="sheetOpen"
        :employees="options.employees"
        :kinds="options.kinds"
        :punch-types="options.punch_types"
        :today="today"
        :initial-employee-id="prefill.employee"
        :initial-date="prefill.date"
    />
</template>
