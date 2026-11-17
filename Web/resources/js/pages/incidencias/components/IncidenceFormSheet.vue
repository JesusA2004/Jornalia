<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    ClipboardPlus,
    FileWarning,
    Send,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import IncidenceController from '@/actions/App/Http/Controllers/Web/Operacion/IncidenceController';
import ComboboxField from '@/components/app/ComboboxField.vue';
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
import type { SelectOption } from '@/types';
import type { IncidenceTypeOption } from '@/types/operacion';

/** Registro de incidencia a nombre de un colaborador (permiso incidences.create). */
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
    minutes: '' as string,
    reason: '',
    evidence: [] as File[],
});

const selectedType = computed(
    () => props.types.find((t) => t.value === form.incidence_type_id) ?? null,
);
const evidenceRequired = computed(
    () => selectedType.value?.requires_evidence ?? false,
);
const evidenceError = computed(() => form.errors.evidence ?? null);

function submit(): void {
    if (evidenceRequired.value && form.evidence.length === 0) {
        form.setError(
            'evidence',
            'Este tipo de incidencia requiere adjuntar evidencia.',
        );

        return;
    }
    form.transform((data) => ({
        employee_id: data.employee_id,
        incidence_type_id: data.incidence_type_id,
        starts_on: data.starts_on,
        ends_on: data.ends_on ?? data.starts_on,
        minutes: data.minutes ? Number(data.minutes) : null,
        reason: data.reason || null,
        evidence: data.evidence[0] ?? null,
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
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
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
                <SheetDescription
                    >Permisos, faltas justificadas,
                    incapacidades.</SheetDescription
                >
            </SheetHeader>
            <form
                id="incidence-form"
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
                <FormField
                    label="Tipo de incidencia"
                    required
                    :error="form.errors.incidence_type_id"
                    v-slot="{ id, invalid }"
                >
                    <ComboboxField
                        :id="id"
                        v-model="form.incidence_type_id"
                        :options="types"
                        :invalid="invalid"
                        placeholder="Selecciona un tipo"
                    />
                </FormField>
                <div v-if="selectedType" class="flex flex-wrap gap-1.5">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="
                            selectedType.requires_approval
                                ? 'bg-warning-soft text-warning-soft-foreground'
                                : 'bg-success-soft text-success-soft-foreground'
                        "
                    >
                        <BadgeCheck class="size-3.5" aria-hidden="true" />
                        {{
                            selectedType.requires_approval
                                ? 'Requiere aprobación'
                                : 'Se aplica al registrar'
                        }}
                    </span>
                    <span
                        v-if="selectedType.requires_evidence"
                        class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-2.5 py-1 text-xs font-medium text-danger-soft-foreground"
                    >
                        <FileWarning class="size-3.5" aria-hidden="true" />
                        Evidencia obligatoria
                    </span>
                    <span
                        v-if="selectedType.affects_payroll"
                        class="inline-flex items-center gap-1 rounded-full bg-info-soft px-2.5 py-1 text-xs font-medium text-info-soft-foreground"
                    >
                        <Wallet class="size-3.5" aria-hidden="true" /> Afecta
                        nómina
                    </span>
                </div>
                <FormField
                    label="Periodo"
                    required
                    :error="form.errors.starts_on ?? form.errors.ends_on"
                    v-slot="{ id, invalid }"
                >
                    <DateRangePicker
                        :id="id"
                        v-model:start="form.starts_on"
                        v-model:end="form.ends_on"
                        :invalid="invalid"
                        :presets="false"
                        :number-of-months="1"
                    />
                </FormField>
                <FormField
                    label="Minutos"
                    hint="Opcional · permisos por horas"
                    :error="form.errors.minutes"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.minutes"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="1440"
                        placeholder="p. ej. 120"
                        class="w-40"
                    />
                </FormField>
                <FormField
                    label="Motivo"
                    hint="Opcional"
                    :error="form.errors.reason"
                    v-slot="{ id }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.reason"
                        rows="3"
                        maxlength="2000"
                        placeholder="Detalles relevantes…"
                    />
                </FormField>
                <FormField
                    label="Evidencia"
                    :required="evidenceRequired"
                    :hint="evidenceRequired ? undefined : 'Opcional'"
                    :error="evidenceError"
                >
                    <FileDropzone
                        v-model="form.evidence"
                        :multiple="false"
                        :max-files="1"
                        :invalid="Boolean(evidenceError)"
                        title="Arrastra un archivo"
                        description="JPG, PNG, WEBP o PDF · máx. 10 MB"
                    />
                </FormField>
            </form>
            <SheetFooter class="border-t p-5">
                <Button
                    type="submit"
                    form="incidence-form"
                    size="lg"
                    class="w-full"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else aria-hidden="true" />
                    Registrar incidencia
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
