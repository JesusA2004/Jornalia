<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowRightLeft } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import EmploymentController from '@/actions/App/Http/Controllers/Web/Admin/EmploymentController';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { todayIso } from '../lib';
import type {
    Assignment,
    AssignmentField,
    AssignmentModel,
    EmployeeFormOptions,
} from '../types';
import AssignmentFields from './AssignmentFields.vue';
import FormSheet from './FormSheet.vue';

const props = defineProps<{
    employeeId: number;
    current: Assignment | null;
    options: EmployeeFormOptions;
    /** Sin permiso de edición sólo se permite cambiar el horario. */
    scheduleOnly: boolean;
    /** Campo preseleccionado al abrir. */
    preset?: AssignmentField | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const FIELD_LABELS: Record<AssignmentField, string> = {
    branch_id: 'Sucursal',
    work_center_id: 'Centro de trabajo',
    department_id: 'Departamento',
    position_id: 'Puesto',
    schedule_id: 'Horario',
    supervisor_employee_id: 'Supervisor',
    attendance_policy_id: 'Política de asistencia',
};

const available = computed<AssignmentField[]>(() =>
    props.scheduleOnly
        ? ['schedule_id']
        : [
              'branch_id',
              'position_id',
              'department_id',
              'schedule_id',
              'supervisor_employee_id',
              'work_center_id',
              'attendance_policy_id',
          ],
);

const form = useForm({
    effective_from: todayIso() as string | null,
    reason: '',
    fields: [] as AssignmentField[],
});

const values = reactive<AssignmentModel>({
    work_center_id: null,
    branch_id: null,
    department_id: null,
    position_id: null,
    supervisor_employee_id: null,
    schedule_id: null,
    attendance_policy_id: null,
});

const errors = computed(() => form.errors as Record<string, string>);

const str = (v: number | null | undefined) =>
    v === null || v === undefined ? null : String(v);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
    form.fields = props.scheduleOnly ? ['schedule_id'] : [];
    values.work_center_id = str(props.current?.work_center_id);
    values.branch_id = str(props.current?.branch_id);
    values.department_id = str(props.current?.department_id);
    values.position_id = str(props.current?.position_id);
    values.supervisor_employee_id = str(props.current?.supervisor_employee_id);
    values.schedule_id = str(props.current?.schedule_id);
    values.attendance_policy_id = str(props.current?.attendance_policy_id);
    if (props.preset) {
        form.fields = [props.preset];
    }
});

function toggle(
    field: AssignmentField,
    checked: boolean | 'indeterminate',
): void {
    form.fields =
        checked === true
            ? [...form.fields.filter((f) => f !== field), field]
            : form.fields.filter((f) => f !== field);
}

function submit(): void {
    form.transform((data) => ({ ...data, ...values })).submit(
        EmploymentController.move(props.employeeId),
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
        title="Registrar movimiento"
        description="Nueva vigencia; la anterior se cierra el día previo."
        :icon="ArrowRightLeft"
        submit-label="Registrar movimiento"
        :processing="form.processing"
        :disabled="
            form.fields.length === 0 ||
            !form.effective_from ||
            form.reason.trim().length < 3
        "
        @submit="submit"
    >
        <FormField
            label="Vigente a partir de"
            :error="errors.effective_from"
            required
            v-slot="{ id, invalid }"
        >
            <DatePicker
                :id="id"
                v-model="form.effective_from"
                :min="todayIso()"
                :invalid="invalid"
            />
        </FormField>

        <FormField
            v-if="!scheduleOnly"
            label="¿Qué cambia?"
            :error="errors.fields"
            required
        >
            <div class="grid grid-cols-2 gap-2">
                <Label
                    v-for="field in available"
                    :key="field"
                    :class="
                        cn(
                            'flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm font-medium transition-standard hover:bg-accent',
                            form.fields.includes(field) &&
                                'border-brand/40 bg-brand-soft',
                        )
                    "
                >
                    <Checkbox
                        :model-value="form.fields.includes(field)"
                        @update:model-value="(v) => toggle(field, v)"
                    />
                    {{ FIELD_LABELS[field] }}
                </Label>
            </div>
        </FormField>

        <div
            v-if="form.fields.length"
            class="rounded-xl border bg-surface-2 p-4"
        >
            <AssignmentFields
                v-model="values"
                :options="options"
                :errors="errors"
                :only="form.fields"
            />
        </div>

        <FormField
            label="Motivo"
            :error="errors.reason"
            required
            v-slot="{ id, invalid }"
        >
            <Textarea
                :id="id"
                v-model="form.reason"
                rows="3"
                placeholder="Promoción, cambio de turno, reubicación…"
                :aria-invalid="invalid || undefined"
            />
        </FormField>
    </FormSheet>
</template>
