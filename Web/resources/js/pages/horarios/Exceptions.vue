<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Ban,
    BedDouble,
    BellRing,
    CalendarClock,
    Check,
    Clock3,
    History,
    Plus,
    Save,
    ShieldCheck,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ScheduleExceptionController from '@/actions/App/Http/Controllers/Web/Admin/ScheduleExceptionController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import DateRangePicker from '@/components/app/DateRangePicker.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep, SelectOption } from '@/types';
import NumberInput from './components/NumberInput.vue';
import TimeField from './components/TimeField.vue';
import { crossesMidnight, hoursLabel, segmentMinutes } from './types';
import type {
    ScheduleChangeStatus,
    ScheduleExceptionPaginator,
    ScheduleExceptionRow,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Horarios', href: '/horarios' },
            { title: 'Cambios programados' },
        ],
    },
});

const props = defineProps<{
    exceptions: ScheduleExceptionPaginator;
    filters: {
        from: string;
        to: string;
        employee_id: string | null;
        status: ScheduleChangeStatus | null;
    };
    pending_count: number;
    employees: SelectOption[];
    reasons: SelectOption[];
    /** Primer día permitido (mañana): nunca retroactivo. */
    min_date: string;
    current_user_id: number;
    can: { manage: boolean; approve: boolean };
}>();

const help: HelpStep[] = [
    {
        title: 'Sólo a futuro',
        text: 'Desde mañana. Nunca retroactivo.',
        icon: CalendarClock,
    },
    {
        title: 'Motivo de catálogo',
        text: 'Guardia, inventario, capacitación, lactancia, cambio de turno o festivo laborado.',
        icon: BedDouble,
    },
    {
        title: 'Autoriza RH',
        text: 'Sólo surte efecto una vez autorizado. Quien lo solicita no lo autoriza.',
        icon: ShieldCheck,
    },
    {
        title: 'Aviso y bitácora',
        text: 'Se notifica al colaborador en la app y queda registrado.',
        icon: BellRing,
    },
    {
        title: 'Sin efecto en el pasado',
        text: 'No modifica marcajes ni borra retardos u horas extra ya generados.',
        icon: History,
    },
    {
        title: 'Límite legal',
        text: 'La jornada no puede exceder el máximo diario vigente.',
        icon: Clock3,
    },
];

const { filters, activeCount, reset } = useFilters(
    {
        from: props.filters.from,
        to: props.filters.to,
        employee_id: props.filters.employee_id,
        status: props.filters.status,
    },
    { only: ['exceptions', 'filters', 'pending_count'] },
);

const statusOptions: SelectOption[] = [
    { value: 'pending', label: 'Por autorizar' },
    { value: 'approved', label: 'Autorizados' },
    { value: 'rejected', label: 'Rechazados' },
];

const STATUS_LABEL: Record<ScheduleChangeStatus, string> = {
    pending: 'Por autorizar',
    approved: 'Autorizado',
    rejected: 'Rechazado',
};

const columns: DataTableColumn<ScheduleExceptionRow>[] = [
    { key: 'date', label: 'Fecha', class: 'w-36', mobile: 'title' },
    { key: 'employee', label: 'Colaborador' },
    { key: 'shift', label: 'Jornada' },
    { key: 'reason', label: 'Motivo', hideBelow: 'lg', mobile: 'full' },
    { key: 'status', label: 'Estado', class: 'w-36' },
    { key: 'actions', label: '', align: 'right', class: 'w-40' },
];

/* --------------------------------------------------------- Alta -- */
const sheetOpen = ref(false);
const form = useForm({
    employee_id: null as string | null,
    date: props.min_date as string | null,
    is_rest_day: false,
    starts_at: '09:00',
    ends_at: '18:00',
    break_minutes: 60,
    reason_code: null as string | null,
    reason: '',
});

const preview = computed(() =>
    form.is_rest_day
        ? 0
        : segmentMinutes({
              starts_at: form.starts_at,
              ends_at: form.ends_at,
              break_minutes: form.break_minutes,
          }),
);

function openCreate(): void {
    form.reset();
    form.date = props.min_date;
    form.clearErrors();
    sheetOpen.value = true;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        starts_at: data.is_rest_day ? null : data.starts_at,
        ends_at: data.is_rest_day ? null : data.ends_at,
        break_minutes: data.is_rest_day ? 0 : data.break_minutes,
    })).submit(ScheduleExceptionController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            sheetOpen.value = false;
            form.reset();
        },
    });
}

/* --------------------------------------------- Autorizar / rechazar -- */
const busyId = ref<number | null>(null);

function canDecide(row: ScheduleExceptionRow): boolean {
    return (
        props.can.approve &&
        row.status === 'pending' &&
        row.created_by_id !== props.current_user_id
    );
}

function approve(row: ScheduleExceptionRow): void {
    router.visit(ScheduleExceptionController.approve(row.id), {
        preserveScroll: true,
        onStart: () => (busyId.value = row.id),
        onFinish: () => (busyId.value = null),
    });
}

const rejectOpen = ref(false);
const rejectTarget = ref<ScheduleExceptionRow | null>(null);
const rejectForm = useForm({ reason: '' });

function askReject(row: ScheduleExceptionRow): void {
    rejectTarget.value = row;
    rejectForm.reset();
    rejectForm.clearErrors();
    rejectOpen.value = true;
}

function confirmReject(): void {
    if (!rejectTarget.value) {
        return;
    }

    rejectForm.submit(
        ScheduleExceptionController.reject(rejectTarget.value.id),
        {
            preserveScroll: true,
            onSuccess: () => (rejectOpen.value = false),
        },
    );
}

/* --------------------------------------------------------- Anular -- */
const target = ref<ScheduleExceptionRow | null>(null);
const confirmOpen = ref(false);
const cancelling = ref(false);

function canCancel(row: ScheduleExceptionRow): boolean {
    return (
        (props.can.manage || props.can.approve) &&
        row.status !== 'rejected' &&
        row.date >= props.min_date
    );
}

function askCancel(row: ScheduleExceptionRow): void {
    target.value = row;
    confirmOpen.value = true;
}

function confirmCancel(): void {
    if (!target.value) {
        return;
    }

    router.visit(ScheduleExceptionController.deactivate(target.value.id), {
        preserveScroll: true,
        onStart: () => (cancelling.value = true),
        onFinish: () => {
            cancelling.value = false;
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Cambios de horario programados"
            description="Sólo a futuro, con motivo y autorización de RH."
            :icon="CalendarClock"
            :help="help"
            help-title="Cambios de horario programados"
            back-href="/horarios"
            back-label="Horarios"
        >
            <template #actions>
                <Badge v-if="pending_count > 0" variant="secondary">
                    {{ pending_count }} por autorizar
                </Badge>
                <Button v-if="can.manage" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Programar cambio
                </Button>
            </template>
        </PageHeader>

        <FilterBar :active-count="activeCount" @reset="reset">
            <DateRangePicker
                v-model:start="filters.from"
                v-model:end="filters.to"
                class="w-72"
            />
            <ComboboxField
                v-model="filters.employee_id"
                :options="employees"
                placeholder="Todos los colaboradores"
                search-placeholder="Nombre o número…"
                clearable
                class="w-72"
            />
            <SelectField
                v-model="filters.status"
                :options="statusOptions"
                placeholder="Estado"
                prefix="Estado:"
                size="sm"
                clearable
                class="w-48"
            />
        </FilterBar>

        <DataTable
            :rows="exceptions.data"
            :columns="columns"
            :pagination="toPaginationMeta(exceptions)"
            :only="['exceptions', 'filters', 'pending_count']"
            :empty-icon="CalendarClock"
            empty-title="Sin cambios en el periodo"
            empty-description="Programa guardias, inventarios o cambios de turno a futuro."
            caption="Cambios de horario programados"
        >
            <template #cell-date="{ row }">
                <span class="font-semibold">{{
                    formatDate(row.date, 'date')
                }}</span>
            </template>
            <template #cell-employee="{ row }">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ row.employee.name }}</p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ row.employee.employee_number }}
                    </p>
                </div>
            </template>
            <template #cell-shift="{ row }">
                <Badge v-if="row.is_rest_day" variant="outline" class="gap-1">
                    <BedDouble aria-hidden="true" />
                    Descanso
                </Badge>
                <span
                    v-else
                    class="inline-flex flex-wrap items-center gap-1.5 numeric"
                >
                    {{ row.starts_at }}–{{ row.ends_at }}
                    <Badge
                        v-if="
                            row.starts_at &&
                            row.ends_at &&
                            crossesMidnight(row.starts_at, row.ends_at)
                        "
                        variant="outline"
                        >+1 día</Badge
                    >
                    <span
                        v-if="row.break_minutes"
                        class="text-xs text-muted-foreground"
                        >· {{ row.break_minutes }} min</span
                    >
                </span>
            </template>
            <template #cell-reason="{ row }">
                <p class="font-medium">{{ row.reason_label }}</p>
                <p
                    v-if="row.reason && row.reason !== row.reason_label"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{ row.reason }}
                </p>
                <p class="text-xs text-subtle-foreground">
                    Solicitó {{ row.created_by ?? '—' }}
                </p>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :status="row.status"
                    :label="STATUS_LABEL[row.status]"
                    size="sm"
                />
                <p
                    v-if="row.status === 'approved' && row.approved_by"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ row.approved_by }}
                    <BellRing
                        v-if="row.notified_at"
                        class="inline size-3"
                        aria-label="Notificado"
                    />
                </p>
                <p
                    v-if="row.status === 'rejected' && row.rejection_reason"
                    class="mt-1 truncate text-xs text-muted-foreground"
                    :title="row.rejection_reason"
                >
                    {{ row.rejection_reason }}
                </p>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <template v-if="canDecide(row)">
                        <Button
                            size="sm"
                            :disabled="busyId === row.id"
                            @click="approve(row)"
                        >
                            <Spinner v-if="busyId === row.id" class="size-4" />
                            <Check v-else aria-hidden="true" />
                            Autorizar
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Rechazar"
                            @click="askReject(row)"
                        >
                            <X />
                        </Button>
                    </template>
                    <Button
                        v-else-if="canCancel(row)"
                        variant="ghost"
                        size="sm"
                        @click="askCancel(row)"
                    >
                        <Ban aria-hidden="true" />
                        Anular
                    </Button>
                </div>
            </template>
            <template #empty-actions>
                <Button v-if="can.manage" variant="outline" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Programar cambio
                </Button>
            </template>
        </DataTable>

        <Sheet v-model:open="sheetOpen">
            <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Programar cambio</SheetTitle>
                    <SheetDescription
                        >Desde mañana; requiere autorización de
                        RH.</SheetDescription
                    >
                </SheetHeader>

                <form
                    id="exception-form"
                    class="flex-1 space-y-5 overflow-y-auto px-4 py-2"
                    @submit.prevent="submit"
                >
                    <FormField
                        label="Colaborador"
                        :error="form.errors.employee_id"
                        required
                        v-slot="{ id }"
                    >
                        <ComboboxField
                            :id="id"
                            v-model="form.employee_id"
                            :options="employees"
                            placeholder="Selecciona"
                            search-placeholder="Nombre o número…"
                            :invalid="!!form.errors.employee_id"
                        />
                    </FormField>

                    <FormField
                        label="Fecha"
                        :error="form.errors.date"
                        required
                        v-slot="{ id }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.date"
                            :min="min_date"
                            :invalid="!!form.errors.date"
                        />
                    </FormField>

                    <FormField
                        label="Motivo"
                        :error="form.errors.reason_code"
                        required
                        v-slot="{ id }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.reason_code"
                            :options="reasons"
                            placeholder="Elige el motivo"
                        />
                    </FormField>

                    <label
                        class="flex items-center justify-between gap-3 rounded-xl border bg-surface-2 px-4 py-3"
                    >
                        <span
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <BedDouble
                                class="icon-sm text-muted-foreground"
                                aria-hidden="true"
                            />
                            Día de descanso
                        </span>
                        <Switch v-model="form.is_rest_day" />
                    </label>

                    <template v-if="!form.is_rest_day">
                        <div class="grid grid-cols-2 gap-4">
                            <FormField
                                label="Entrada"
                                :error="form.errors.starts_at"
                                required
                            >
                                <TimeField
                                    v-model="form.starts_at"
                                    aria-label="Entrada"
                                />
                            </FormField>
                            <FormField
                                label="Salida"
                                :error="form.errors.ends_at"
                                required
                            >
                                <TimeField
                                    v-model="form.ends_at"
                                    aria-label="Salida"
                                />
                            </FormField>
                        </div>
                        <FormField
                            label="Descanso"
                            :error="form.errors.break_minutes"
                            v-slot="{ id }"
                        >
                            <NumberInput
                                :id="id"
                                v-model="form.break_minutes"
                                :min="0"
                                :max="240"
                                :step="5"
                                suffix="min"
                            />
                        </FormField>
                        <p
                            class="flex items-center gap-2 rounded-lg bg-surface-2 px-3 py-2 text-sm"
                        >
                            <Clock3
                                class="icon-sm text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="numeric font-semibold">{{
                                hoursLabel(preview)
                            }}</span>
                            <span class="text-muted-foreground">efectivas</span>
                            <Badge
                                v-if="
                                    crossesMidnight(
                                        form.starts_at,
                                        form.ends_at,
                                    )
                                "
                                variant="outline"
                                class="ml-auto"
                                >+1 día</Badge
                            >
                        </p>
                    </template>

                    <FormField
                        label="Detalle"
                        :error="form.errors.reason"
                        hint="Opcional"
                        v-slot="{ id, describedBy }"
                    >
                        <Textarea
                            :id="id"
                            v-model="form.reason"
                            rows="2"
                            placeholder="Inventario anual de sucursal"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </form>

                <SheetFooter class="flex-row justify-end gap-2 border-t">
                    <Button variant="outline" @click="sheetOpen = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        form="exception-form"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else aria-hidden="true" />
                        Enviar a RH
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="rejectOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Rechazar cambio</DialogTitle>
                    <DialogDescription>
                        {{ rejectTarget?.employee.name }} ·
                        {{
                            rejectTarget
                                ? formatDate(rejectTarget.date, 'date')
                                : ''
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormField
                    label="Motivo"
                    :error="rejectForm.errors.reason"
                    required
                    v-slot="{ id }"
                >
                    <Textarea :id="id" v-model="rejectForm.reason" rows="3" />
                </FormField>
                <DialogFooter>
                    <Button variant="outline" @click="rejectOpen = false"
                        >Cancelar</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="rejectForm.processing"
                        @click="confirmReject"
                    >
                        <Spinner v-if="rejectForm.processing" class="size-4" />
                        Rechazar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="confirmOpen"
            title="¿Anular cambio?"
            :description="
                target
                    ? `${target.employee.name} conserva su horario asignado el ${formatDate(target.date, 'date')}.`
                    : ''
            "
            confirm-label="Anular"
            tone="danger"
            :icon="UserRound"
            :loading="cancelling"
            :close-on-confirm="false"
            @confirm="confirmCancel"
        />
    </div>
</template>
