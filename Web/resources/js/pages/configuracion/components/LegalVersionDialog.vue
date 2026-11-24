<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, watch } from 'vue';
import LegalVersionController from '@/actions/App/Http/Controllers/Web/Admin/LegalVersionController';
import FormField from '@/components/app/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { LegalEditableKey, LegalVersionRow } from '../types';
import NumberInput from './NumberInput.vue';

/**
 * Edición (mode=edit) o verificación por RH (mode=verify) de una versión normativa.
 * Los valores se capturan en horas y se envían en minutos enteros.
 */
const props = defineProps<{
    version: LegalVersionRow | null;
    mode: 'edit' | 'verify';
}>();
const open = defineModel<boolean>('open', { default: false });

const FIELDS: { key: LegalEditableKey; label: string; unit: 'h' | 'd' }[] = [
    { key: 'weekly_ordinary_max_minutes', label: 'Jornada semanal', unit: 'h' },
    { key: 'daily_max_day', label: 'Máx. diario diurna', unit: 'h' },
    { key: 'daily_max_night', label: 'Máx. diario nocturna', unit: 'h' },
    { key: 'daily_max_mixed', label: 'Máx. diario mixta', unit: 'h' },
    { key: 'overtime_weekly_max', label: 'Extra por semana', unit: 'h' },
    { key: 'overtime_daily_max', label: 'Extra por día', unit: 'h' },
    { key: 'overtime_max_days_per_week', label: 'Días con extra', unit: 'd' },
    {
        key: 'overtime_excess_weekly_max',
        label: 'Triples por semana',
        unit: 'h',
    },
    { key: 'daily_total_max', label: 'Jornada total diaria', unit: 'h' },
];

type FormShape = Record<LegalEditableKey, number | null> & { note: string };

const form = useForm<FormShape>({
    weekly_ordinary_max_minutes: null,
    daily_max_day: null,
    daily_max_night: null,
    daily_max_mixed: null,
    overtime_weekly_max: null,
    overtime_daily_max: null,
    overtime_max_days_per_week: null,
    overtime_excess_weekly_max: null,
    daily_total_max: null,
    note: '',
});

watch(
    () => [props.version?.id, open.value],
    () => {
        form.clearErrors();
        form.note = '';
        for (const f of FIELDS) {
            const value = props.version?.values[f.key] ?? null;
            form[f.key] =
                value === null ? null : f.unit === 'h' ? value / 60 : value;
        }
    },
    { immediate: true },
);

const title = computed(() =>
    props.mode === 'edit' ? 'Editar normativa' : 'Verificar normativa',
);

function submit(): void {
    if (!props.version) {
        return;
    }
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.mode === 'verify') {
        form.transform(() => ({ note: form.note })).submit(
            LegalVersionController.verify(props.version.id),
            options,
        );

        return;
    }

    form.transform((data) => {
        const out: Record<string, number | string> = { note: data.note };
        for (const f of FIELDS) {
            const v = Number(data[f.key] ?? 0);
            out[f.key] = f.unit === 'h' ? Math.round(v * 60) : Math.round(v);
        }

        return out;
    }).submit(LegalVersionController.update(props.version.id), options);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="version">
                    {{ version.code }} ·
                    {{
                        mode === 'edit'
                            ? 'Al guardar queda «Sin verificar».'
                            : 'Se registra tu usuario y la fecha de hoy.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form v-if="version" class="grid gap-5" @submit.prevent="submit">
                <div v-if="mode === 'edit'" class="grid gap-4 sm:grid-cols-3">
                    <FormField
                        v-for="f in FIELDS"
                        :key="f.key"
                        :label="f.label"
                        :error="form.errors[f.key]"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form[f.key]"
                            :min="0"
                            :step="f.unit === 'h' ? 0.5 : 1"
                            :max="f.unit === 'd' ? 7 : 80"
                            :suffix="f.unit === 'h' ? 'h' : 'días'"
                            :invalid="!!form.errors[f.key]"
                        />
                    </FormField>
                </div>

                <p
                    v-else
                    class="flex items-start gap-2 rounded-xl bg-success-soft/60 p-3 text-sm text-success-soft-foreground"
                >
                    <ShieldCheck class="mt-0.5 icon-sm shrink-0" />
                    Confirmas que RH (con Jurídico) contrastó estos parámetros
                    con la publicación oficial.
                </p>

                <FormField
                    :label="
                        mode === 'edit' ? 'Motivo del cambio' : 'Referencia'
                    "
                    required
                    :error="
                        form.errors.note ||
                        (form.errors as Record<string, string | undefined>)
                            .version
                    "
                    v-slot="{ id, describedBy, invalid }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.note"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid || undefined"
                        rows="3"
                        maxlength="500"
                        :placeholder="
                            mode === 'edit'
                                ? 'Publicación DOF, disposición STPS…'
                                : 'Oficio, dictamen o minuta de Jurídico…'
                        "
                    />
                </FormField>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || form.note.trim().length < 5
                        "
                    >
                        <Spinner v-if="form.processing" />
                        {{ mode === 'edit' ? 'Guardar' : 'Verificar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
