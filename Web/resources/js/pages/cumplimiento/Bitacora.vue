<script setup lang="ts">
import {
    Braces,
    Globe,
    Lock,
    MonitorSmartphone,
    ScrollText,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateRangePicker from '@/components/app/DateRangePicker.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import KeyValueGrid from '@/components/app/KeyValueGrid.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import SelectField from '@/components/app/SelectField.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useFilters } from '@/composables/useFilters';
import { formatDateTime } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, KeyValueItem } from '@/types';
import type { AuditIndexProps, AuditLogRow } from '@/types/operacion';
import ComplianceTabs from './components/ComplianceTabs.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Análisis' },
            { title: 'Cumplimiento', href: '/cumplimiento' },
            { title: 'Bitácora de cambios' },
        ],
    },
});

const props = defineProps<AuditIndexProps>();

const { filters, activeCount, reset, processing } = useFilters(
    {
        search: props.filters.search ?? '',
        module: props.filters.module,
        user_id: props.filters.user_id,
        from: props.filters.from,
        to: props.filters.to,
    },
    { only: ['logs', 'filters'], defaults: { search: '' } },
);

const MODULE_TONE: Record<string, string> = {
    punch: 'bg-primary-soft text-primary-soft-foreground',
    rectification: 'bg-info-soft text-info-soft-foreground',
    incidence: 'bg-warning-soft text-warning-soft-foreground',
    vacation: 'bg-success-soft text-success-soft-foreground',
    overtime: 'bg-warning-soft text-warning-soft-foreground',
    compliance: 'bg-danger-soft text-danger-soft-foreground',
    integrity: 'bg-brand-soft text-brand-soft-foreground',
    report: 'bg-cyan-soft text-cyan-soft-foreground',
};
const toneFor = (module: string) =>
    MODULE_TONE[module] ?? 'bg-neutral-soft text-neutral-soft-foreground';

const columns: DataTableColumn<AuditLogRow>[] = [
    { key: 'created_at', label: 'Fecha y hora', class: 'w-44' },
    { key: 'user', label: 'Usuario', hideBelow: 'md' },
    { key: 'module', label: 'Módulo', class: 'w-36' },
    { key: 'description', label: 'Descripción' },
    {
        key: 'ip_address',
        label: 'Dispositivo o IP',
        hideBelow: 'lg',
        class: 'w-40',
    },
];

const selected = ref<AuditLogRow | null>(null);
const open = ref(false);
function show(row: AuditLogRow): void {
    selected.value = row;
    open.value = true;
}

const details = computed<KeyValueItem[]>(() => {
    const l = selected.value;
    if (!l) {
        return [];
    }

    return [
        {
            key: 'at',
            label: 'Fecha y hora',
            value: formatDateTime(l.created_at, {
                format: 'datetime-long',
                timeZone: props.timezone,
            }),
            full: true,
        },
        { key: 'action', label: 'Acción', value: l.action, mono: true },
        { key: 'module', label: 'Módulo', value: l.module_label },
        {
            key: 'subject',
            label: 'Registro',
            value: l.subject_type
                ? `${l.subject_type} #${l.subject_id ?? '—'}`
                : null,
            mono: true,
        },
        {
            key: 'id',
            label: 'Folio de bitácora',
            value: `#${l.id}`,
            mono: true,
        },
        {
            key: 'user',
            label: 'Usuario',
            value: l.user ? `${l.user.name} · ${l.user.email}` : 'Sistema',
            full: true,
        },
        { key: 'ip', label: 'IP', value: l.ip_address, mono: true },
        { key: 'device', label: 'Dispositivo', value: l.device },
        {
            key: 'ua',
            label: 'Navegador / dispositivo',
            value: l.user_agent,
            full: true,
        },
    ];
});

function pretty(value: Record<string, unknown> | null): string | null {
    return value && Object.keys(value).length > 0
        ? JSON.stringify(value, null, 2)
        : null;
}

const oldJson = computed(() => pretty(selected.value?.old_values ?? null));
const newJson = computed(() => pretty(selected.value?.new_values ?? null));

const contextJson = computed(() =>
    selected.value?.context && Object.keys(selected.value.context).length > 0
        ? JSON.stringify(selected.value.context, null, 2)
        : null,
);

const help = [
    {
        title: 'Bitácora inmutable',
        text: 'Ningún registro se modifica ni se elimina. Guarda usuario, fecha y hora, acción, valor anterior y nuevo, dispositivo o IP.',
        icon: Lock,
    },
    {
        title: 'Filtra',
        text: 'Por módulo, usuario, periodo o texto.',
        icon: ScrollText,
    },
    {
        title: 'Detalle',
        text: 'Abre un evento para ver contexto, IP y dispositivo.',
        icon: Braces,
    },
];
</script>

<template>
    <PageHeader
        title="Cumplimiento"
        description="Bitácora de cambios inalterable."
        :icon="ScrollText"
        :help="help"
        help-title="Bitácora de cambios"
    />

    <ComplianceTabs />

    <div class="space-y-5">
        <FilterBar :active-count="activeCount" @reset="reset">
            <template #search>
                <SearchInput
                    v-model="filters.search"
                    placeholder="Descripción, acción o IP…"
                />
            </template>
            <SelectField
                v-model="filters.module"
                :options="options.modules"
                placeholder="Módulo"
                prefix="Módulo:"
                clearable
                class="w-full sm:w-48"
            />
            <ComboboxField
                v-model="filters.user_id"
                :options="options.users"
                placeholder="Usuario"
                search-placeholder="Nombre o correo…"
                clearable
                class="w-full sm:w-60"
            />
            <DateRangePicker
                v-model:start="filters.from"
                v-model:end="filters.to"
                class="w-full sm:w-auto"
            />
        </FilterBar>

        <DataTable
            :rows="logs.data"
            :columns="columns"
            :pagination="toPaginationMeta(logs)"
            :loading="processing"
            :only="['logs']"
            :per-page-options="[25, 50, 100]"
            caption="Bitácora de auditoría"
            :empty-icon="ScrollText"
            empty-title="Sin eventos"
            empty-description="No hay eventos registrados con estos filtros."
            @row-click="show"
        >
            <template #cell-created_at="{ row }">
                <DateTimeDisplay
                    :value="row.created_at"
                    :timezone="timezone"
                    format="datetime"
                    class="whitespace-nowrap"
                />
            </template>
            <template #cell-user="{ row }">
                <UserAvatar
                    v-if="row.user"
                    :name="row.user.name"
                    :subtitle="row.user.email"
                    size="sm"
                    show-name
                />
                <span
                    v-else
                    class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <MonitorSmartphone class="size-3.5" aria-hidden="true" />
                    Sistema
                </span>
            </template>
            <template #cell-module="{ row }">
                <span
                    :class="[
                        'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
                        toneFor(row.module),
                    ]"
                >
                    {{ row.module_label }}
                </span>
            </template>
            <template #cell-description="{ row }">
                <button
                    type="button"
                    class="group block min-w-0 text-left"
                    @click.stop="show(row)"
                >
                    <span class="line-clamp-2 text-sm group-hover:underline">{{
                        row.description
                    }}</span>
                    <span
                        class="block font-mono text-[0.6875rem] text-muted-foreground"
                        >{{ row.action }}</span
                    >
                </button>
            </template>
            <template #cell-ip_address="{ row }">
                <span
                    v-if="row.device"
                    class="block truncate text-xs text-muted-foreground"
                    >{{ row.device }}</span
                >
                <span class="font-mono text-xs text-muted-foreground">{{
                    row.ip_address ?? '—'
                }}</span>
            </template>
        </DataTable>
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader v-if="selected">
                <SheetTitle class="pr-6">{{ selected.description }}</SheetTitle>
                <SheetDescription class="flex items-center gap-2">
                    <span
                        :class="[
                            'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
                            toneFor(selected.module),
                        ]"
                    >
                        {{ selected.module_label }}
                    </span>
                    <DateTimeDisplay
                        :value="selected.created_at"
                        :timezone="timezone"
                        format="relative"
                    />
                </SheetDescription>
            </SheetHeader>

            <div v-if="selected" class="space-y-6 px-4 pb-6">
                <KeyValueGrid :items="details" :columns="2" />

                <div
                    v-if="oldJson || newJson"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div class="space-y-2">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Valor anterior
                        </p>
                        <pre
                            class="max-h-72 overflow-auto rounded-xl border bg-surface-2 p-3 font-mono text-xs leading-relaxed"
                            >{{ oldJson ?? '—' }}</pre>
                    </div>
                    <div class="space-y-2">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Valor nuevo
                        </p>
                        <pre
                            class="max-h-72 overflow-auto rounded-xl border bg-surface-2 p-3 font-mono text-xs leading-relaxed"
                            >{{ newJson ?? '—' }}</pre>
                    </div>
                </div>

                <div class="space-y-2">
                    <p
                        class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <Braces class="size-3.5" aria-hidden="true" />
                        Contexto
                    </p>
                    <pre
                        v-if="contextJson"
                        class="max-h-96 overflow-auto rounded-xl border bg-surface-2 p-4 font-mono text-xs leading-relaxed text-foreground/90"
                        >{{ contextJson }}</pre>
                    <p
                        v-else
                        class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        Sin contexto adicional.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <span
                        v-if="selected.user"
                        class="inline-flex items-center gap-1.5"
                    >
                        <UserRound class="size-3.5" aria-hidden="true" />{{
                            selected.user.name
                        }}
                    </span>
                    <span
                        v-if="selected.ip_address"
                        class="inline-flex items-center gap-1.5"
                    >
                        <Globe class="size-3.5" aria-hidden="true" />{{
                            selected.ip_address
                        }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <Lock class="size-3.5" aria-hidden="true" />Registro
                        inmutable
                    </span>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
