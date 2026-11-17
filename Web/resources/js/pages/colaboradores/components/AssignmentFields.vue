<script setup lang="ts">
import { computed, watch } from 'vue';
import ComboboxField from '@/components/app/ComboboxField.vue';
import FormField from '@/components/app/FormField.vue';
import SelectField from '@/components/app/SelectField.vue';
import OptionalTimeField from '@/pages/horarios/components/OptionalTimeField.vue';
import type { SelectOption } from '@/types';
import type {
    AssignmentField,
    AssignmentModel,
    EmployeeFormOptions,
} from '../types';

/**
 * Campos de asignación (sucursal, puesto, horario…). Se usa en alta,
 * reingreso y movimientos. `only` limita los campos visibles.
 */

const props = withDefaults(
    defineProps<{
        options: EmployeeFormOptions;
        errors?: Partial<Record<string, string>>;
        only?: AssignmentField[];
        branchRequired?: boolean;
    }>(),
    { errors: () => ({}), only: undefined, branchRequired: false },
);

const model = defineModel<AssignmentModel>({ required: true });

const show = (field: AssignmentField) =>
    !props.only || props.only.includes(field);

const positions = computed<SelectOption[]>(() => {
    const dept = model.value.department_id;

    if (!dept) {
        return props.options.positions;
    }

    const filtered = props.options.positions.filter(
        (p) => !p.department_id || p.department_id === dept,
    );

    return filtered.length ? filtered : props.options.positions;
});

// Al elegir sucursal, propone su centro de trabajo.
watch(
    () => model.value.branch_id,
    (branchId) => {
        const branch = props.options.branches.find((b) => b.value === branchId);

        if (branch?.work_center_id && show('work_center_id')) {
            model.value.work_center_id = branch.work_center_id;
        }
    },
);
</script>

<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <FormField
            v-if="show('branch_id')"
            label="Sucursal"
            :error="errors.branch_id"
            :required="branchRequired"
            v-slot="{ id, invalid }"
        >
            <ComboboxField
                :id="id"
                v-model="model.branch_id"
                :options="options.branches"
                placeholder="Selecciona la sucursal"
                search-placeholder="Buscar sucursal…"
                empty-text="No hay sucursales activas."
                :invalid="invalid"
                :clearable="!branchRequired"
            />
        </FormField>

        <FormField
            v-if="show('work_center_id')"
            label="Centro de trabajo"
            :error="errors.work_center_id"
            hint="Opcional"
            v-slot="{ id, invalid }"
        >
            <SelectField
                :id="id"
                v-model="model.work_center_id"
                :options="options.work_centers"
                placeholder="Según la sucursal"
                :invalid="invalid"
                clearable
            />
        </FormField>

        <FormField
            v-if="show('department_id')"
            label="Departamento"
            :error="errors.department_id"
            v-slot="{ id, invalid }"
        >
            <ComboboxField
                :id="id"
                v-model="model.department_id"
                :options="options.departments"
                placeholder="Sin departamento"
                :invalid="invalid"
                clearable
            />
        </FormField>

        <FormField
            v-if="show('position_id')"
            label="Puesto"
            :error="errors.position_id"
            v-slot="{ id, invalid }"
        >
            <ComboboxField
                :id="id"
                v-model="model.position_id"
                :options="positions"
                placeholder="Sin puesto"
                :invalid="invalid"
                clearable
            />
        </FormField>

        <FormField
            v-if="show('schedule_id')"
            label="Horario"
            :error="errors.schedule_id"
            v-slot="{ id, invalid }"
        >
            <ComboboxField
                :id="id"
                v-model="model.schedule_id"
                :options="options.schedules"
                placeholder="Sin horario"
                :invalid="invalid"
                clearable
            />
        </FormField>

        <FormField
            v-if="show('supervisor_employee_id')"
            label="Supervisor"
            :error="errors.supervisor_employee_id"
            v-slot="{ id, invalid }"
        >
            <ComboboxField
                :id="id"
                v-model="model.supervisor_employee_id"
                :options="options.supervisors"
                placeholder="Sin supervisor"
                search-placeholder="Buscar colaborador…"
                :invalid="invalid"
                clearable
            />
        </FormField>

        <FormField
            v-if="show('attendance_policy_id')"
            label="Política de asistencia"
            :error="errors.attendance_policy_id"
            class="sm:col-span-2"
            v-slot="{ id, invalid }"
        >
            <SelectField
                :id="id"
                v-model="model.attendance_policy_id"
                :options="options.attendance_policies"
                placeholder="Política por defecto"
                :invalid="invalid"
                clearable
            />
        </FormField>

        <FormField
            v-if="only?.includes('auto_clock_out_at')"
            label="Salida automática personal"
            description="Desactívala para heredar la del puesto o la política."
            :error="errors.auto_clock_out_at"
            class="sm:col-span-2"
            v-slot="{ id, invalid }"
        >
            <OptionalTimeField
                :id="id"
                :model-value="model.auto_clock_out_at ?? null"
                :invalid="invalid"
                off-label="Hereda de puesto o política"
                aria-label="Salida automática personal"
                @update:model-value="
                    (v: string | null) => (model.auto_clock_out_at = v)
                "
            />
        </FormField>
    </div>
</template>
