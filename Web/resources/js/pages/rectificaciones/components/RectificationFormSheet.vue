<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FilePlus2, Send } from '@lucide/vue';
import { computed, watch } from 'vue';
import RectificationController from '@/actions/App/Http/Controllers/Web/Operacion/RectificationController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import FileDropzone from '@/components/app/FileDropzone.vue';
import FormField from '@/components/app/FormField.vue';
import SelectField from '@/components/app/SelectField.vue';
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
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types';
import DayPunchPicker from './DayPunchPicker.vue';
import TimeField from './TimeField.vue';

/** Alta de rectificación a nombre de un colaborador (permiso rectifications.create). */
const props = defineProps<{
    employees: SelectOption[];
    kinds: SelectOption[];
    punchTypes: SelectOption[];
    today: string;
    initialEmployeeId?: string | null;
    initialDate?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    employee_id: props.initialEmployeeId ?? (null as string | null),
    date: props.initialDate ?? (props.today as string | null),
    kind: 'missing_punch' as string | null,
    punch_uuid: '',
    proposed_type: 'clock_in' as string | null,
    proposed_time: null as string | null,
    reason: '',
    evidence: [] as File[],
});

watch(
    () => [props.initialEmployeeId, props.initialDate],
    ([employee, date]) => {
        if (employee) {
            form.employee_id = employee;
        }
        if (date) {
            form.date = date;
        }
    },
);

const needsPunch = computed(
    () => form.kind === 'wrong_type' || form.kind === 'time_clarification',
);
const needsType = computed(() => form.kind !== 'time_clarification');
const needsTime = computed(() => form.kind !== 'wrong_type');

const evidenceError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.evidence ??
        Object.entries(errors).find(([k]) => k.startsWith('evidence.'))?.[1] ??
        null
    );
});

function submit(): void {
    if (form.evidence.length === 0) {
        form.setError(
            'evidence',
            'Adjunta la evidencia (foto, documento o constancia).',
        );

        return;
    }
    form.transform((data) => ({
        employee_id: data.employee_id,
        date: data.date,
        kind: data.kind,
        punch_uuid: data.punch_uuid.trim() || null,
        proposed_type: needsType.value ? data.proposed_type : null,
        proposed_time: needsTime.value ? data.proposed_time : null,
        reason: data.reason,
        evidence: data.evidence,
    })).post(RectificationController.store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
            <SheetHeader class="border-b p-5">
                <SheetTitle
                    class="flex items-center gap-2 font-display text-lg"
                >
                    <FilePlus2
                        class="size-5 text-brand-strong"
                        aria-hidden="true"
                    />
                    Nueva rectificación
                </SheetTitle>
                <SheetDescription
                    >El marcaje original nunca se modifica.</SheetDescription
                >
            </SheetHeader>
            <form
                id="rectification-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    label="Colaborador"
                    required
                    :error="form.errors.employee_id"
                    v-slot="{ id, invalid }"
                >
                    <ComboboxField
                        :id="id"
                        v-model="form.employee_id"
                        :options="employees"
                        :invalid="invalid"
                        placeholder="Busca por nombre o número"
                    />
                </FormField>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Fecha"
                        required
                        :error="form.errors.date"
                        v-slot="{ id, invalid }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.date"
                            :max="today"
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        label="Tipo"
                        required
                        :error="form.errors.kind"
                        v-slot="{ id, invalid }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.kind"
                            :options="kinds"
                            :invalid="invalid"
                        />
                    </FormField>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        v-if="needsType"
                        label="Marcaje propuesto"
                        :error="form.errors.proposed_type"
                        v-slot="{ id, invalid }"
                    >
                        <SelectField
                            :id="id"
                            v-model="form.proposed_type"
                            :options="punchTypes"
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        v-if="needsTime"
                        label="Hora propuesta"
                        :error="form.errors.proposed_time"
                        v-slot="{ id, invalid }"
                    >
                        <TimeField
                            :id="id"
                            v-model="form.proposed_time"
                            :invalid="invalid"
                        />
                    </FormField>
                </div>
                <FormField
                    label="Marcaje a corregir"
                    :required="needsPunch"
                    :hint="needsPunch ? undefined : 'Opcional'"
                    :error="form.errors.punch_uuid"
                    v-slot="{ id, invalid }"
                >
                    <DayPunchPicker
                        :id="id"
                        v-model="form.punch_uuid"
                        :employee-id="form.employee_id"
                        :date="form.date"
                        :invalid="invalid"
                    />
                </FormField>
                <FormField
                    label="Motivo"
                    required
                    :hint="`${form.reason.length}/2000`"
                    :error="form.errors.reason"
                    v-slot="{ id }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.reason"
                        rows="4"
                        maxlength="2000"
                        placeholder="Describe qué ocurrió (mínimo 10 caracteres)…"
                    />
                </FormField>
                <FormField label="Evidencia" required :error="evidenceError">
                    <FileDropzone
                        v-model="form.evidence"
                        :max-files="5"
                        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf"
                        title="Foto, documento o constancia"
                        description="JPG, PNG, WEBP, HEIC o PDF · máx. 10 MB"
                    />
                </FormField>
            </form>
            <SheetFooter class="border-t p-5">
                <Button
                    type="submit"
                    form="rectification-form"
                    size="lg"
                    class="w-full"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else aria-hidden="true" />
                    Registrar solicitud
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
