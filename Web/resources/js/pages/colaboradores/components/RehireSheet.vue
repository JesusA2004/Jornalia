<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserRoundPlus } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import EmploymentController from '@/actions/App/Http/Controllers/Web/Admin/EmploymentController';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import SelectField from '@/components/app/SelectField.vue';
import { todayIso } from '../lib';
import type {
    Assignment,
    AssignmentModel,
    EmployeeFormOptions,
} from '../types';
import AssignmentFields from './AssignmentFields.vue';
import FormSheet from './FormSheet.vue';

const props = defineProps<{
    employeeId: number;
    last: Assignment | null;
    lastEndsOn: string | null;
    options: EmployeeFormOptions;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    starts_on: todayIso() as string | null,
    seniority_starts_on: null as string | null,
    contract_type: 'indefinite' as string | null,
    work_modality: 'onsite' as string | null,
    legal_entity_id: null as string | null,
});

const assignment = reactive<AssignmentModel>({
    work_center_id: null,
    branch_id: null,
    department_id: null,
    position_id: null,
    supervisor_employee_id: null,
    schedule_id: null,
    attendance_policy_id: null,
});

const errors = computed(() => form.errors as Record<string, string>);

const minDate = computed(() => {
    if (!props.lastEndsOn) {
        return undefined;
    }

    const d = new Date(`${props.lastEndsOn}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + 1);

    return d.toISOString().slice(0, 10);
});

const str = (v: number | null | undefined) =>
    v === null || v === undefined ? null : String(v);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
    assignment.work_center_id = str(props.last?.work_center_id);
    assignment.branch_id = str(props.last?.branch_id);
    assignment.department_id = str(props.last?.department_id);
    assignment.position_id = str(props.last?.position_id);
    assignment.supervisor_employee_id = str(props.last?.supervisor_employee_id);
    assignment.schedule_id = str(props.last?.schedule_id);
    assignment.attendance_policy_id = str(props.last?.attendance_policy_id);
});

function submit(): void {
    form.transform((data) => ({ ...data, ...assignment })).submit(
        EmploymentController.rehire(props.employeeId),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <FormSheet
        v-model:open="open"
        title="Reingreso"
        description="Abre una nueva relación laboral; la anterior se conserva."
        :icon="UserRoundPlus"
        submit-label="Registrar reingreso"
        :processing="form.processing"
        :disabled="!form.starts_on || !assignment.branch_id"
        @submit="submit"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <FormField
                label="Fecha de reingreso"
                :error="errors.starts_on"
                required
                v-slot="{ id, invalid }"
            >
                <DatePicker
                    :id="id"
                    v-model="form.starts_on"
                    :min="minDate"
                    :invalid="invalid"
                />
            </FormField>
            <FormField
                label="Antigüedad desde"
                :error="errors.seniority_starts_on"
                hint="Opcional"
                v-slot="{ id, invalid }"
            >
                <DatePicker
                    :id="id"
                    v-model="form.seniority_starts_on"
                    :max="form.starts_on ?? undefined"
                    placeholder="Igual al reingreso"
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

        <div class="rounded-xl border bg-surface-2 p-4">
            <AssignmentFields
                v-model="assignment"
                :options="options"
                :errors="errors"
                branch-required
            />
        </div>
    </FormSheet>
</template>
