<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    FileSignature,
    FileUp,
    Search,
    TriangleAlert,
    UserCheck,
    UserMinus,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import EmployeeImportController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeImportController';
import DataTable from '@/components/app/DataTable.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep, SortState } from '@/types';
import { EMPLOYEE_STATUS } from './lib';
import type { EmployeeIndexProps, EmployeeListRow } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Colaboradores', href: '/colaboradores' },
        ],
    },
});

const props = defineProps<EmployeeIndexProps>();

const { filters, activeCount, reset, processing } = useFilters(
    {
        search: props.filters.search ?? '',
        status: props.filters.status ?? null,
        branch_id: props.filters.branch_id
            ? String(props.filters.branch_id)
            : null,
        department_id: props.filters.department_id
            ? String(props.filters.department_id)
            : null,
        position_id: props.filters.position_id
            ? String(props.filters.position_id)
            : null,
        registration: props.filters.registration ?? null,
    },
    { only: ['employees', 'filters', 'stats'], defaults: { search: '' } },
);

const sort = computed<SortState | null>(() =>
    props.filters.sort
        ? {
              key: props.filters.sort,
              direction: props.filters.direction ?? 'asc',
          }
        : null,
);

const columns: DataTableColumn<EmployeeListRow>[] = [
    { key: 'name', label: 'Colaborador', sortable: true, mobile: 'title' },
    { key: 'employee_number', label: 'Número', sortable: true, class: 'w-28' },
    { key: 'branch', label: 'Sucursal', hideBelow: 'md' },
    { key: 'position', label: 'Puesto', hideBelow: 'lg' },
    { key: 'schedule', label: 'Horario', hideBelow: 'xl' },
    { key: 'starts_on', label: 'Ingreso', hideBelow: 'lg', class: 'w-32' },
    { key: 'app', label: 'App', hideBelow: 'md', class: 'w-28' },
    { key: 'agreement', label: 'Convenio de registro', hideBelow: 'md', class: 'w-36' },
    { key: 'status', label: 'Estado', sortable: true, class: 'w-32' },
];

const statusOptions = [
    { value: 'active', label: 'Activos' },
    { value: 'terminated', label: 'Bajas' },
    { value: 'inactive', label: 'Inactivos' },
];

const registrationOptions = [
    { value: 'complete', label: 'Completo' },
    { value: 'incomplete', label: 'Incompleto' },
    { value: 'no_app', label: 'Sin app' },
    { value: 'no_agreement', label: 'Sin convenio' },
];

const pagination = computed(() => toPaginationMeta(props.employees));
const isFiltered = computed(() => activeCount.value > 0);

const help: HelpStep[] = [
    {
        title: 'Alta en tres pasos',
        text: 'Datos personales, relación laboral y asignación a sucursal y horario.',
        icon: UserPlus,
    },
    {
        title: 'Expediente con historial',
        text: 'Cada cambio de sucursal, puesto u horario crea una vigencia nueva; las pasadas no se modifican.',
        icon: Users,
    },
    {
        title: 'Bajas y reingresos',
        text: 'La baja cierra la relación laboral. Un reingreso abre una nueva y conserva la anterior.',
        icon: UserMinus,
    },
    {
        title: 'Alta de registro',
        text: 'Pasa a «Activo» cuando tiene la app vinculada y el convenio de registro firmado (en la app o adjunto en papel).',
        icon: FileSignature,
    },
    {
        title: 'Importación',
        text: 'Carga un CSV o sincroniza con People; los duplicados se reportan sin sobrescribir.',
        icon: FileUp,
    },
];
</script>

<template>
    <PageHeader
        title="Colaboradores"
        description="Expedientes, relación laboral y asignaciones."
        :icon="Users"
        :help="help"
        help-title="Colaboradores"
    >
        <template #actions>
            <Button v-if="can.import" variant="outline" as-child>
                <Link :href="EmployeeImportController.create.url()">
                    <FileUp aria-hidden="true" />
                    Importar
                </Link>
            </Button>
            <Button v-if="can.create" as-child>
                <Link :href="EmployeeController.create.url()">
                    <UserPlus aria-hidden="true" />
                    Nuevo colaborador
                </Link>
            </Button>
        </template>
    </PageHeader>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile
            label="Activos"
            :value="stats.active"
            :icon="UserCheck"
            tone="success"
        />
        <KpiTile
            label="Altas del mes"
            :value="stats.hired_this_month"
            :icon="UserPlus"
            tone="brand"
        />
        <KpiTile
            label="Alta de registro incompleta"
            :value="stats.registration_incomplete"
            :icon="TriangleAlert"
            :tone="stats.registration_incomplete > 0 ? 'warning' : 'neutral'"
            hint="Sin app vinculada o sin convenio firmado"
        />
        <KpiTile
            label="Bajas"
            :value="stats.terminated"
            :icon="UserMinus"
            tone="neutral"
        />
    </div>

    <DataTable
        :rows="employees.data"
        :columns="columns"
        :pagination="pagination"
        :sort="sort"
        :loading="processing"
        :only="['employees', 'filters', 'stats']"
        :row-href="(row) => EmployeeController.show.url(row.id)"
        caption="Listado de colaboradores"
        :empty-icon="isFiltered ? Search : Users"
        :empty-title="
            isFiltered ? 'Sin coincidencias' : 'Aún no hay colaboradores'
        "
        :empty-description="
            isFiltered
                ? 'Ajusta o limpia los filtros.'
                : 'Da de alta al primero o importa tu plantilla.'
        "
    >
        <template #toolbar>
            <FilterBar :active-count="activeCount" @reset="reset">
                <template #search>
                    <SearchInput
                        v-model="filters.search"
                        placeholder="Nombre, número o correo"
                        :loading="processing"
                    />
                </template>
                <SelectField
                    v-model="filters.status"
                    :options="statusOptions"
                    placeholder="Estado"
                    prefix="Estado:"
                    size="sm"
                    clearable
                />
                <SelectField
                    v-if="options.branches.length"
                    v-model="filters.branch_id"
                    :options="options.branches"
                    placeholder="Sucursal"
                    prefix="Sucursal:"
                    size="sm"
                    clearable
                />
                <SelectField
                    v-if="options.departments.length"
                    v-model="filters.department_id"
                    :options="options.departments"
                    placeholder="Departamento"
                    prefix="Depto.:"
                    size="sm"
                    clearable
                />
                <SelectField
                    v-if="options.positions.length"
                    v-model="filters.position_id"
                    :options="options.positions"
                    placeholder="Puesto"
                    prefix="Puesto:"
                    size="sm"
                    clearable
                />
                <SelectField
                    v-model="filters.registration"
                    :options="registrationOptions"
                    placeholder="Registro electrónico"
                    prefix="Registro:"
                    size="sm"
                    clearable
                />
            </FilterBar>
        </template>

        <template #cell-name="{ row }">
            <UserAvatar
                :name="row.full_name"
                :subtitle="row.email ?? row.department ?? null"
                size="sm"
                show-name
            />
        </template>
        <template #cell-employee_number="{ row }">
            <span class="font-mono text-xs">{{ row.employee_number }}</span>
        </template>
        <template #cell-starts_on="{ row }">
            <span class="numeric">{{
                row.starts_on ? formatDate(row.starts_on, 'date-short') : '—'
            }}</span>
        </template>
        <template #cell-app="{ row }">
            <StatusBadge
                v-if="row.registration"
                :status="row.registration.app ? 'linked' : 'pending'"
                :label="row.registration.app ? 'Vinculada' : 'Pendiente'"
                :tone="row.registration.app ? 'success' : 'danger'"
                size="sm"
            />
        </template>
        <template #cell-agreement="{ row }">
            <StatusBadge
                v-if="row.registration"
                :status="row.registration.agreement ? 'signed' : 'pending'"
                :label="row.registration.agreement ? 'Firmado' : 'Pendiente'"
                :tone="row.registration.agreement ? 'success' : 'danger'"
                size="sm"
            />
        </template>
        <template #cell-status="{ row }">
            <StatusBadge
                :status="row.display_status"
                :label="EMPLOYEE_STATUS[row.display_status]?.label"
                :tone="EMPLOYEE_STATUS[row.display_status]?.tone"
                size="sm"
            />
        </template>

        <template v-if="!isFiltered && (can.create || can.import)" #empty>
            <EmptyState
                :icon="Users"
                tone="brand"
                title="Aún no hay colaboradores"
                description="Da de alta al primero o importa tu plantilla."
            >
                <template #actions>
                    <Button v-if="can.import" variant="outline" as-child>
                        <Link :href="EmployeeImportController.create.url()">
                            <FileUp aria-hidden="true" />
                            Importar CSV
                        </Link>
                    </Button>
                    <Button v-if="can.create" as-child>
                        <Link :href="EmployeeController.create.url()">
                            <UserPlus aria-hidden="true" />
                            Nuevo colaborador
                        </Link>
                    </Button>
                </template>
            </EmptyState>
        </template>
    </DataTable>
</template>
