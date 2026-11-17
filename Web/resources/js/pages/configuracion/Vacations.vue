<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    BadgePercent,
    Check,
    Palmtree,
    Pencil,
    Plus,
    RotateCcw,
    Scale,
    Sparkles,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import VacationPolicyController from '@/actions/App/Http/Controllers/Web/Admin/VacationPolicyController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
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
import { cn } from '@/lib/utils';
import InactiveToggle from './components/InactiveToggle.vue';
import NumberInput from './components/NumberInput.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import { civilDate, todayCivil } from './format';
import type {
    ExtraDaysRow,
    LegalVacationRow,
    ManageCan,
    VacationPolicyRow,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Vacaciones' },
        ],
    },
});

const props = defineProps<{
    policies: VacationPolicyRow[];
    legal_table: LegalVacationRow[];
    legal_premium_percent: string;
    can: ManageCan;
}>();

const showInactive = ref(false);
const inactiveCount = computed(
    () => props.policies.filter((p) => p.deleted).length,
);
const visible = computed(() =>
    props.policies.filter((p) => showInactive.value || !p.deleted),
);

function legalDaysFor(year: number): number {
    return (
        props.legal_table.find(
            (r) =>
                year >= r.from_year &&
                (r.to_year === null || year <= r.to_year),
        )?.days ?? 0
    );
}

function extraFor(rows: ExtraDaysRow[], year: number): number {
    return (
        rows.find(
            (r) =>
                year >= r.from_year &&
                (r.to_year === null || year <= r.to_year),
        )?.days ?? 0
    );
}

const yearsPreview = [1, 2, 3, 4, 5, 6, 10, 15, 20, 25];

type ExtraRowForm = {
    from_year: number | null;
    to_year: number | null;
    days: number | null;
};
type PolicyForm = {
    name: string;
    extra_days: ExtraRowForm[];
    premium_percent: string;
    min_notice_days: number | null;
    allow_half_days: boolean;
    valid_from: string | null;
    valid_to: string | null;
    is_default: boolean;
};

const blank = (): PolicyForm => ({
    name: '',
    extra_days: [],
    premium_percent: props.legal_premium_percent,
    min_notice_days: 0,
    allow_half_days: false,
    valid_from: todayCivil(),
    valid_to: null,
    is_default: false,
});

const open = ref(false);
const editing = ref<VacationPolicyRow | null>(null);
const form = useForm<PolicyForm>(blank());

function create(): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(policy: VacationPolicyRow): void {
    editing.value = policy;
    form.defaults({
        name: policy.name,
        extra_days: policy.extra_days.map((r) => ({ ...r })),
        premium_percent: policy.premium_percent,
        min_notice_days: policy.min_notice_days,
        allow_half_days: policy.allow_half_days,
        valid_from: policy.valid_from,
        valid_to: policy.valid_to,
        is_default: policy.is_default,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function addRow(): void {
    const last = form.extra_days[form.extra_days.length - 1];
    const from = last ? (last.to_year ?? last.from_year ?? 0) + 1 : 1;
    if (last && last.to_year === null) {
        last.to_year = from - 1;
    }
    form.extra_days.push({ from_year: from, to_year: null, days: 2 });
}

function removeRow(index: number): void {
    form.extra_days.splice(index, 1);
}

const premiumBelowLegal = computed(
    () =>
        Number(form.premium_percent || 0) < Number(props.legal_premium_percent),
);

const formPreview = computed(() =>
    yearsPreview.map((year) => {
        const rows = form.extra_days
            .filter((r) => r.from_year !== null && r.days !== null)
            .map((r) => ({
                from_year: r.from_year ?? 0,
                to_year: r.to_year,
                days: r.days ?? 0,
            }));

        return { year, legal: legalDaysFor(year), extra: extraFor(rows, year) };
    }),
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.put(
            VacationPolicyController.update.url(editing.value.id),
            options,
        );
    } else {
        form.post(VacationPolicyController.store.url(), options);
    }
}

const errorOf = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const target = ref<VacationPolicyRow | null>(null);
const confirmOpen = ref(false);

function askDeactivate(policy: VacationPolicyRow): void {
    target.value = policy;
    confirmOpen.value = true;
}

function deactivate(): void {
    if (target.value) {
        router.post(
            VacationPolicyController.deactivate.url(target.value.id),
            {},
            { preserveScroll: true },
        );
    }
}

function restore(policy: VacationPolicyRow): void {
    router.post(
        VacationPolicyController.restore.url(policy.id),
        {},
        { preserveScroll: true },
    );
}

const help = [
    {
        title: 'Mínimo legal',
        text: 'Los días de ley (LFT art. 76) siempre se otorgan; aquí sólo sumas prestaciones superiores.',
        icon: Scale,
    },
    {
        title: 'Días adicionales',
        text: 'Define rangos de años de servicio con días extra.',
        icon: Sparkles,
    },
    {
        title: 'Prima vacacional',
        text: 'Nunca menor al mínimo legal (LFT art. 80).',
        icon: BadgePercent,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Vacaciones de ley y prestaciones superiores."
        :icon="Palmtree"
        :help="help"
        help-title="Políticas de vacaciones"
    />

    <SettingsNav />

    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <SectionCard title="Mínimo legal" :icon="Scale" divided padding="none">
            <template #actions>
                <StatusBadge
                    status="legal"
                    :label="`Prima ${legal_premium_percent} %`"
                    tone="info"
                    size="sm"
                    :dot="false"
                />
            </template>
            <ul class="divide-y">
                <li
                    v-for="row in legal_table"
                    :key="row.from_year"
                    class="flex items-center justify-between px-5 py-2.5 text-sm"
                >
                    <span class="text-muted-foreground">
                        {{
                            row.to_year === null
                                ? `${row.from_year}+ años`
                                : row.from_year === row.to_year
                                  ? `${row.from_year} ${row.from_year === 1 ? 'año' : 'años'}`
                                  : `${row.from_year}–${row.to_year} años`
                        }}
                    </span>
                    <span class="font-semibold tabular-nums"
                        >{{ row.days }} días</span
                    >
                </li>
            </ul>
            <p
                v-if="legal_table[0]"
                class="border-t px-5 py-3 text-xs text-muted-foreground"
            >
                {{ legal_table[0].source }}
            </p>
        </SectionCard>

        <div class="grid content-start gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display text-lg font-bold">Políticas</h2>
                <div class="flex items-center gap-2">
                    <InactiveToggle
                        v-model="showInactive"
                        :count="inactiveCount"
                    />
                    <Button v-if="can.manage" @click="create">
                        <Plus class="icon-sm" />
                        Nueva política
                    </Button>
                </div>
            </div>

            <EmptyState
                v-if="visible.length === 0"
                :icon="Palmtree"
                bordered
                class="rounded-2xl"
                title="Sin políticas"
                description="Se aplica el mínimo legal."
            />

            <article
                v-for="policy in visible"
                :key="policy.id"
                :class="
                    cn(
                        'rounded-2xl border bg-card p-5 shadow-elev-1',
                        policy.is_default && 'ring-1 ring-brand/40',
                        policy.deleted && 'opacity-70',
                    )
                "
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ policy.name }}</h3>
                            <StatusBadge
                                v-if="policy.is_default"
                                status="default"
                                label="Por defecto"
                                tone="brand"
                                size="sm"
                            />
                            <StatusBadge
                                v-if="policy.deleted"
                                status="inactive"
                                label="Inactiva"
                                size="sm"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ civilDate(policy.valid_from) }} –
                            {{
                                policy.valid_to
                                    ? civilDate(policy.valid_to)
                                    : 'vigente'
                            }}
                            · Prima {{ policy.premium_percent }} %
                            <template v-if="policy.min_notice_days">
                                · Aviso
                                {{ policy.min_notice_days }} días</template
                            >
                        </p>
                    </div>
                    <div v-if="can.manage" class="flex gap-1">
                        <template v-if="!policy.deleted">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="edit(policy)"
                            >
                                <Pencil class="icon-sm" />
                                Editar
                            </Button>
                            <Button
                                v-if="!policy.is_default"
                                variant="ghost"
                                size="sm"
                                @click="askDeactivate(policy)"
                            >
                                <Archive class="icon-sm" />
                                Desactivar
                            </Button>
                        </template>
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            @click="restore(policy)"
                        >
                            <RotateCcw class="icon-sm" />
                            Reactivar
                        </Button>
                    </div>
                </header>

                <div class="mt-4 grid grid-cols-5 gap-2 sm:grid-cols-10">
                    <div
                        v-for="year in yearsPreview"
                        :key="year"
                        class="rounded-xl bg-surface-2 px-2 py-2 text-center"
                    >
                        <p class="text-[0.6875rem] text-muted-foreground">
                            {{ year }} {{ year === 1 ? 'año' : 'años' }}
                        </p>
                        <p class="font-display text-lg font-bold tabular-nums">
                            {{
                                legalDaysFor(year) +
                                extraFor(policy.extra_days, year)
                            }}
                        </p>
                        <p
                            v-if="extraFor(policy.extra_days, year)"
                            class="text-[0.6875rem] font-medium text-success-soft-foreground"
                        >
                            +{{ extraFor(policy.extra_days, year) }}
                        </p>
                    </div>
                </div>
            </article>
        </div>
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-xl">
            <SheetHeader class="border-b">
                <SheetTitle>{{
                    editing ? 'Editar política' : 'Nueva política'
                }}</SheetTitle>
                <SheetDescription
                    >Días adicionales al mínimo legal.</SheetDescription
                >
            </SheetHeader>
            <form
                id="vacation-form"
                class="grid flex-1 gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    label="Nombre"
                    :error="form.errors.name"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        placeholder="Prestaciones superiores"
                    />
                </FormField>

                <fieldset class="grid gap-2">
                    <legend
                        class="mb-1 flex w-full items-center justify-between text-sm font-medium"
                    >
                        Días adicionales por antigüedad
                    </legend>
                    <div
                        v-for="(row, index) in form.extra_days"
                        :key="index"
                        class="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-2 rounded-xl border bg-surface-2/50 p-3"
                    >
                        <FormField
                            label="Desde año"
                            :error="errorOf(`extra_days.${index}.from_year`)"
                            v-slot="{ id }"
                        >
                            <NumberInput
                                :id="id"
                                v-model="row.from_year"
                                :min="1"
                                :max="60"
                            />
                        </FormField>
                        <FormField
                            label="Hasta año"
                            :error="errorOf(`extra_days.${index}.to_year`)"
                            v-slot="{ id }"
                        >
                            <NumberInput
                                :id="id"
                                v-model="row.to_year"
                                :min="1"
                                :max="60"
                            />
                        </FormField>
                        <FormField
                            label="Días extra"
                            :error="errorOf(`extra_days.${index}.days`)"
                            v-slot="{ id }"
                        >
                            <NumberInput
                                :id="id"
                                v-model="row.days"
                                :min="0"
                                :max="60"
                            />
                        </FormField>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="mb-0.5"
                            aria-label="Quitar rango"
                            @click="removeRow(index)"
                        >
                            <X class="icon-sm" />
                        </Button>
                    </div>
                    <p
                        v-if="errorOf('extra_days')"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ errorOf('extra_days') }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        @click="addRow"
                    >
                        <Plus class="icon-sm" />
                        Agregar rango
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        Deja «Hasta año» vacío para «en adelante».
                    </p>
                </fieldset>

                <div class="grid grid-cols-5 gap-1.5">
                    <div
                        v-for="item in formPreview"
                        :key="item.year"
                        class="rounded-lg bg-surface-2 px-1.5 py-1.5 text-center"
                    >
                        <p class="text-[0.625rem] text-muted-foreground">
                            {{ item.year }}a
                        </p>
                        <p class="text-sm font-bold tabular-nums">
                            {{ item.legal + item.extra }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Prima vacacional"
                        :error="
                            form.errors.premium_percent ||
                            (premiumBelowLegal
                                ? `Mínimo legal: ${legal_premium_percent} %`
                                : null)
                        "
                        required
                        v-slot="{ id }"
                    >
                        <div class="relative">
                            <Input
                                :id="id"
                                v-model="form.premium_percent"
                                inputmode="decimal"
                                class="pr-8 tabular-nums"
                            />
                            <span
                                class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                                >%</span
                            >
                        </div>
                    </FormField>
                    <FormField
                        label="Aviso mínimo"
                        :error="form.errors.min_notice_days"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form.min_notice_days"
                            :min="0"
                            :max="90"
                            suffix="días"
                        />
                    </FormField>
                    <FormField
                        label="Vigente desde"
                        :error="form.errors.valid_from"
                        required
                        v-slot="{ id }"
                    >
                        <DatePicker :id="id" v-model="form.valid_from" />
                    </FormField>
                    <FormField
                        label="Vigente hasta"
                        :error="form.errors.valid_to"
                        v-slot="{ id }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.valid_to"
                            :min="form.valid_from ?? undefined"
                            clearable
                            placeholder="Sin fin"
                        />
                    </FormField>
                </div>

                <div class="grid gap-2">
                    <ToggleRow
                        v-model="form.allow_half_days"
                        label="Permitir medios días"
                    />
                    <ToggleRow
                        v-model="form.is_default"
                        label="Política por defecto"
                        :disabled="editing?.is_default"
                    />
                    <p
                        v-if="form.errors.is_default"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ form.errors.is_default }}
                    </p>
                </div>
            </form>
            <SheetFooter class="border-t">
                <Button
                    type="submit"
                    form="vacation-form"
                    :disabled="form.processing || premiumBelowLegal"
                >
                    <Spinner v-if="form.processing" />
                    <Check v-else class="icon-sm" />
                    {{ editing ? 'Guardar cambios' : 'Crear política' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="confirmOpen"
        tone="danger"
        :title="`¿Desactivar «${target?.name ?? ''}»?`"
        description="Podrás reactivarla cuando lo necesites."
        confirm-label="Desactivar"
        @confirm="deactivate"
    />
</template>
