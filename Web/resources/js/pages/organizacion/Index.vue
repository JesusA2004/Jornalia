<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    Briefcase,
    Building2,
    Factory,
    FolderTree,
    Landmark,
    MapPin,
    MoreHorizontal,
    Pencil,
    Plus,
    Radar,
    Store,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import OrganizationStructureController from '@/actions/App/Http/Controllers/Web/Admin/OrganizationStructureController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { DataTableColumn, HelpStep, SelectOption } from '@/types';
import StructureSheet from './components/StructureSheet.vue';
import type {
    FieldDef,
    FormValue,
    OrganizationPageProps,
    StructureEntity,
} from './types';
import { formatCoords, osmUrl } from './utils';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Organización', href: '/organizacion' },
        ],
    },
});

const props = defineProps<OrganizationPageProps>();

type Row = { id: number; active: boolean; deleted: boolean } & Record<
    string,
    unknown
>;

type Extra = {
    key: string;
    label: string;
    value: (row: Row) => string | number | null;
    hideBelow?: 'sm' | 'md' | 'lg' | 'xl';
    numeric?: boolean;
};

type TabConfig = {
    key: string;
    entity: StructureEntity;
    label: string;
    singular: string;
    icon: LucideIcon;
    rows: Row[];
    primary: (row: Row) => string;
    secondary: (row: Row) => string | null;
    extras: Extra[];
    fields: FieldDef[];
    defaults: Record<string, FormValue>;
    map?: (row: Row) => string | null;
};

const opt = (rows: Row[], label: string, desc?: string): SelectOption[] =>
    rows
        .filter((r) => !r.deleted)
        .map((r) => ({
            value: String(r.id),
            label: String(r[label] ?? ''),
            description: desc && r[desc] ? String(r[desc]) : undefined,
        }));

const asRows = (rows: unknown[]): Row[] => rows as Row[];
const str = (v: unknown): string | null =>
    v === null || v === undefined || v === '' ? null : String(v);

const tabs = computed<TabConfig[]>(() => {
    const legalOptions = opt(asRows(props.legal_entities), 'legal_name', 'rfc');
    const centerOptions = opt(asRows(props.work_centers), 'name', 'code');
    const branchOptions = opt(asRows(props.branches), 'name', 'code');
    const departmentOptions = opt(asRows(props.departments), 'name', 'code');
    const code: FieldDef = {
        key: 'code',
        label: 'Clave',
        type: 'text',
        required: true,
        uppercase: true,
        placeholder: 'MTY-01',
    };
    const active: FieldDef = { key: 'active', label: 'Activo', type: 'switch' };

    const list: TabConfig[] = [
        {
            key: 'legal-entities',
            entity: 'razones-sociales',
            label: 'Razones sociales',
            singular: 'razón social',
            icon: Landmark,
            rows: asRows(props.legal_entities),
            primary: (r) => String(r.legal_name),
            secondary: (r) => str(r.rfc),
            extras: [
                {
                    key: 'employer_registration',
                    label: 'Registro patronal',
                    value: (r) => str(r.employer_registration),
                    hideBelow: 'md',
                },
                {
                    key: 'work_centers_count',
                    label: 'Centros',
                    value: (r) => Number(r.work_centers_count),
                    numeric: true,
                },
            ],
            fields: [
                {
                    key: 'legal_name',
                    label: 'Razón social',
                    type: 'text',
                    required: true,
                    full: true,
                    placeholder: 'Comercializadora del Norte, S.A. de C.V.',
                },
                {
                    key: 'rfc',
                    label: 'RFC',
                    type: 'text',
                    uppercase: true,
                    placeholder: 'CNO010101AB1',
                },
                {
                    key: 'employer_registration',
                    label: 'Registro patronal IMSS',
                    type: 'text',
                    uppercase: true,
                },
                {
                    key: 'fiscal_address',
                    label: 'Domicilio fiscal',
                    type: 'textarea',
                },
                active,
            ],
            defaults: {
                legal_name: null,
                rfc: null,
                employer_registration: null,
                fiscal_address: null,
                active: true,
            },
        },
        {
            key: 'work-centers',
            entity: 'centros-trabajo',
            label: 'Centros de trabajo',
            singular: 'centro de trabajo',
            icon: Factory,
            rows: asRows(props.work_centers),
            primary: (r) => String(r.name),
            secondary: (r) =>
                [r.code, r.legal_entity].filter(Boolean).join(' · ') || null,
            extras: [
                {
                    key: 'state',
                    label: 'Entidad',
                    value: (r) => str(r.state),
                    hideBelow: 'md',
                },
                {
                    key: 'timezone',
                    label: 'Zona horaria',
                    value: (r) =>
                        str(r.timezone)
                            ?.replace('America/', '')
                            .replace('_', ' ') ?? null,
                    hideBelow: 'lg',
                },
                {
                    key: 'branches_count',
                    label: 'Sucursales',
                    value: (r) => Number(r.branches_count),
                    numeric: true,
                    hideBelow: 'sm',
                },
                {
                    key: 'employees_count',
                    label: 'Personal',
                    value: (r) => Number(r.employees_count),
                    numeric: true,
                },
            ],
            fields: [
                {
                    key: 'name',
                    label: 'Nombre',
                    type: 'text',
                    required: true,
                    full: true,
                },
                code,
                {
                    key: 'legal_entity_id',
                    label: 'Razón social',
                    type: 'combobox',
                    options: legalOptions,
                },
                {
                    key: 'state_code',
                    label: 'Entidad federativa',
                    type: 'combobox',
                    options: props.states,
                    hint: 'Define los festivos locales.',
                },
                {
                    key: 'timezone',
                    label: 'Zona horaria',
                    type: 'combobox',
                    options: props.timezones,
                    required: true,
                },
                { key: 'address', label: 'Domicilio', type: 'textarea' },
                active,
            ],
            defaults: {
                name: null,
                code: null,
                legal_entity_id: null,
                state_code: null,
                timezone: 'America/Mexico_City',
                address: null,
                active: true,
            },
        },
        {
            key: 'branches',
            entity: 'sucursales',
            label: 'Sucursales',
            singular: 'sucursal',
            icon: Store,
            rows: asRows(props.branches),
            primary: (r) => String(r.name),
            secondary: (r) =>
                [r.code, r.region].filter(Boolean).join(' · ') || null,
            extras: [
                {
                    key: 'work_center',
                    label: 'Centro',
                    value: (r) => str(r.work_center),
                    hideBelow: 'md',
                },
                {
                    key: 'coords',
                    label: 'Ubicación',
                    value: (r) =>
                        formatCoords(
                            r.latitude as string | null,
                            r.longitude as string | null,
                        ),
                    hideBelow: 'lg',
                },
                {
                    key: 'geofences_count',
                    label: 'Geocercas',
                    value: (r) => Number(r.geofences_count),
                    numeric: true,
                    hideBelow: 'sm',
                },
                {
                    key: 'employees_count',
                    label: 'Personal',
                    value: (r) => Number(r.employees_count),
                    numeric: true,
                },
            ],
            fields: [
                {
                    key: 'name',
                    label: 'Nombre',
                    type: 'text',
                    required: true,
                    full: true,
                },
                code,
                {
                    key: 'region',
                    label: 'Región',
                    type: 'text',
                    placeholder: 'Noreste',
                },
                {
                    key: 'work_center_id',
                    label: 'Centro de trabajo',
                    type: 'combobox',
                    options: centerOptions,
                    full: true,
                },
                { key: 'address', label: 'Domicilio', type: 'textarea' },
                {
                    key: 'latitude',
                    label: 'Latitud',
                    type: 'text',
                    placeholder: '19.4326',
                    hint: 'Entre −90 y 90',
                },
                {
                    key: 'longitude',
                    label: 'Longitud',
                    type: 'text',
                    placeholder: '-99.1332',
                    hint: 'Entre −180 y 180',
                },
                active,
            ],
            defaults: {
                name: null,
                code: null,
                region: null,
                work_center_id: null,
                address: null,
                latitude: null,
                longitude: null,
                active: true,
            },
            map: (r) =>
                osmUrl(
                    r.latitude as string | null,
                    r.longitude as string | null,
                ),
        },
        {
            key: 'departments',
            entity: 'departamentos',
            label: 'Departamentos',
            singular: 'departamento',
            icon: FolderTree,
            rows: asRows(props.departments),
            primary: (r) => String(r.name),
            secondary: (r) =>
                [r.code, r.parent ? `en ${r.parent}` : null]
                    .filter(Boolean)
                    .join(' · ') || null,
            extras: [
                {
                    key: 'positions_count',
                    label: 'Puestos',
                    value: (r) => Number(r.positions_count),
                    numeric: true,
                    hideBelow: 'sm',
                },
                {
                    key: 'employees_count',
                    label: 'Personal',
                    value: (r) => Number(r.employees_count),
                    numeric: true,
                },
            ],
            fields: [
                {
                    key: 'name',
                    label: 'Nombre',
                    type: 'text',
                    required: true,
                    full: true,
                },
                code,
                {
                    key: 'parent_id',
                    label: 'Departamento superior',
                    type: 'combobox',
                    options: departmentOptions,
                },
                active,
            ],
            defaults: { name: null, code: null, parent_id: null, active: true },
        },
        {
            key: 'positions',
            entity: 'puestos',
            label: 'Puestos',
            singular: 'puesto',
            icon: Briefcase,
            rows: asRows(props.positions),
            primary: (r) => String(r.name),
            secondary: (r) => str(r.code),
            extras: [
                {
                    key: 'department',
                    label: 'Departamento',
                    value: (r) => str(r.department),
                    hideBelow: 'sm',
                },
                {
                    key: 'auto_clock_out_at',
                    label: 'Salida auto.',
                    value: (r) => str(r.auto_clock_out_at),
                    hideBelow: 'md',
                },
                {
                    key: 'employees_count',
                    label: 'Personal',
                    value: (r) => Number(r.employees_count),
                    numeric: true,
                },
            ],
            fields: [
                {
                    key: 'name',
                    label: 'Nombre',
                    type: 'text',
                    required: true,
                    full: true,
                },
                code,
                {
                    key: 'department_id',
                    label: 'Departamento',
                    type: 'combobox',
                    options: departmentOptions,
                },
                {
                    key: 'auto_clock_out_at',
                    label: 'Salida automática',
                    type: 'time',
                    full: true,
                    hint: 'Si no marca salida, se registra a esta hora. No cuenta como horas extra.',
                },
                active,
            ],
            defaults: {
                name: null,
                code: null,
                department_id: null,
                auto_clock_out_at: null,
                active: true,
            },
        },
        {
            key: 'geofences',
            entity: 'geocercas',
            label: 'Geocercas',
            singular: 'geocerca',
            icon: Radar,
            rows: asRows(props.geofences),
            primary: (r) => String(r.name),
            secondary: (r) => str(r.branch),
            extras: [
                {
                    key: 'coords',
                    label: 'Centro',
                    value: (r) =>
                        formatCoords(
                            r.latitude as string,
                            r.longitude as string,
                        ),
                    hideBelow: 'md',
                },
                {
                    key: 'radius_meters',
                    label: 'Radio',
                    value: (r) =>
                        `${Number(r.radius_meters).toLocaleString('es-MX')} m`,
                    numeric: true,
                },
            ],
            fields: [
                {
                    key: 'name',
                    label: 'Nombre',
                    type: 'text',
                    required: true,
                    full: true,
                },
                {
                    key: 'branch_id',
                    label: 'Sucursal',
                    type: 'combobox',
                    options: branchOptions,
                    full: true,
                },
                {
                    key: 'latitude',
                    label: 'Latitud',
                    type: 'text',
                    required: true,
                    placeholder: '19.4326',
                },
                {
                    key: 'longitude',
                    label: 'Longitud',
                    type: 'text',
                    required: true,
                    placeholder: '-99.1332',
                },
                {
                    key: 'radius_meters',
                    label: 'Radio (m)',
                    type: 'number',
                    required: true,
                    min: 20,
                    max: 5000,
                    step: 10,
                    hint: 'Entre 20 m y 5 km',
                },
                active,
            ],
            defaults: {
                name: null,
                branch_id: null,
                latitude: null,
                longitude: null,
                radius_meters: 100,
                active: true,
            },
            map: (r) => osmUrl(r.latitude as string, r.longitude as string),
        },
    ];

    return list;
});

const validTabs = [
    'legal-entities',
    'work-centers',
    'branches',
    'departments',
    'positions',
    'geofences',
];
const activeTab = ref(
    validTabs.includes(props.tab) ? props.tab : 'legal-entities',
);
const showInactive = ref(false);
const search = ref('');

function visibleRows(tab: TabConfig): Row[] {
    const term = search.value.trim().toLowerCase();

    return tab.rows.filter((row) => {
        if (!showInactive.value && row.deleted) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [tab.primary(row), tab.secondary(row)]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(term));
    });
}

function activeCount(tab: TabConfig): number {
    return tab.rows.filter((r) => !r.deleted).length;
}

function columns(tab: TabConfig): DataTableColumn<Row>[] {
    return [
        { key: 'name', label: 'Nombre' },
        ...tab.extras.map((e) => ({
            key: e.key,
            label: e.label,
            accessor: e.value,
            hideBelow: e.hideBelow,
            numeric: e.numeric,
        })),
        { key: 'status', label: 'Estado', class: 'w-28' },
        { key: 'actions', label: '', class: 'w-12', align: 'right' as const },
    ];
}

/* ---------------------------------------------------------- Formularios -- */
const sheetOpen = ref(false);
const sheetTab = ref<TabConfig | null>(null);
const sheetRecord = ref<Row | null>(null);

const sheetInitial = computed<Record<string, FormValue>>(() => {
    const tab = sheetTab.value;

    if (!tab) {
        return {};
    }

    const base = { ...tab.defaults };

    if (sheetRecord.value) {
        for (const key of Object.keys(base)) {
            const value = sheetRecord.value[key];
            base[key] =
                typeof value === 'number' && key.endsWith('_id')
                    ? String(value)
                    : ((value as FormValue) ?? null);
        }
    }

    return base;
});

function openCreate(tab: TabConfig): void {
    sheetTab.value = tab;
    sheetRecord.value = null;
    sheetOpen.value = true;
}

function openEdit(tab: TabConfig, row: Row): void {
    sheetTab.value = tab;
    sheetRecord.value = row;
    sheetOpen.value = true;
}

/* ------------------------------------------------- Desactivar / reactivar -- */
const confirmOpen = ref(false);
const pending = ref<{ tab: TabConfig; row: Row } | null>(null);
const busy = ref(false);

function askDeactivate(tab: TabConfig, row: Row): void {
    pending.value = { tab, row };
    confirmOpen.value = true;
}

function showErrors(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function deactivate(): void {
    if (!pending.value) {
        return;
    }

    const { tab, row } = pending.value;
    busy.value = true;
    router.post(
        OrganizationStructureController.deactivate.url({
            entity: tab.entity,
            id: row.id,
        }),
        {},
        {
            preserveScroll: true,
            onError: showErrors,
            onFinish: () => {
                busy.value = false;
                confirmOpen.value = false;
            },
        },
    );
}

function restore(tab: TabConfig, row: Row): void {
    router.post(
        OrganizationStructureController.restore.url({
            entity: tab.entity,
            id: row.id,
        }),
        {},
        { preserveScroll: true, onError: showErrors },
    );
}

const help: HelpStep[] = [
    {
        title: 'Razón social',
        text: 'El patrón registrado ante el SAT e IMSS.',
        icon: Landmark,
    },
    {
        title: 'Centro de trabajo',
        text: 'Define zona horaria y festivos locales.',
        icon: Factory,
    },
    {
        title: 'Sucursales y geocercas',
        text: 'La geocerca delimita dónde se acepta el marcaje.',
        icon: MapPin,
    },
    {
        title: 'Nada se borra',
        text: 'Desactiva lo que ya no uses; puedes reactivarlo cuando quieras.',
        icon: Archive,
    },
];
</script>

<template>
    <PageHeader
        title="Organización"
        description="Estructura de la empresa."
        :icon="Building2"
        :help="help"
        help-title="Estructura de la organización"
    />

    <Tabs v-model="activeTab" class="gap-5">
        <div
            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <div class="-mx-1 overflow-x-auto px-1 pb-1">
                <TabsList class="h-auto w-max">
                    <TabsTrigger
                        v-for="tab in tabs"
                        :key="tab.key"
                        :value="tab.key"
                        class="gap-2 px-3 py-1.5"
                    >
                        <component
                            :is="tab.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ tab.label }}
                        <span
                            class="rounded-full bg-surface-3 px-1.5 numeric text-[0.6875rem] font-semibold text-muted-foreground"
                            >{{ activeCount(tab) }}</span
                        >
                    </TabsTrigger>
                </TabsList>
            </div>

            <div class="flex items-center gap-3">
                <SearchInput
                    v-model="search"
                    placeholder="Buscar…"
                    class="w-full sm:w-56"
                />
                <div class="flex shrink-0 items-center gap-2">
                    <Switch id="show-inactive" v-model="showInactive" />
                    <Label
                        for="show-inactive"
                        class="text-sm whitespace-nowrap text-muted-foreground"
                        >Ver inactivos</Label
                    >
                </div>
            </div>
        </div>

        <TabsContent
            v-for="tab in tabs"
            :key="tab.key"
            :value="tab.key"
            class="mt-0"
        >
            <EmptyState
                v-if="tab.rows.length === 0"
                :icon="tab.icon"
                tone="brand"
                bordered
                class="rounded-2xl py-14"
                :title="`Sin ${tab.label.toLowerCase()}`"
                :description="`Registra la primera ${tab.singular} para empezar.`"
            >
                <template v-if="can.manage" #actions>
                    <Button @click="openCreate(tab)">
                        <Plus />
                        Agregar {{ tab.singular }}
                    </Button>
                </template>
            </EmptyState>

            <DataTable
                v-else
                :rows="visibleRows(tab)"
                :columns="columns(tab)"
                :server="false"
                :caption="tab.label"
                empty-title="Sin coincidencias"
                empty-description="Ajusta la búsqueda o activa «Ver inactivos»."
            >
                <template #toolbar>
                    <div class="flex w-full items-center justify-between gap-3">
                        <p class="text-sm text-muted-foreground">
                            <span
                                class="numeric font-semibold text-foreground"
                                >{{ visibleRows(tab).length }}</span
                            >
                            {{
                                visibleRows(tab).length === 1
                                    ? 'registro'
                                    : 'registros'
                            }}
                        </p>
                        <Button
                            v-if="can.manage"
                            size="sm"
                            @click="openCreate(tab)"
                        >
                            <Plus />
                            Agregar
                        </Button>
                    </div>
                </template>

                <template #cell-name="{ row }">
                    <div
                        class="flex min-w-0 items-center gap-3"
                        :class="row.deleted ? 'opacity-60' : ''"
                    >
                        <div
                            class="hidden size-9 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground sm:flex"
                        >
                            <component
                                :is="tab.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-semibold">
                                {{ tab.primary(row) }}
                            </p>
                            <p
                                v-if="tab.secondary(row)"
                                class="truncate font-mono text-xs text-muted-foreground"
                            >
                                {{ tab.secondary(row) }}
                            </p>
                        </div>
                    </div>
                </template>

                <template #cell-coords="{ row, value }">
                    <a
                        v-if="value && tab.map?.(row)"
                        :href="tab.map(row) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-mono text-xs text-brand-strong hover:underline"
                    >
                        <MapPin class="size-3.5" aria-hidden="true" />
                        {{ value }}
                    </a>
                    <span v-else class="text-subtle-foreground">—</span>
                </template>

                <template #cell-status="{ row }">
                    <StatusBadge
                        :status="
                            row.deleted || !row.active ? 'inactive' : 'active'
                        "
                        size="sm"
                    />
                </template>

                <template #cell-actions="{ row }">
                    <DropdownMenu v-if="can.manage">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Acciones"
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <template v-if="!row.deleted">
                                <DropdownMenuItem @select="openEdit(tab, row)">
                                    <Pencil />
                                    Editar
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    @select="askDeactivate(tab, row)"
                                >
                                    <Archive />
                                    Desactivar
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-else
                                @select="restore(tab, row)"
                            >
                                <ArchiveRestore />
                                Reactivar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </DataTable>
        </TabsContent>
    </Tabs>

    <StructureSheet
        v-if="sheetTab"
        v-model:open="sheetOpen"
        :entity="sheetTab.entity"
        :record-id="sheetRecord?.id ?? null"
        :key="sheetTab.key"
        :title="`${sheetRecord ? 'Editar' : 'Agregar'} ${sheetTab.singular}`"
        :fields="sheetTab.fields"
        :initial="sheetInitial"
    />

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="`¿Desactivar «${pending ? pending.tab.primary(pending.row) : ''}»?`"
        description="Dejará de estar disponible para nuevas asignaciones. Su historial se conserva y puedes reactivarlo."
        confirm-label="Desactivar"
        tone="danger"
        :icon="Archive"
        :loading="busy"
        :close-on-confirm="false"
        @confirm="deactivate"
    />
</template>
