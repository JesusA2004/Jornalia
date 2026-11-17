<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Ban,
    BedDouble,
    CalendarX2,
    Clock3,
    Plus,
    Save,
    Undo2,
    UserRound,
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { ScheduleExceptionPaginator, ScheduleExceptionRow } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Horarios', href: '/horarios' },
            { title: 'Excepciones' },
        ],
    },
});

const props = defineProps<{
    exceptions: ScheduleExceptionPaginator;
    filters: { from: string; to: string; employee_id: string | null };
    employees: SelectOption[];
    can: { manage: boolean };
}>();

const help: HelpStep[] = [
    {
        title: 'Excepción puntual',
        text: 'Sustituye el horario asignado sólo en esa fecha.',
        icon: CalendarX2,
    },
    {
        title: 'Descanso o jornada especial',
        text: 'Marca el día como descanso o define otra entrada y salida.',
        icon: BedDouble,
    },
    {
        title: 'Límite legal',
        text: 'La jornada especial no puede exceder el máximo diario vigente.',
        icon: Clock3,
    },
    {
        title: 'Anular',
        text: 'Sólo excepciones de hoy en adelante; las pasadas se conservan.',
        icon: Undo2,
    },
];

const { filters, activeCount, reset } = useFilters(
    {
        from: props.filters.from,
        to: props.filters.to,
        employee_id: props.filters.employee_id,
    },
    { only: ['exceptions', 'filters'] },
);

const today = new Date().toISOString().slice(0, 10);

const columns: DataTableColumn<ScheduleExceptionRow>[] = [
    { key: 'date', label: 'Fecha', class: 'w-36', mobile: 'title' },
    { key: 'employee', label: 'Colaborador' },
    { key: 'shift', label: 'Jornada' },
    { key: 'reason', label: 'Motivo', hideBelow: 'lg', mobile: 'full' },
    { key: 'created_by', label: 'Registró', hideBelow: 'xl' },
    { key: 'actions', label: '', align: 'right', class: 'w-24' },
];

/* --------------------------------------------------------- Alta -- */
const sheetOpen = ref(false);
const form = useForm({
    employee_id: null as string | null,
    date: today as string | null,
    is_rest_day: false,
    starts_at: '09:00',
    ends_at: '18:00',
    break_minutes: 60,
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

/* --------------------------------------------------------- Anular -- */
const target = ref<ScheduleExceptionRow | null>(null);
const confirmOpen = ref(false);
const cancelling = ref(false);

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
            title="Excepciones de horario"
            description="Cambios puntuales por colaborador y día."
            :icon="CalendarX2"
            :help="help"
            back-href="/horarios"
            back-label="Horarios"
        >
            <template #actions>
                <Button v-if="can.manage" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Nueva excepción
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
        </FilterBar>

        <DataTable
            :rows="exceptions.data"
            :columns="columns"
            :pagination="toPaginationMeta(exceptions)"
            :only="['exceptions', 'filters']"
            :empty-icon="CalendarX2"
            empty-title="Sin excepciones en el periodo"
            empty-description="Registra descansos o jornadas especiales cuando se necesiten."
            caption="Excepciones de horario"
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
            <template #cell-created_by="{ row }">
                <span class="text-muted-foreground">{{
                    row.created_by ?? '—'
                }}</span>
            </template>
            <template #cell-actions="{ row }">
                <Button
                    v-if="can.manage && row.date >= today"
                    variant="ghost"
                    size="sm"
                    @click="askCancel(row)"
                >
                    <Ban aria-hidden="true" />
                    Anular
                </Button>
            </template>
            <template #empty-actions>
                <Button v-if="can.manage" variant="outline" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Nueva excepción
                </Button>
            </template>
        </DataTable>

        <Sheet v-model:open="sheetOpen">
            <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Nueva excepción</SheetTitle>
                    <SheetDescription
                        >Aplica sólo a la fecha indicada.</SheetDescription
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
                            :min="today"
                            :invalid="!!form.errors.date"
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
                        label="Motivo"
                        :error="form.errors.reason"
                        required
                        v-slot="{ id, describedBy }"
                    >
                        <Textarea
                            :id="id"
                            v-model="form.reason"
                            rows="3"
                            placeholder="Cambio de turno acordado"
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
                        Guardar
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <ConfirmDialog
            v-model:open="confirmOpen"
            title="¿Anular excepción?"
            :description="
                target
                    ? `${target.employee.name} volverá a su horario asignado el ${formatDate(target.date, 'date')}.`
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
