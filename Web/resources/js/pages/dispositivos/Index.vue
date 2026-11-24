<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Ban,
    Building,
    Clock,
    Fingerprint,
    KeyRound,
    MonitorSmartphone,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DeviceController from '@/actions/App/Http/Controllers/Web/Admin/DeviceController';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFilters } from '@/composables/useFilters';
import { toPaginationMeta } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { DataTableColumn, HelpStep } from '@/types';
import KiosksPanel from './components/KiosksPanel.vue';
import type {
    DeviceBrief,
    DevicePendingReason,
    DeviceRow,
    DevicesPageProps,
    DeviceStatus,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Dispositivos', href: '/dispositivos' },
        ],
    },
});

const props = defineProps<DevicesPageProps>();

const help: HelpStep[] = [
    {
        title: 'Uno a uno',
        text: 'Un teléfono por colaborador. Un teléfono nuevo queda «Pendiente».',
        icon: Smartphone,
    },
    {
        title: 'Autoriza RH',
        text: 'Sin autorización no marca; el día queda como omisión. Al autorizar, el anterior se revoca.',
        icon: ShieldCheck,
    },
    {
        title: 'Baja',
        text: 'La baja del colaborador revoca su teléfono y sesiones ese mismo día.',
        icon: UserX,
    },
    {
        title: 'Alertas',
        text: 'Ubicación simulada o teléfono alterado generan alerta en Cumplimiento. La detección depende de lo que reporta el teléfono: no es infalible.',
        icon: ShieldAlert,
    },
    {
        title: 'Kiosco',
        text: 'Tableta de sucursal para quien no tiene celular: número de empleado + NIP.',
        icon: MonitorSmartphone,
    },
    {
        title: 'Revoca',
        text: 'Con motivo; cierra las sesiones de la app en ese teléfono.',
        icon: ShieldOff,
    },
    {
        title: 'Corporativo',
        text: 'Distingue equipos de la empresa de los personales.',
        icon: Building,
    },
];

const { filters, activeCount, reset } = useFilters(
    {
        status: props.filters.status ?? null,
        search: props.filters.search ?? '',
        platform: props.filters.platform ?? null,
        corporate: props.filters.corporate ?? null,
    },
    { only: ['devices', 'filters', 'counts'], defaults: { search: '' } },
);

const tabs = computed(() => [
    {
        value: null,
        label: 'Todos',
        count:
            props.counts.pending + props.counts.approved + props.counts.revoked,
    },
    {
        value: 'pending' as DeviceStatus,
        label: 'Pendientes',
        count: props.counts.pending,
    },
    {
        value: 'approved' as DeviceStatus,
        label: 'Autorizados',
        count: props.counts.approved,
    },
    {
        value: 'revoked' as DeviceStatus,
        label: 'Revocados',
        count: props.counts.revoked,
    },
]);

const platformOptions = [
    { value: 'android', label: 'Android' },
    { value: 'ios', label: 'iOS' },
];
const corporateOptions = [
    { value: 'yes', label: 'Corporativos' },
    { value: 'no', label: 'Personales' },
];

const columns: DataTableColumn<DeviceRow>[] = [
    { key: 'name', label: 'Dispositivo', mobile: 'title' },
    { key: 'employee', label: 'Colaborador' },
    { key: 'status', label: 'Estado', class: 'w-32' },
    { key: 'last_seen_at', label: 'Última actividad', hideBelow: 'lg' },
    {
        key: 'is_corporate',
        label: 'Corporativo',
        align: 'center',
        class: 'w-28',
        hideBelow: 'md',
    },
    { key: 'actions', label: '', align: 'right', class: 'w-48' },
];

const canAct = computed(() => props.can.approve || props.can.revoke);

/* -------------------------------------------------------- Autorizar -- */
const approveTarget = ref<DeviceRow | null>(null);
const approveOpen = ref(false);
const approving = ref(false);

function askApprove(row: DeviceRow): void {
    approveTarget.value = row;
    approveOpen.value = true;
}

function confirmApprove(): void {
    if (!approveTarget.value) {
        return;
    }

    router.visit(DeviceController.approve(approveTarget.value.id), {
        preserveScroll: true,
        onStart: () => (approving.value = true),
        onFinish: () => {
            approving.value = false;
            approveOpen.value = false;
        },
    });
}

/* --------------------------------------------------------- Revocar -- */
const revokeTarget = ref<DeviceRow | null>(null);
const revokeOpen = ref(false);
const revokeForm = useForm({ reason: '' });

function askRevoke(row: DeviceRow): void {
    revokeTarget.value = row;
    revokeForm.reset();
    revokeForm.clearErrors();
    revokeOpen.value = true;
}

function submitRevoke(): void {
    if (!revokeTarget.value) {
        return;
    }

    revokeForm.submit(DeviceController.revoke(revokeTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            revokeOpen.value = false;
        },
    });
}

/* ------------------------------------------------------ Corporativo -- */
const toggling = ref<number | null>(null);

function toggleCorporate(row: DeviceRow, value: boolean): void {
    router.visit(DeviceController.corporate(row.id), {
        data: { is_corporate: value },
        preserveScroll: true,
        only: ['devices', 'counts'],
        onStart: () => (toggling.value = row.id),
        onFinish: () => (toggling.value = null),
    });
}

const PENDING_REASON: Record<DevicePendingReason, string> = {
    device_change: 'Cambio de teléfono',
    new_device: 'Teléfono nuevo',
    relink: 'Re-vinculación',
};

const deviceLabel = (d: DeviceBrief) => d.model ?? d.name;

const approveDescription = computed(() => {
    const row = approveTarget.value;

    if (!row) {
        return '';
    }

    if (row.previous_device) {
        return `Al autorizar, se revocará el teléfono anterior (${deviceLabel(row.previous_device)}).`;
    }

    return `${row.name} podrá registrar marcajes de ${row.employee?.name ?? row.user?.name ?? 'su usuario'}.`;
});

const INTEGRITY_LABEL: Record<string, string> = {
    rooted: 'Teléfono alterado (root)',
    jailbroken: 'Teléfono alterado (jailbreak)',
    emulator: 'Emulador',
    mock_location: 'Ubicación simulada',
};

const integrityLabel = (flag: string) => INTEGRITY_LABEL[flag] ?? flag;

const platformLabel = (p: string) =>
    p === 'ios' ? 'iOS' : p === 'android' ? 'Android' : p;
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Dispositivos"
            description="Teléfonos vinculados y su autorización para marcar."
            :icon="Smartphone"
            :help="help"
        />

        <!-- Segmentos por estado -->
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Estado">
            <button
                v-for="tab in tabs"
                :key="tab.label"
                type="button"
                role="tab"
                :aria-selected="filters.status === tab.value"
                :class="
                    cn(
                        'inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium transition-standard',
                        filters.status === tab.value
                            ? 'border-transparent bg-foreground text-background shadow-elev-1'
                            : 'bg-card text-muted-foreground hover:text-foreground',
                    )
                "
                @click="filters.status = tab.value"
            >
                {{ tab.label }}
                <span
                    :class="
                        cn(
                            'rounded-full px-1.5 numeric text-[0.6875rem] font-semibold',
                            filters.status === tab.value
                                ? 'bg-background/20'
                                : 'bg-surface-3',
                            tab.value === 'pending' &&
                                tab.count > 0 &&
                                filters.status !== tab.value &&
                                'bg-warning-soft text-warning-soft-foreground',
                        )
                    "
                    >{{ tab.count }}</span
                >
            </button>
        </div>

        <FilterBar :active-count="activeCount" @reset="reset">
            <template #search>
                <SearchInput
                    v-model="filters.search"
                    placeholder="Equipo, colaborador o número"
                />
            </template>
            <SelectField
                v-model="filters.platform"
                :options="platformOptions"
                placeholder="Plataforma"
                clearable
                class="w-40"
            />
            <SelectField
                v-model="filters.corporate"
                :options="corporateOptions"
                placeholder="Propiedad"
                clearable
                class="w-44"
            />
        </FilterBar>

        <DataTable
            :rows="devices.data"
            :columns="columns"
            :pagination="toPaginationMeta(devices)"
            :only="['devices', 'filters', 'counts']"
            :empty-icon="Smartphone"
            :empty-title="
                filters.status === 'pending'
                    ? 'Sin dispositivos pendientes'
                    : 'Sin dispositivos'
            "
            empty-description="Aparecerán aquí cuando el personal vincule la app."
            caption="Dispositivos vinculados"
        >
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted-foreground"
                    >
                        <Smartphone class="icon-sm" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ row.name }}</p>
                        <p
                            class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                        >
                            <span
                                >{{ platformLabel(row.platform) }}
                                {{ row.os_version }}</span
                            >
                            <span v-if="row.model">· {{ row.model }}</span>
                            <span v-if="row.app_version"
                                >· app {{ row.app_version }}</span
                            >
                            <TooltipProvider v-if="row.biometric_available">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Fingerprint
                                            class="icon-xs text-success"
                                            aria-label="Biometría disponible"
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent
                                        >Biometría del dispositivo
                                        disponible</TooltipContent
                                    >
                                </Tooltip>
                            </TooltipProvider>
                        </p>
                    </div>
                </div>
            </template>

            <template #cell-employee="{ row }">
                <Link
                    v-if="row.employee"
                    :href="EmployeeController.show(row.employee.id)"
                    class="block min-w-0 hover:underline"
                >
                    <span class="block truncate font-medium">{{
                        row.employee.name
                    }}</span>
                    <span class="font-mono text-xs text-muted-foreground">{{
                        row.employee.employee_number
                    }}</span>
                </Link>
                <div v-else-if="row.user" class="min-w-0">
                    <span class="block truncate font-medium">{{
                        row.user.name
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >Sin expediente</span
                    >
                </div>
                <span v-else class="text-muted-foreground">—</span>
            </template>

            <template #cell-status="{ row }">
                <div class="space-y-1">
                    <StatusBadge
                        :status="row.status"
                        :label="
                            row.status === 'approved'
                                ? 'Autorizado'
                                : row.status === 'pending'
                                  ? 'Pendiente'
                                  : 'Revocado'
                        "
                        size="sm"
                    />
                    <template
                        v-if="row.status === 'pending' && row.pending_reason"
                    >
                        <p
                            :class="
                                cn(
                                    'text-xs font-medium',
                                    row.pending_reason === 'device_change'
                                        ? 'text-warning-soft-foreground'
                                        : 'text-muted-foreground',
                                )
                            "
                        >
                            {{ PENDING_REASON[row.pending_reason] }}
                        </p>
                        <p
                            v-if="row.previous_device"
                            class="max-w-52 text-xs text-muted-foreground"
                        >
                            Anterior: {{ deviceLabel(row.previous_device) }} ·
                            <DateTimeDisplay
                                :value="row.previous_device.last_seen_at"
                                format="relative"
                                fallback="sin uso"
                            />
                        </p>
                    </template>
                    <p
                        v-for="flag in row.integrity_flags"
                        :key="flag"
                        class="flex items-center gap-1 text-xs font-medium text-danger"
                    >
                        <ShieldAlert class="icon-xs" aria-hidden="true" />
                        {{ integrityLabel(flag) }}
                    </p>
                    <p
                        v-if="row.status === 'revoked' && row.replaced_by"
                        class="max-w-52 truncate text-xs text-muted-foreground"
                    >
                        Reemplazado por {{ deviceLabel(row.replaced_by) }}
                    </p>
                    <p
                        v-else-if="
                            row.status === 'revoked' && row.revoke_reason
                        "
                        class="max-w-48 truncate text-xs text-muted-foreground"
                        :title="row.revoke_reason"
                    >
                        {{ row.revoke_reason }}
                    </p>
                </div>
            </template>

            <template #cell-last_seen_at="{ row }">
                <span class="inline-flex items-center gap-1.5 text-sm">
                    <Clock
                        class="icon-xs text-muted-foreground"
                        aria-hidden="true"
                    />
                    <DateTimeDisplay
                        :value="row.last_seen_at"
                        format="relative"
                        fallback="Nunca"
                    />
                </span>
            </template>

            <template #cell-is_corporate="{ row }">
                <Switch
                    v-if="canAct && row.status !== 'revoked'"
                    :model-value="row.is_corporate"
                    :disabled="toggling === row.id"
                    :aria-label="`Corporativo: ${row.name}`"
                    @update:model-value="
                        (v: boolean) => toggleCorporate(row, v)
                    "
                />
                <Badge v-else-if="row.is_corporate" variant="outline">Sí</Badge>
                <span v-else class="text-muted-foreground">No</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1.5">
                    <Button
                        v-if="can.approve && row.status === 'pending'"
                        size="sm"
                        @click="askApprove(row)"
                    >
                        <BadgeCheck aria-hidden="true" />
                        Autorizar
                    </Button>
                    <Button
                        v-if="can.revoke && row.status !== 'revoked'"
                        size="sm"
                        variant="outline"
                        @click="askRevoke(row)"
                    >
                        <Ban aria-hidden="true" />
                        Revocar
                    </Button>
                </div>
            </template>
        </DataTable>

        <ConfirmDialog
            v-model:open="approveOpen"
            title="¿Autorizar dispositivo?"
            :description="approveDescription"
            confirm-label="Autorizar"
            :icon="ShieldCheck"
            :loading="approving"
            :close-on-confirm="false"
            @confirm="confirmApprove"
        />

        <Dialog v-model:open="revokeOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <KeyRound
                            class="icon-md text-danger"
                            aria-hidden="true"
                        />
                        Revocar «{{ revokeTarget?.name }}»
                    </DialogTitle>
                    <DialogDescription
                        >Se cerrarán sus sesiones de la app. Deberá vincularse
                        de nuevo.</DialogDescription
                    >
                </DialogHeader>
                <form id="revoke-form" @submit.prevent="submitRevoke">
                    <FormField
                        label="Motivo"
                        :error="revokeForm.errors.reason"
                        required
                        v-slot="{ id, describedBy }"
                    >
                        <Textarea
                            :id="id"
                            v-model="revokeForm.reason"
                            rows="3"
                            placeholder="Equipo extraviado"
                            :aria-describedby="describedBy"
                            :aria-invalid="
                                revokeForm.errors.reason ? true : undefined
                            "
                        />
                    </FormField>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="revokeOpen = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        form="revoke-form"
                        variant="destructive"
                        :disabled="
                            revokeForm.processing ||
                            revokeForm.reason.trim().length < 5
                        "
                    >
                        <Spinner v-if="revokeForm.processing" class="size-4" />
                        <Ban v-else aria-hidden="true" />
                        Revocar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <KiosksPanel
            v-if="can.kiosk || kiosks.length > 0"
            :kiosks="kiosks"
            :branches="branches"
            :activation="kiosk_activation"
            :can-manage="can.kiosk"
        />
    </div>
</template>
