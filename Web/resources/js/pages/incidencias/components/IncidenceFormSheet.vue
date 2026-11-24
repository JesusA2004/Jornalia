<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ClipboardPlus, Info, Send } from '@lucide/vue';
import { computed, watch } from 'vue';
import IncidenceController from '@/actions/App/Http/Controllers/Web/Operacion/IncidenceController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import DateRangePicker from '@/components/app/DateRangePicker.vue';
import FileDropzone from '@/components/app/FileDropzone.vue';
import FormField from '@/components/app/FormField.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';
import type { IncidenceTypeOption } from '@/types/operacion';
import TimeField from '../../rectificaciones/components/TimeField.vue';

/**
 * Registro de incidencia (catálogo cerrado). Evidencia y folio según el tipo;
 * la omisión de checada abre una rectificación para firma del colaborador.
 */
const props = defineProps<{
    employees: SelectOption[];
    types: IncidenceTypeOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    employee_id: null as string | null,
    incidence_type_id: null as string | null,
    starts_on: null as string | null,
    ends_on: null as string | null,
    proposed_time: null as string | null,
    folio: '',
    reason: '',
    evidence: [] as File[],
});

const selectedType = computed(
    () => props.types.find((t) => t.value === form.incidence_type_id) ?? null,
);
const isOmission = computed(() =>
    Boolean(selectedType.value?.omission_punch_type),
);
const evidenceRequired = computed(
    () => selectedType.value?.requires_evidence ?? false,
);

watch(isOmission, (omission) => {
    if (omission) {
        form.ends_on = form.starts_on;
    }
});

const evidenceError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.evidence ??
        Object.entries(errors).find(([k]) => k.startsWith('evidence.'))?.[1] ??
        null
    );
});

function submit(): void {
    if (evidenceRequired.value && form.evidence.length === 0) {
        form.setError(
            'evidence',
            `Adjunta ${selectedType.value?.evidence_label?.toLowerCase() ?? 'la evidencia'}.`,
        );

        return;
    }
    form.transform((data) => ({
        employee_id: data.employee_id,
        incidence_type_id: data.incidence_type_id,
        starts_on: data.starts_on,
        ends_on: isOmission.value
            ? data.starts_on
            : (data.ends_on ?? data.starts_on),
        proposed_time: isOmission.value ? data.proposed_time : null,
        folio: selectedType.value?.requires_folio ? data.folio.trim() : null,
        reason: data.reason || null,
        evidence: data.evidence,
    })).post(IncidenceController.store.url(), {
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
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-xl">
            <SheetHeader class="border-b p-5">
                <SheetTitle
                    class="flex items-center gap-2 font-display text-lg"
                >
                    <ClipboardPlus
                        class="size-5 text-brand-strong"
                        aria-hidden="true"
                    />
                    Nueva incidencia
                </SheetTitle>
                <SheetDescription>Catálogo autorizado por RH.</SheetDescription>
            </SheetHeader>
            <form
                id="incidence-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <fieldset class="grid gap-2">
                    <legend
                        class="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Tipo de incidencia
                    </legend>
                    <div
                        class="flex flex-wrap gap-1.5"
                        role="radiogroup"
                        aria-label="Tipo de incidencia"
                    >
                        <button
                            v-for="t in types"
                            :key="t.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.incidence_type_id === t.value"
                            :class="
                                cn(
                                    'rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors',
                                    form.incidence_type_id === t.value
                                        ? 'border-brand bg-brand-soft text-brand-soft-foreground ring-2 ring-brand/20'
                                        : 'hover:bg-surface-2',
                                )
                            "
                            @click="form.incidence_type_id = t.value"
                        >
                            {{ t.name }}
                        </button>
                    </div>
                    <p
                        v-if="form.errors.incidence_type_id"
                        class="text-sm text-danger-soft-foreground"
                    >
                        {{ form.errors.incidence_type_id }}
                    </p>
                </fieldset>

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

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        :label="isOmission ? 'Día' : 'Periodo'"
                        required
                        :error="form.errors.starts_on ?? form.errors.ends_on"
                        v-slot="{ id, invalid }"
                    >
                        <DatePicker
                            v-if="isOmission"
                            :id="id"
                            v-model="form.starts_on"
                            :invalid="invalid"
                        />
                        <DateRangePicker
                            v-else
                            :id="id"
                            v-model:start="form.starts_on"
                            v-model:end="form.ends_on"
                            :invalid="invalid"
                            :presets="false"
                            :number-of-months="1"
                        />
                    </FormField>
                    <FormField
                        v-if="isOmission"
                        :label="
                            selectedType?.omission_punch_type === 'clock_in'
                                ? 'Hora real de entrada'
                                : 'Hora real de salida'
                        "
                        required
                        :error="form.errors.proposed_time"
                        v-slot="{ id, invalid }"
                    >
                        <TimeField
                            :id="id"
                            v-model="form.proposed_time"
                            :invalid="invalid"
                        />
                    </FormField>
                    <FormField
                        v-if="selectedType?.requires_folio"
                        label="Folio IMSS"
                        required
                        :error="form.errors.folio"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="form.folio"
                            maxlength="40"
                            placeholder="LT123456"
                            autocomplete="off"
                        />
                    </FormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        :label="
                            selectedType?.evidence_label
                                ? `Evidencia: ${selectedType.evidence_label}`
                                : 'Evidencia'
                        "
                        :required="evidenceRequired"
                        :error="evidenceError"
                    >
                        <FileDropzone
                            v-model="form.evidence"
                            :max-files="5"
                            accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf"
                            :invalid="Boolean(evidenceError)"
                            title="Arrastra el archivo"
                            description="PDF o imagen · máx. 10 MB"
                        />
                    </FormField>
                    <FormField
                        label="Observaciones"
                        hint="Opcional"
                        :error="form.errors.reason"
                        v-slot="{ id }"
                    >
                        <Textarea
                            :id="id"
                            v-model="form.reason"
                            rows="4"
                            maxlength="2000"
                        />
                    </FormField>
                </div>

                <p
                    class="flex items-start gap-2 rounded-lg bg-warning-soft p-3 text-xs text-warning-soft-foreground"
                >
                    <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>
                        La <strong>falta injustificada</strong> no se captura:
                        la genera el sistema. La
                        <strong>omisión de checada</strong> abre una
                        rectificación que firma el colaborador. Vacaciones se
                        tramitan en su módulo.
                    </span>
                </p>
            </form>
            <SheetFooter class="border-t p-5">
                <Button
                    type="submit"
                    form="incidence-form"
                    size="lg"
                    class="w-full"
                    :disabled="form.processing || !selectedType"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else aria-hidden="true" />
                    {{
                        isOmission
                            ? 'Enviar a rectificación'
                            : 'Enviar a autorización'
                    }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
