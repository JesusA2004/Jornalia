<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BriefcaseBusiness,
    Check,
    IdCard,
    MapPin,
    ShieldCheck,
    UserPlus,
    UserRound,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import KeyValueGrid from '@/components/app/KeyValueGrid.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Stepper,
    StepperDescription,
    StepperItem,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
} from '@/components/ui/stepper';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { HelpStep, KeyValueItem } from '@/types';
import AssignmentFields from './components/AssignmentFields.vue';
import { todayIso } from './lib';
import type { AssignmentModel, EmployeeCreateProps } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Nuevo' },
        ],
    },
});

const props = defineProps<EmployeeCreateProps>();

const step = ref(1);

const form = useForm({
    employee_number: props.suggested_number,
    first_name: '',
    last_name: '',
    second_last_name: '',
    email: '',
    phone: '',
    birth_date: null as string | null,
    curp: '',
    rfc: '',
    nss: '',
    starts_on: todayIso() as string | null,
    seniority_starts_on: null as string | null,
    contract_type: 'indefinite' as string | null,
    work_modality: 'onsite' as string | null,
    legal_entity_id: (props.options.legal_entities.length === 1
        ? props.options.legal_entities[0].value
        : null) as string | null,
});

const assignment = reactive<AssignmentModel>({
    work_center_id: null,
    branch_id:
        props.options.branches.length === 1
            ? props.options.branches[0].value
            : null,
    department_id: null,
    position_id: null,
    supervisor_employee_id: null,
    schedule_id: null,
    attendance_policy_id: null,
});

const errors = computed(() => form.errors as Record<string, string>);

const steps = [
    {
        step: 1,
        title: 'Datos personales',
        description: 'Identificación',
        icon: UserRound,
    },
    {
        step: 2,
        title: 'Relación laboral',
        description: 'Ingreso y contrato',
        icon: BriefcaseBusiness,
    },
    {
        step: 3,
        title: 'Asignación',
        description: 'Sucursal y horario',
        icon: MapPin,
    },
];

const STEP_FIELDS: Record<number, string[]> = {
    1: [
        'employee_number',
        'first_name',
        'last_name',
        'second_last_name',
        'email',
        'phone',
        'birth_date',
        'curp',
        'rfc',
        'nss',
    ],
    2: [
        'starts_on',
        'seniority_starts_on',
        'contract_type',
        'work_modality',
        'legal_entity_id',
    ],
    3: [
        'work_center_id',
        'branch_id',
        'department_id',
        'position_id',
        'supervisor_employee_id',
        'schedule_id',
        'attendance_policy_id',
    ],
};

const stepValid = computed<Record<number, boolean>>(() => ({
    1: Boolean(
        form.employee_number.trim() &&
        form.first_name.trim() &&
        form.last_name.trim(),
    ),
    2: Boolean(form.starts_on && form.contract_type && form.work_modality),
    3: Boolean(assignment.branch_id),
}));

function stepHasErrors(n: number): boolean {
    return STEP_FIELDS[n].some((field) => errors.value[field]);
}

function next(): void {
    if (stepValid.value[step.value] && step.value < 3) {
        step.value++;
    }
}

function label(
    options: { value: string; label: string }[],
    value: string | null,
): string | null {
    return options.find((o) => o.value === value)?.label ?? null;
}

const summary = computed<KeyValueItem[]>(() => [
    {
        key: 'name',
        label: 'Nombre',
        value: [form.first_name, form.last_name, form.second_last_name]
            .filter(Boolean)
            .join(' '),
    },
    { key: 'number', label: 'Número', value: form.employee_number, mono: true },
    {
        key: 'starts',
        label: 'Ingreso',
        value: form.starts_on ? formatDate(form.starts_on, 'date-long') : null,
    },
    {
        key: 'contract',
        label: 'Contrato',
        value: label(props.options.contract_types, form.contract_type),
    },
    {
        key: 'branch',
        label: 'Sucursal',
        value: label(props.options.branches, assignment.branch_id),
    },
    {
        key: 'schedule',
        label: 'Horario',
        value:
            label(props.options.schedules, assignment.schedule_id) ??
            'Sin horario',
    },
]);

function submit(): void {
    form.transform((data) => ({
        ...data,
        ...assignment,
        curp: props.can.sensitive ? data.curp : '',
        rfc: props.can.sensitive ? data.rfc : '',
        nss: props.can.sensitive ? data.nss : '',
    })).submit(EmployeeController.store(), {
        preserveScroll: true,
        onError: () => {
            const first = [1, 2, 3].find((n) => stepHasErrors(n));

            if (first) {
                step.value = first;
            }
        },
    });
}

const help: HelpStep[] = [
    {
        title: 'Datos mínimos',
        text: 'Sólo nombre y número son obligatorios. Evita capturar datos que no necesitas.',
        icon: UserRound,
    },
    {
        title: 'Identificadores oficiales',
        text: 'CURP, RFC y NSS se guardan cifrados y sólo los ve quien tiene permiso.',
        icon: ShieldCheck,
    },
    {
        title: 'Antigüedad',
        text: 'Si reconoces antigüedad previa, indícala: es la base de las vacaciones.',
        icon: BriefcaseBusiness,
    },
    {
        title: 'Horario legal',
        text: 'El horario se valida contra la jornada máxima vigente en la fecha de ingreso.',
        icon: MapPin,
    },
];
</script>

<template>
    <PageHeader
        title="Nuevo colaborador"
        description="Alta en tres pasos."
        :icon="UserPlus"
        :back-href="EmployeeController.index.url()"
        back-label="Colaboradores"
        :help="help"
        help-title="Alta de colaborador"
    />

    <div class="grid gap-6 lg:grid-cols-[17rem_minmax(0,1fr)]">
        <Stepper
            v-model="step"
            orientation="vertical"
            :linear="false"
            class="flex h-fit flex-row gap-2 overflow-x-auto rounded-2xl border bg-card p-3 shadow-elev-1 lg:sticky lg:top-20 lg:flex-col"
        >
            <StepperItem
                v-for="item in steps"
                :key="item.step"
                :step="item.step"
                :disabled="item.step > step && !stepValid[step]"
                class="relative w-full min-w-44 lg:min-w-0"
            >
                <StepperTrigger
                    :class="
                        cn(
                            'w-full flex-row items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-standard hover:bg-accent',
                            step === item.step && 'bg-brand-soft',
                        )
                    "
                >
                    <span
                        :class="
                            cn(
                                'flex size-9 shrink-0 items-center justify-center rounded-full border text-sm font-bold',
                                step === item.step &&
                                    'border-transparent bg-primary text-primary-foreground',
                                step > item.step &&
                                    'border-transparent bg-success-soft text-success-soft-foreground',
                                stepHasErrors(item.step) &&
                                    'border-transparent bg-danger-soft text-danger-soft-foreground',
                            )
                        "
                    >
                        <Check
                            v-if="step > item.step && !stepHasErrors(item.step)"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <component
                            :is="item.icon"
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="min-w-0">
                        <StepperTitle class="block text-sm">{{
                            item.title
                        }}</StepperTitle>
                        <StepperDescription
                            class="block text-xs text-muted-foreground"
                        >
                            {{ item.description }}
                        </StepperDescription>
                    </span>
                </StepperTrigger>
                <StepperSeparator
                    v-if="item.step < steps.length"
                    class="absolute top-[3.1rem] left-[1.85rem] hidden h-3 w-px lg:block"
                />
            </StepperItem>
        </Stepper>

        <form
            class="grid gap-6"
            novalidate
            @submit.prevent="step === 3 ? submit() : next()"
        >
            <SectionCard
                v-show="step === 1"
                title="Datos personales"
                description="Lo mínimo para identificar a la persona."
                :icon="UserRound"
                divided
            >
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Nombre(s)"
                        :error="errors.first_name"
                        required
                        v-slot="{ id, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.first_name"
                            autocomplete="off"
                            :aria-invalid="invalid || undefined"
                            v-focus
                        />
                    </FormField>
                    <FormField
                        label="Número de empleado"
                        :error="errors.employee_number"
                        required
                        v-slot="{ id, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.employee_number"
                            class="font-mono"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Apellido paterno"
                        :error="errors.last_name"
                        required
                        v-slot="{ id, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.last_name"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Apellido materno"
                        :error="errors.second_last_name"
                        hint="Opcional"
                        v-slot="{ id, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.second_last_name"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Correo"
                        :error="errors.email"
                        hint="Opcional"
                        v-slot="{ id, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.email"
                            type="email"
                            inputmode="email"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Teléfono"
                        :error="errors.phone"
                        hint="Opcional"
                        v-slot="{ id, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.phone"
                            type="tel"
                            inputmode="tel"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Fecha de nacimiento"
                        :error="errors.birth_date"
                        hint="Opcional"
                        description="Sólo para reglas de menores de edad."
                        v-slot="{ id, invalid }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.birth_date"
                            :max="todayIso()"
                            dropdowns
                            clearable
                            :invalid="invalid"
                        />
                    </FormField>
                </div>

                <div
                    v-if="can.sensitive"
                    class="mt-6 rounded-xl border border-dashed bg-surface-2 p-4"
                >
                    <p
                        class="mb-4 flex items-center gap-2 text-sm font-semibold"
                    >
                        <IdCard
                            class="size-4 text-brand-strong"
                            aria-hidden="true"
                        />
                        Identificadores oficiales
                        <span class="text-xs font-normal text-muted-foreground"
                            >· cifrados</span
                        >
                    </p>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <FormField
                            label="CURP"
                            :error="errors.curp"
                            v-slot="{ id, invalid }"
                        >
                            <Input
                                :id="id"
                                v-model="form.curp"
                                maxlength="18"
                                class="font-mono uppercase"
                                autocomplete="off"
                                :aria-invalid="invalid || undefined"
                            />
                        </FormField>
                        <FormField
                            label="RFC"
                            :error="errors.rfc"
                            v-slot="{ id, invalid }"
                        >
                            <Input
                                :id="id"
                                v-model="form.rfc"
                                maxlength="13"
                                class="font-mono uppercase"
                                autocomplete="off"
                                :aria-invalid="invalid || undefined"
                            />
                        </FormField>
                        <FormField
                            label="NSS"
                            :error="errors.nss"
                            v-slot="{ id, invalid }"
                        >
                            <Input
                                :id="id"
                                v-model="form.nss"
                                maxlength="11"
                                inputmode="numeric"
                                class="font-mono"
                                autocomplete="off"
                                :aria-invalid="invalid || undefined"
                            />
                        </FormField>
                    </div>
                </div>
            </SectionCard>

            <SectionCard
                v-show="step === 2"
                title="Relación laboral"
                description="Fecha de ingreso y condiciones."
                :icon="BriefcaseBusiness"
                divided
            >
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Fecha de ingreso"
                        :error="errors.starts_on"
                        required
                        v-slot="{ id, invalid }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.starts_on"
                            dropdowns
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        label="Antigüedad reconocida desde"
                        :error="errors.seniority_starts_on"
                        hint="Opcional"
                        v-slot="{ id, invalid }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.seniority_starts_on"
                            :max="form.starts_on ?? undefined"
                            placeholder="Igual al ingreso"
                            dropdowns
                            clearable
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        label="Tipo de contrato"
                        :error="errors.contract_type"
                        required
                        v-slot="{ id, invalid }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.contract_type"
                            :options="options.contract_types"
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        label="Modalidad"
                        :error="errors.work_modality"
                        required
                        v-slot="{ id, invalid }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.work_modality"
                            :options="options.work_modalities"
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        v-if="options.legal_entities.length"
                        label="Razón social"
                        :error="errors.legal_entity_id"
                        class="sm:col-span-2"
                        v-slot="{ id, invalid }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.legal_entity_id"
                            :options="options.legal_entities"
                            placeholder="Selecciona el patrón"
                            :invalid="invalid"
                            clearable
                        />
                    </FormField>
                </div>
            </SectionCard>

            <template v-if="step === 3">
                <SectionCard
                    title="Asignación"
                    description="Dónde y con qué horario trabajará."
                    :icon="MapPin"
                    divided
                >
                    <AssignmentFields
                        v-model="assignment"
                        :options="options"
                        :errors="errors"
                        branch-required
                    />
                </SectionCard>
                <SectionCard title="Resumen" variant="muted" padding="sm">
                    <KeyValueGrid :items="summary" :columns="3" />
                </SectionCard>
            </template>

            <div class="flex items-center justify-between gap-3">
                <Button
                    v-if="step > 1"
                    type="button"
                    variant="ghost"
                    @click="step--"
                >
                    <ArrowLeft aria-hidden="true" />
                    Anterior
                </Button>
                <span v-else />
                <Button
                    v-if="step < 3"
                    type="submit"
                    :disabled="!stepValid[step]"
                >
                    Continuar
                    <ArrowRight aria-hidden="true" />
                </Button>
                <Button
                    v-else
                    type="submit"
                    size="lg"
                    :disabled="form.processing || !stepValid[3]"
                >
                    <Spinner v-if="form.processing" />
                    <UserPlus v-else aria-hidden="true" />
                    Dar de alta
                </Button>
            </div>
        </form>
    </div>
</template>
