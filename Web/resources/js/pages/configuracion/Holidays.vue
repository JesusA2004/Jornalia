<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Building,
    CalendarHeart,
    Check,
    Gavel,
    Landmark,
    Lock,
    MapPin,
    Pencil,
    Plus,
    RotateCcw,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import HolidayController from '@/actions/App/Http/Controllers/Web/Admin/HolidayController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';
import InactiveToggle from './components/InactiveToggle.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import { civilDate } from './format';
import type { HolidayCan, HolidayRow, Option } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Festivos' },
        ],
    },
});

const props = defineProps<{
    year: number;
    years: number[];
    holidays: HolidayRow[];
    work_centers: Option[];
    can: HolidayCan;
}>();

const yearOptions = computed<Option[]>(() =>
    [...new Set([...props.years, props.year - 1, props.year + 1])]
        .sort((a, b) => a - b)
        .map((y) => ({ value: String(y), label: String(y) })),
);

const selectedYear = computed<string | null>({
    get: () => String(props.year),
    set: (value) => {
        if (value) {
            router.get(
                HolidayController.index.url({ query: { year: value } }),
                {},
                { preserveScroll: true, preserveState: true },
            );
        }
    },
});

const showInactive = ref(false);
const kindFilter = ref<string>('all');
const inactiveCount = computed(
    () => props.holidays.filter((h) => h.deleted).length,
);

const ORIGIN: Record<
    HolidayRow['origin'],
    { label: string; tone: Tone; icon: typeof Landmark }
> = {
    preloaded: { label: 'Oficial', tone: 'brand', icon: Landmark },
    adjusted: { label: 'Oficial ajustado', tone: 'brand', icon: Landmark },
    variable: { label: 'Obligatorio', tone: 'brand', icon: Gavel },
    company: { label: 'Empresa', tone: 'info', icon: Building },
    local: { label: 'Local', tone: 'primary', icon: MapPin },
};

/** Los días obligatorios (art. 74) los administra RH con permiso de normativa. */
function canManage(h: HolidayRow): boolean {
    return h.kind === 'official'
        ? props.can.manage_mandatory
        : props.can.manage;
}

/** Precargado o ajustado del art. 74: se edita, nunca se desactiva. */
function isFixedOfficial(h: HolidayRow): boolean {
    return h.origin === 'preloaded' || h.origin === 'adjusted';
}

const months = computed(() => {
    const groups = new Map<number, HolidayRow[]>();

    for (const h of props.holidays) {
        if (
            (!showInactive.value && h.deleted) ||
            (kindFilter.value !== 'all' && h.kind !== kindFilter.value)
        ) {
            continue;
        }

        const month = Number(h.date.slice(5, 7));
        groups.set(month, [...(groups.get(month) ?? []), h]);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => a - b)
        .map(([month, items]) => ({
            month,
            label: civilDate(
                `${props.year}-${String(month).padStart(2, '0')}-01`,
                { month: 'long' },
            ),
            items,
        }));
});

const counts = computed(() => ({
    official: props.holidays.filter((h) => h.kind === 'official' && !h.deleted)
        .length,
}));

type HolidayKind = HolidayRow['kind'];

type HolidayForm = {
    date: string | null;
    name: string;
    kind: HolidayKind;
    work_center_id: string | null;
    is_mandatory_rest: boolean;
    legal_basis: string;
    notes: string;
    change_reason: string;
};

const blank = (): HolidayForm => ({
    date: null,
    name: '',
    kind: props.can.manage ? 'company' : 'official',
    work_center_id: null,
    is_mandatory_rest: true,
    legal_basis: '',
    notes: '',
    change_reason: '',
});

const open = ref(false);
const editing = ref<HolidayRow | null>(null);
const form = useForm<HolidayForm>(blank());

/** Edición de un precargado del art. 74: sólo nombre, anotación y día movido. */
const adjusting = computed(
    () => editing.value !== null && isFixedOfficial(editing.value),
);

const kindOptions = computed(() => {
    // Un registro existente no cambia entre obligatorio y empresa/local.
    if (editing.value) {
        return editing.value.kind === 'official'
            ? (['official'] as HolidayKind[])
            : (['company', 'local'] as HolidayKind[]);
    }

    return [
        ...(props.can.manage_mandatory ? (['official'] as HolidayKind[]) : []),
        ...(props.can.manage ? (['company', 'local'] as HolidayKind[]) : []),
    ];
});

function create(): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(h: HolidayRow): void {
    editing.value = h;
    form.defaults({
        date: h.date,
        name: h.name,
        kind: h.kind,
        work_center_id: h.work_center_id ? String(h.work_center_id) : null,
        is_mandatory_rest: h.is_mandatory_rest,
        legal_basis: h.legal_basis ?? '',
        notes: h.notes ?? '',
        change_reason: '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };
    const payload = form.transform((data) =>
        adjusting.value
            ? {
                  date: data.date,
                  name: data.name,
                  notes: data.notes || null,
                  change_reason: data.change_reason,
              }
            : {
                  ...data,
                  work_center_id:
                      data.kind === 'company' ? null : data.work_center_id,
                  legal_basis: data.legal_basis || null,
                  notes: data.notes || null,
                  change_reason: data.change_reason || null,
              },
    );

    if (editing.value) {
        payload.put(HolidayController.update.url(editing.value.id), options);
    } else {
        payload.post(HolidayController.store.url(), options);
    }
}

const kindModel = computed<string>({
    get: () => form.kind,
    set: (v) => {
        if (v === 'official' || v === 'company' || v === 'local') {
            form.kind = v;
            if (v === 'official' && !form.legal_basis) {
                form.legal_basis = 'LFT art. 74 fr. IX';
            }
        }
    },
});

const KIND_LABEL: Record<HolidayKind, string> = {
    official: 'Obligatorio',
    company: 'Empresa',
    local: 'Local',
};

const target = ref<HolidayRow | null>(null);
const confirmOpen = ref(false);
const deactivateForm = useForm({ reason: '' });

function askDeactivate(h: HolidayRow): void {
    target.value = h;
    deactivateForm.reset();
    deactivateForm.clearErrors();
    confirmOpen.value = true;
}

function deactivate(): void {
    if (target.value) {
        deactivateForm.post(HolidayController.deactivate.url(target.value.id), {
            preserveScroll: true,
            onSuccess: () => (confirmOpen.value = false),
        });
    }
}

function restore(h: HolidayRow): void {
    router.post(
        HolidayController.restore.url(h.id),
        {},
        { preserveScroll: true },
    );
}

const help = [
    {
        title: 'Oficiales',
        text: 'Los del art. 74 LFT se cargan solos. No se borran ni se desactivan; RH puede anotarlos o registrar el día movido por la ley.',
        icon: Landmark,
    },
    {
        title: 'Obligatorios variables',
        text: 'Jornada electoral u otro que ordene la ley: RH los agrega por año, con fundamento y motivo.',
        icon: Gavel,
    },
    {
        title: 'De empresa',
        text: 'Descansos que otorga la empresa a todo el personal.',
        icon: Building,
    },
    {
        title: 'Locales',
        text: 'Aplican sólo al centro de trabajo elegido (fiestas estatales o municipales).',
        icon: MapPin,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Días festivos y de descanso obligatorio."
        :icon="CalendarHeart"
        :help="help"
        help-title="Festivos"
    />

    <SettingsNav />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <SelectField
            v-model="selectedYear"
            :options="yearOptions"
            prefix="Año:"
            size="sm"
            class="w-32"
        />
        <ToggleGroup
            v-model="kindFilter"
            type="single"
            variant="outline"
            size="sm"
        >
            <ToggleGroupItem value="all">Todos</ToggleGroupItem>
            <ToggleGroupItem value="official"
                >Obligatorios · {{ counts.official }}</ToggleGroupItem
            >
            <ToggleGroupItem value="company">Empresa</ToggleGroupItem>
            <ToggleGroupItem value="local">Locales</ToggleGroupItem>
        </ToggleGroup>
        <div class="ml-auto flex items-center gap-2">
            <InactiveToggle v-model="showInactive" :count="inactiveCount" />
            <Button v-if="can.manage || can.manage_mandatory" @click="create">
                <Plus class="icon-sm" />
                Agregar festivo
            </Button>
        </div>
    </div>

    <EmptyState
        v-if="months.length === 0"
        :icon="CalendarHeart"
        bordered
        class="rounded-2xl"
        :title="`Sin festivos en ${year}`"
        description="Agrega los festivos propios de la empresa o de un centro de trabajo."
    />

    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <section
            v-for="group in months"
            :key="group.month"
            class="rounded-2xl border bg-card p-4 shadow-elev-1"
        >
            <h3
                class="mb-3 font-display text-sm font-bold tracking-wide text-muted-foreground uppercase"
            >
                {{ group.label }}
            </h3>
            <ul class="grid gap-2">
                <li
                    v-for="h in group.items"
                    :key="h.id"
                    :class="
                        cn(
                            'flex items-start gap-3 rounded-xl border bg-surface-2/40 p-3',
                            h.deleted && 'opacity-60',
                        )
                    "
                >
                    <div
                        class="flex w-11 shrink-0 flex-col items-center rounded-lg bg-card py-1 shadow-elev-1 ring-1 ring-border"
                    >
                        <span
                            class="font-display text-lg leading-none font-bold"
                            >{{ Number(h.date.slice(8, 10)) }}</span
                        >
                        <span
                            class="text-[0.625rem] text-muted-foreground uppercase"
                            >{{ civilDate(h.date, { weekday: 'short' }) }}</span
                        >
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="leading-tight font-medium">{{ h.name }}</p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusBadge
                                :status="h.origin"
                                :label="ORIGIN[h.origin].label"
                                :tone="ORIGIN[h.origin].tone"
                                size="sm"
                            />
                            <span
                                v-if="!h.is_mandatory_rest"
                                class="text-xs text-muted-foreground"
                                >No obligatorio</span
                            >
                            <StatusBadge
                                v-if="h.deleted"
                                status="inactive"
                                label="Inactivo"
                                size="sm"
                            />
                        </div>
                        <p
                            v-if="h.original && h.original.date !== h.date"
                            class="text-xs text-muted-foreground"
                        >
                            Movido del {{ civilDate(h.original.date) }}
                        </p>
                        <p
                            v-if="h.work_center"
                            class="text-xs text-muted-foreground"
                        >
                            {{ h.work_center
                            }}<template v-if="h.state">
                                · {{ h.state }}</template
                            >
                        </p>
                        <p
                            v-if="h.legal_basis"
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Lock v-if="isFixedOfficial(h)" class="icon-xs" />
                            {{ h.legal_basis }}
                        </p>
                        <p v-if="h.notes" class="text-xs">{{ h.notes }}</p>
                    </div>
                    <div
                        v-if="canManage(h) && h.editable"
                        class="flex shrink-0 flex-col gap-1"
                    >
                        <Button
                            v-if="h.deleted"
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Reactivar"
                            @click="restore(h)"
                        >
                            <RotateCcw class="icon-sm" />
                        </Button>
                        <template v-else>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Editar"
                                @click="edit(h)"
                            >
                                <Pencil class="icon-sm" />
                            </Button>
                            <Button
                                v-if="h.can_deactivate"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Desactivar"
                                @click="askDeactivate(h)"
                            >
                                <Archive class="icon-sm" />
                            </Button>
                        </template>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
            <SheetHeader class="border-b">
                <SheetTitle>{{
                    adjusting
                        ? 'Ajustar día oficial'
                        : editing
                          ? 'Editar festivo'
                          : 'Agregar festivo'
                }}</SheetTitle>
                <SheetDescription>{{
                    adjusting
                        ? 'Sigue siendo descanso obligatorio.'
                        : 'Se excluye del cálculo de faltas y vacaciones.'
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="holiday-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    v-if="!adjusting && kindOptions.length > 1"
                    label="Tipo"
                    required
                >
                    <ToggleGroup
                        v-model="kindModel"
                        type="single"
                        variant="outline"
                        class="w-full"
                    >
                        <ToggleGroupItem
                            v-for="k in kindOptions"
                            :key="k"
                            :value="k"
                            class="flex-1"
                        >
                            <Gavel v-if="k === 'official'" class="icon-sm" />
                            <Building
                                v-else-if="k === 'company'"
                                class="icon-sm"
                            />
                            <MapPin v-else class="icon-sm" />
                            {{ KIND_LABEL[k] }}
                        </ToggleGroupItem>
                    </ToggleGroup>
                </FormField>
                <FormField
                    v-if="!adjusting && form.kind !== 'company'"
                    label="Centro de trabajo"
                    :error="form.errors.work_center_id"
                    :required="form.kind === 'local'"
                    :hint="form.kind === 'official' ? 'Opcional' : undefined"
                    v-slot="{ id }"
                >
                    <SelectField
                        :id="id"
                        v-model="form.work_center_id"
                        :options="work_centers"
                        placeholder="Selecciona el centro"
                        :invalid="!!form.errors.work_center_id"
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
                        dropdowns
                        :invalid="!!form.errors.date"
                    />
                </FormField>
                <FormField
                    label="Nombre"
                    :error="form.errors.name"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        :placeholder="
                            form.kind === 'official'
                                ? 'Jornada electoral'
                                : 'Aniversario de la empresa'
                        "
                    />
                </FormField>
                <FormField
                    v-if="!adjusting"
                    label="Fundamento"
                    :error="form.errors.legal_basis"
                    :required="form.kind === 'official'"
                    :hint="form.kind === 'official' ? undefined : 'Opcional'"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.legal_basis"
                        :placeholder="
                            form.kind === 'official'
                                ? 'LFT art. 74 fr. IX'
                                : 'Contrato colectivo, cláusula 12'
                        "
                    />
                </FormField>
                <FormField
                    label="Anotación"
                    :error="form.errors.notes"
                    hint="Opcional"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.notes"
                        placeholder="Visible en Calendario"
                    />
                </FormField>
                <FormField
                    v-if="adjusting || form.kind === 'official'"
                    label="Motivo del cambio"
                    :error="form.errors.change_reason"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.change_reason"
                        placeholder="Decreto publicado en el DOF"
                    />
                </FormField>
                <ToggleRow
                    v-if="!adjusting && form.kind !== 'official'"
                    v-model="form.is_mandatory_rest"
                    label="Descanso obligatorio"
                    description="Trabajarlo genera pago adicional."
                />
            </form>
            <SheetFooter class="border-t">
                <Button
                    type="submit"
                    form="holiday-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Check v-else class="icon-sm" />
                    {{ editing ? 'Guardar cambios' : 'Agregar' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="confirmOpen"
        tone="danger"
        :title="`¿Desactivar «${target?.name ?? ''}»?`"
        description="Dejará de considerarse festivo; podrás reactivarlo."
        confirm-label="Desactivar"
        :loading="deactivateForm.processing"
        :close-on-confirm="false"
        @confirm="deactivate"
    >
        <FormField
            label="Motivo"
            :error="deactivateForm.errors.reason"
            :required="target?.kind === 'official'"
            :hint="target?.kind === 'official' ? undefined : 'Opcional'"
            v-slot="{ id }"
        >
            <Input :id="id" v-model="deactivateForm.reason" />
        </FormField>
    </ConfirmDialog>
</template>
