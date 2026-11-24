<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    CalendarClock,
    FilePen,
    Lock,
    Pencil,
    RefreshCcw,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import IncidenceTypeController from '@/actions/App/Http/Controllers/Web/Admin/IncidenceTypeController';
import DatePicker from '@/components/app/DatePicker.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
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
import type { HelpStep } from '@/types';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import type {
    IncidenceTypeRow,
    Option,
    PeopleIncidenceStatus,
    RectificationParameters,
} from './types';

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
    evidence_kinds: Option[];
    approval_routes: Option[];
    rectifications: RectificationParameters;
    people: PeopleIncidenceStatus;
    can: { manage: boolean; sync: boolean };
}>();

const label = (list: Option[], value: string | null) =>
    list.find((o) => o.value === value)?.label ?? '—';

/* ── Edición de un tipo (sólo campos configurables) ── */
type TypeForm = {
    requires_evidence: boolean;
    evidence_kind: string | null;
    requires_folio: boolean;
    approval_route: string | null;
    employee_requestable: boolean;
    external_code: string;
};

const open = ref(false);
const editing = ref<IncidenceTypeRow | null>(null);
const form = useForm<TypeForm>({
    requires_evidence: true,
    evidence_kind: null,
    requires_folio: false,
    approval_route: 'manager_hr',
    employee_requestable: true,
    external_code: '',
});

function edit(type: IncidenceTypeRow): void {
    editing.value = type;
    form.defaults({
        requires_evidence: type.requires_evidence,
        evidence_kind: type.evidence_kind,
        requires_folio: type.requires_folio,
        approval_route: type.approval_route,
        employee_requestable: type.employee_requestable,
        external_code: type.external_code ?? '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save(): void {
    if (!editing.value) {
        return;
    }
    form.transform((d) => ({
        ...d,
        external_code: d.external_code.trim() || null,
    })).put(IncidenceTypeController.update.url(editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

function toggle(type: IncidenceTypeRow): void {
    router.post(
        type.active
            ? IncidenceTypeController.deactivate.url(type.id)
            : IncidenceTypeController.restore.url(type.id),
        {},
        { preserveScroll: true },
    );
}

/* ── Parámetros de rectificaciones ── */
const params = useForm<RectificationParameters>({ ...props.rectifications });

function saveParams(): void {
    params.put(IncidenceTypeController.updateRectifications.url(), {
        preserveScroll: true,
    });
}

const syncing = ref(false);

function syncPeople(): void {
    router.post(
        IncidenceTypeController.syncPeople.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => (syncing.value = false),
        },
    );
}

const help: HelpStep[] = [
    {
        title: 'Catálogo cerrado',
        text: 'Los tipos no se agregan ni se borran. RH ajusta evidencia, folio, quién autoriza y el código de People.',
        icon: Lock,
    },
    {
        title: 'Falta injustificada',
        text: 'No se captura: la determina el sistema cuando no hay marcaje ni justificación aprobada.',
        icon: TriangleAlert,
    },
    {
        title: 'Plazo de firma',
        text: 'Días hábiles que tiene el colaborador para firmar una rectificación. Vencido, se rechaza y el día queda como omisión.',
        icon: CalendarClock,
    },
    {
        title: 'Cierre de nómina',
        text: 'Las rectificaciones de fechas ya cerradas son extemporáneas: sólo RH con permiso las aplica.',
        icon: ShieldCheck,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Catálogo de incidencias y parámetros de rectificaciones."
        :icon="TriangleAlert"
        :help="help"
        help-title="Incidencias y rectificaciones"
    />

    <SettingsNav />

    <div class="grid gap-5">
        <SectionCard title="Catálogo de incidencias" :icon="Lock" divided>
            <div class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Tipo</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Evidencia</TableHead
                            >
                            <TableHead>Autoriza</TableHead>
                            <TableHead class="hidden lg:table-cell"
                                >Código People</TableHead
                            >
                            <TableHead>Estado</TableHead>
                            <TableHead v-if="can.manage" class="w-24" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="type in types" :key="type.id">
                            <TableCell>
                                <div class="grid">
                                    <span class="font-medium">{{
                                        type.name
                                    }}</span>
                                    <span class="text-xs text-muted-foreground"
                                        >{{ type.category_label }} ·
                                        {{ type.incidences_count }}
                                        registradas</span
                                    >
                                </div>
                            </TableCell>
                            <TableCell class="hidden text-sm md:table-cell">
                                <template v-if="type.requires_evidence">
                                    {{
                                        label(
                                            evidence_kinds,
                                            type.evidence_kind,
                                        )
                                    }}
                                    <span
                                        v-if="type.requires_folio"
                                        class="text-xs text-muted-foreground"
                                    >
                                        + folio</span
                                    >
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >No requerida</span
                                >
                            </TableCell>
                            <TableCell class="text-sm">
                                <span
                                    v-if="type.is_omission"
                                    class="inline-flex items-center gap-1"
                                >
                                    <FilePen
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Rectificación
                                </span>
                                <template v-else>{{
                                    label(approval_routes, type.approval_route)
                                }}</template>
                            </TableCell>
                            <TableCell
                                class="hidden font-mono text-xs lg:table-cell"
                                >{{ type.external_code ?? '—' }}</TableCell
                            >
                            <TableCell>
                                <StatusBadge
                                    :status="
                                        type.active ? 'active' : 'inactive'
                                    "
                                    :label="type.active ? 'Activa' : 'Inactiva'"
                                    :tone="type.active ? 'success' : 'neutral'"
                                    size="sm"
                                />
                            </TableCell>
                            <TableCell v-if="can.manage" class="text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Editar ${type.name}`"
                                        @click="edit(type)"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="toggle(type)"
                                    >
                                        {{
                                            type.active
                                                ? 'Desactivar'
                                                : 'Activar'
                                        }}
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </SectionCard>

        <div class="grid gap-5 lg:grid-cols-2">
            <SectionCard title="Rectificaciones" :icon="CalendarClock" divided>
                <form class="grid gap-4" @submit.prevent="saveParams">
                    <FormField
                        label="Plazo de firma (días hábiles)"
                        required
                        :error="params.errors.business_days"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model.number="params.business_days"
                            type="number"
                            min="1"
                            max="15"
                            class="w-28"
                            :disabled="!can.manage"
                        />
                    </FormField>
                    <ToggleRow
                        v-model="params.confirmed_by_hr"
                        label="Plazo confirmado por RH"
                        description="Valor inicial propuesto: 3 días hábiles."
                        :disabled="!can.manage"
                    />
                    <FormField
                        label="Nómina cerrada hasta"
                        hint="Opcional"
                        :error="params.errors.closed_through"
                        v-slot="{ id }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="params.closed_through"
                            :disabled="!can.manage"
                        />
                    </FormField>
                    <div v-if="can.manage">
                        <Button type="submit" :disabled="params.processing">
                            <Spinner v-if="params.processing" />
                            Guardar
                        </Button>
                    </div>
                </form>
            </SectionCard>

            <SectionCard title="Mr. Lana People" :icon="RefreshCcw" divided>
                <div class="grid gap-3 text-sm">
                    <StatusBadge
                        class="justify-self-start"
                        :status="people.configured ? 'active' : 'inactive'"
                        :label="
                            people.configured ? 'Configurada' : 'Sin configurar'
                        "
                        :tone="people.configured ? 'success' : 'neutral'"
                    />
                    <p class="text-muted-foreground">
                        Recibe permisos, incapacidades y vacaciones aprobados.
                    </p>
                    <p v-if="people.last_success_at" class="text-xs">
                        Última sincronización:
                        <DateTimeDisplay
                            :value="people.last_success_at"
                            format="datetime"
                        />
                    </p>
                    <div v-if="can.sync && people.configured">
                        <Button
                            variant="outline"
                            :disabled="syncing"
                            @click="syncPeople"
                        >
                            <Spinner v-if="syncing" />
                            <RefreshCcw v-else aria-hidden="true" />
                            Sincronizar ahora
                        </Button>
                    </div>
                </div>
            </SectionCard>
        </div>
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-md">
            <SheetHeader class="border-b p-5">
                <SheetTitle class="font-display text-lg">{{
                    editing?.name
                }}</SheetTitle>
                <SheetDescription
                    >Catálogo cerrado: sólo ajustes.</SheetDescription
                >
            </SheetHeader>
            <form
                id="incidence-type-form"
                class="grid flex-1 content-start gap-4 overflow-y-auto p-5"
                @submit.prevent="save"
            >
                <ToggleRow
                    v-model="form.requires_evidence"
                    label="Evidencia obligatoria"
                    :disabled="editing?.is_omission"
                />
                <FormField
                    v-if="form.requires_evidence"
                    label="Tipo de evidencia"
                    required
                    :error="form.errors.evidence_kind"
                >
                    <SelectField
                        v-model="form.evidence_kind"
                        :options="evidence_kinds"
                        placeholder="Selecciona"
                    />
                </FormField>
                <ToggleRow
                    v-if="!editing?.is_omission"
                    v-model="form.requires_folio"
                    label="Folio IMSS obligatorio"
                />
                <FormField
                    v-if="!editing?.is_omission"
                    label="Autoriza"
                    required
                    :error="form.errors.approval_route"
                >
                    <SelectField
                        v-model="form.approval_route"
                        :options="approval_routes"
                    />
                </FormField>
                <ToggleRow
                    v-model="form.employee_requestable"
                    label="El colaborador puede solicitarla"
                />
                <FormField
                    label="Código en People"
                    hint="Opcional"
                    :error="form.errors.external_code"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.external_code"
                        maxlength="40"
                        class="font-mono"
                    />
                </FormField>
            </form>
            <SheetFooter class="border-t p-5">
                <Button
                    type="submit"
                    form="incidence-type-form"
                    class="w-full"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
