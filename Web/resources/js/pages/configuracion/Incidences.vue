<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Check,
    FileCheck2,
    Lock,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import IncidenceTypeController from '@/actions/App/Http/Controllers/Web/Admin/IncidenceTypeController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import InactiveToggle from './components/InactiveToggle.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import { civilDate, todayCivil } from './format';
import type { IncidenceTypeRow, ManageCan, Option } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Incidencias' },
        ],
    },
});

const props = defineProps<{
    types: IncidenceTypeRow[];
    categories: Option[];
    effects: Option[];
    can: ManageCan;
}>();

const search = ref('');
const category = ref<string | null>(null);
const showInactive = ref(false);
const inactiveCount = computed(
    () => props.types.filter((t) => t.deleted).length,
);

const rows = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.types.filter(
        (t) =>
            (showInactive.value || !t.deleted) &&
            (!category.value || t.category === category.value) &&
            (!term ||
                t.name.toLowerCase().includes(term) ||
                t.code.toLowerCase().includes(term)),
    );
});

const label = (list: Option[], value: string) =>
    list.find((o) => o.value === value)?.label ?? value;

type TypeForm = {
    code: string;
    name: string;
    category: string | null;
    description: string;
    operational_effect: string | null;
    requires_evidence: boolean;
    requires_approval: boolean;
    affects_payroll: boolean;
    employee_requestable: boolean;
    active: boolean;
    valid_from: string | null;
    valid_to: string | null;
};

const blank = (): TypeForm => ({
    code: '',
    name: '',
    category: 'leave',
    description: '',
    operational_effect: 'justified',
    requires_evidence: false,
    requires_approval: true,
    affects_payroll: false,
    employee_requestable: true,
    active: true,
    valid_from: todayCivil(),
    valid_to: null,
});

const open = ref(false);
const editing = ref<IncidenceTypeRow | null>(null);
const form = useForm<TypeForm>(blank());
/** Error general del tipo (clave fuera del formulario tipado). */
const typeError = computed(
    () => (form.errors as Record<string, string | undefined>).type,
);

function create(): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(type: IncidenceTypeRow): void {
    editing.value = type;
    form.defaults({
        code: type.code,
        name: type.name,
        category: type.category,
        description: type.description ?? '',
        operational_effect: type.operational_effect,
        requires_evidence: type.requires_evidence,
        requires_approval: type.requires_approval,
        affects_payroll: type.affects_payroll,
        employee_requestable: type.employee_requestable,
        active: type.active,
        valid_from: type.valid_from ?? todayCivil(),
        valid_to: type.valid_to,
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

    if (editing.value) {
        form.put(IncidenceTypeController.update.url(editing.value.id), options);
    } else {
        form.post(IncidenceTypeController.store.url(), options);
    }
}

const target = ref<IncidenceTypeRow | null>(null);
const confirmOpen = ref(false);

function askDeactivate(type: IncidenceTypeRow): void {
    target.value = type;
    confirmOpen.value = true;
}

function deactivate(): void {
    if (target.value) {
        router.post(
            IncidenceTypeController.deactivate.url(target.value.id),
            {},
            { preserveScroll: true },
        );
    }
}

function restore(type: IncidenceTypeRow): void {
    router.post(
        IncidenceTypeController.restore.url(type.id),
        {},
        { preserveScroll: true },
    );
}

const help = [
    {
        title: 'Del sistema',
        text: 'Las marcadas con candado las usa el cálculo de asistencia; son de sólo lectura.',
        icon: Lock,
    },
    {
        title: 'Vigencia',
        text: 'Cada tipo nuevo se registra con fecha de inicio; desactivarlo cierra su vigencia.',
        icon: FileCheck2,
    },
    {
        title: 'Efecto',
        text: 'Indica cómo impacta en la asistencia y si se reporta a nómina.',
        icon: TriangleAlert,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Catálogo de incidencias de la empresa."
        :icon="TriangleAlert"
        :help="help"
        help-title="Catálogo de incidencias"
    />

    <SettingsNav />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <SearchInput
            v-model="search"
            placeholder="Buscar incidencia…"
            class="w-full sm:w-64"
        />
        <SelectField
            v-model="category"
            :options="categories"
            placeholder="Categoría"
            prefix="Categoría:"
            clearable
            size="sm"
            class="w-48"
        />
        <div class="ml-auto flex items-center gap-2">
            <InactiveToggle v-model="showInactive" :count="inactiveCount" />
            <Button v-if="can.manage" @click="create">
                <Plus class="icon-sm" />
                Nueva incidencia
            </Button>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border bg-card shadow-elev-1">
        <Table v-if="rows.length">
            <TableHeader>
                <TableRow>
                    <TableHead>Incidencia</TableHead>
                    <TableHead class="hidden md:table-cell"
                        >Categoría</TableHead
                    >
                    <TableHead class="hidden lg:table-cell">Efecto</TableHead>
                    <TableHead class="hidden xl:table-cell">Reglas</TableHead>
                    <TableHead class="hidden md:table-cell">Vigencia</TableHead>
                    <TableHead class="w-28 text-right"
                        ><span class="sr-only">Acciones</span></TableHead
                    >
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="type in rows"
                    :key="type.id"
                    :class="cn(type.deleted && 'opacity-60')"
                >
                    <TableCell>
                        <div class="flex items-center gap-2">
                            <Lock
                                v-if="type.is_system"
                                class="icon-xs shrink-0 text-muted-foreground"
                                aria-label="Del sistema"
                            />
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ type.name }}
                                </p>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ type.code }}
                                </p>
                            </div>
                        </div>
                    </TableCell>
                    <TableCell class="hidden md:table-cell">{{
                        label(categories, type.category)
                    }}</TableCell>
                    <TableCell class="hidden lg:table-cell">{{
                        label(effects, type.operational_effect)
                    }}</TableCell>
                    <TableCell class="hidden xl:table-cell">
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-if="type.requires_approval"
                                class="rounded-full bg-surface-3 px-2 py-0.5 text-xs"
                                >Aprobación</span
                            >
                            <span
                                v-if="type.requires_evidence"
                                class="rounded-full bg-surface-3 px-2 py-0.5 text-xs"
                                >Evidencia</span
                            >
                            <span
                                v-if="type.affects_payroll"
                                class="rounded-full bg-surface-3 px-2 py-0.5 text-xs"
                                >Nómina</span
                            >
                            <span
                                v-if="type.employee_requestable"
                                class="rounded-full bg-surface-3 px-2 py-0.5 text-xs"
                                >App</span
                            >
                        </div>
                    </TableCell>
                    <TableCell class="hidden text-sm md:table-cell">
                        <template v-if="type.valid_from || type.valid_to">
                            {{ civilDate(type.valid_from) }} –
                            {{
                                type.valid_to
                                    ? civilDate(type.valid_to)
                                    : 'vigente'
                            }}
                        </template>
                        <span v-else class="text-muted-foreground"
                            >Permanente</span
                        >
                    </TableCell>
                    <TableCell class="text-right">
                        <StatusBadge
                            v-if="type.deleted"
                            status="inactive"
                            label="Inactiva"
                            size="sm"
                            class="mr-1"
                        />
                        <template v-if="can.manage && !type.is_system">
                            <Button
                                v-if="type.deleted"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Reactivar"
                                @click="restore(type)"
                            >
                                <RotateCcw class="icon-sm" />
                            </Button>
                            <template v-else>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Editar"
                                    @click="edit(type)"
                                >
                                    <Pencil class="icon-sm" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Desactivar"
                                    @click="askDeactivate(type)"
                                >
                                    <Archive class="icon-sm" />
                                </Button>
                            </template>
                        </template>
                        <StatusBadge
                            v-else-if="type.is_system"
                            status="system"
                            label="Sistema"
                            tone="neutral"
                            size="sm"
                            :dot="false"
                        />
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
        <EmptyState
            v-else
            :icon="Search"
            title="Sin incidencias"
            description="No hay tipos que coincidan con el filtro."
            size="sm"
        />
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-lg">
            <SheetHeader class="border-b">
                <SheetTitle>{{
                    editing ? 'Editar incidencia' : 'Nueva incidencia'
                }}</SheetTitle>
                <SheetDescription
                    >Disponible a partir de su vigencia.</SheetDescription
                >
            </SheetHeader>
            <form
                id="incidence-form"
                class="grid flex-1 gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <div class="grid gap-5 sm:grid-cols-[8rem_minmax(0,1fr)]">
                    <FormField
                        label="Clave"
                        :error="form.errors.code"
                        required
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="form.code"
                            class="font-mono uppercase"
                            placeholder="CAPACITACION"
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
                            placeholder="Capacitación externa"
                        />
                    </FormField>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Categoría"
                        :error="form.errors.category"
                        required
                        v-slot="{ id }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.category"
                            :options="categories"
                        />
                    </FormField>
                    <FormField
                        label="Efecto"
                        :error="form.errors.operational_effect"
                        required
                        v-slot="{ id }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.operational_effect"
                            :options="effects"
                        />
                    </FormField>
                </div>
                <FormField
                    label="Descripción"
                    :error="form.errors.description"
                    v-slot="{ id }"
                >
                    <Textarea :id="id" v-model="form.description" rows="2" />
                </FormField>
                <div class="grid gap-2">
                    <ToggleRow
                        v-model="form.requires_approval"
                        label="Requiere aprobación"
                    />
                    <ToggleRow
                        v-model="form.requires_evidence"
                        label="Requiere evidencia"
                    />
                    <ToggleRow
                        v-model="form.affects_payroll"
                        label="Afecta nómina"
                    />
                    <ToggleRow
                        v-model="form.employee_requestable"
                        label="Solicitable desde la app"
                    />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
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
                <p v-if="typeError" class="text-xs text-danger-soft-foreground">
                    {{ typeError }}
                </p>
            </form>
            <SheetFooter class="border-t">
                <Button
                    type="submit"
                    form="incidence-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Check v-else class="icon-sm" />
                    {{ editing ? 'Guardar cambios' : 'Agregar al catálogo' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="confirmOpen"
        tone="danger"
        :title="`¿Desactivar «${target?.name ?? ''}»?`"
        description="Se cierra su vigencia; las incidencias registradas se conservan."
        confirm-label="Desactivar"
        @confirm="deactivate"
    />
</template>
