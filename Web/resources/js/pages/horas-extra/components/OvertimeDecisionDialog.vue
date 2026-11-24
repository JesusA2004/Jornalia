<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertOctagon, Check, MessageSquareText, Timer } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import OvertimeController from '@/actions/App/Http/Controllers/Web/Operacion/OvertimeController';
import FormField from '@/components/app/FormField.vue';
import MinutesDisplay from '@/components/app/MinutesDisplay.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { EmployeeCard, OvertimeRecordCell } from '@/types/operacion';

/**
 * Autorización o justificación del tiempo extra de un día. Los minutos
 * autorizados no superan lo registrado; el tiempo nunca se oculta. A menores de
 * 18 años sólo se les puede justificar (el backend también lo bloquea).
 */
const props = defineProps<{
    record: OvertimeRecordCell | null;
    employee: EmployeeCard | null;
}>();
const open = defineModel<boolean>('open', { default: false });

const mode = ref<'approve' | 'reject'>('approve');
const form = useForm({
    decision: 'approve' as 'approve' | 'reject',
    authorized_minutes: '' as string,
    justification: '',
});
const localErrors = ref<{ minutes?: string; justification?: string }>({});

watch(
    () => props.record,
    (r) => {
        if (r) {
            mode.value = r.can_authorize ? 'approve' : 'reject';
            form.clearErrors();
            localErrors.value = {};
            form.authorized_minutes = String(
                Math.max(0, r.recorded_excess_minutes - r.over_limit_minutes) ||
                    r.recorded_excess_minutes,
            );
            form.justification = '';
        }
    },
    { immediate: true },
);

const max = computed(() => props.record?.recorded_excess_minutes ?? 0);

function submit(): void {
    if (!props.record) {
        return;
    }
    localErrors.value = {};
    const minutes = Number(form.authorized_minutes);
    if (
        mode.value === 'approve' &&
        (!Number.isInteger(minutes) || minutes <= 0 || minutes > max.value)
    ) {
        localErrors.value.minutes = `Indica entre 1 y ${max.value} minutos.`;
    }
    if (form.justification.trim().length < 5) {
        localErrors.value.justification =
            'La justificación es obligatoria (mínimo 5 caracteres).';
    }
    if (Object.keys(localErrors.value).length > 0) {
        return;
    }
    form.transform((data) => ({
        decision: mode.value,
        authorized_minutes:
            mode.value === 'approve' ? Number(data.authorized_minutes) : null,
        justification: data.justification,
    })).post(OvertimeController.decide.url(props.record.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 font-display">
                    <Timer
                        class="size-5 text-brand-strong"
                        aria-hidden="true"
                    />
                    Tiempo extraordinario
                </DialogTitle>
                <DialogDescription v-if="record">
                    {{ employee?.name ?? 'Colaborador' }} ·
                    {{ formatDate(record.date, 'date-long') }}
                </DialogDescription>
            </DialogHeader>

            <form
                v-if="record"
                id="overtime-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="rounded-xl bg-surface-2 p-2.5">
                        <p class="text-[0.6875rem] text-muted-foreground">
                            Registrado
                        </p>
                        <MinutesDisplay
                            :minutes="record.recorded_excess_minutes"
                            format="short"
                            class="numeric font-bold"
                        />
                    </div>
                    <div class="rounded-xl bg-surface-2 p-2.5">
                        <p class="text-[0.6875rem] text-muted-foreground">
                            Dobles
                        </p>
                        <MinutesDisplay
                            :minutes="record.double_minutes"
                            format="short"
                            class="numeric font-bold"
                        />
                    </div>
                    <div class="rounded-xl bg-surface-2 p-2.5">
                        <p class="text-[0.6875rem] text-muted-foreground">
                            Triples
                        </p>
                        <MinutesDisplay
                            :minutes="record.triple_minutes"
                            format="short"
                            class="numeric font-bold"
                        />
                    </div>
                    <div
                        :class="
                            cn(
                                'rounded-xl p-2.5',
                                record.over_limit_minutes > 0
                                    ? 'bg-danger-soft text-danger-soft-foreground ring-1 ring-danger/30'
                                    : 'bg-surface-2',
                            )
                        "
                    >
                        <p class="text-[0.6875rem]">Sobre límite</p>
                        <MinutesDisplay
                            :minutes="record.over_limit_minutes"
                            format="short"
                            class="numeric font-bold"
                        />
                    </div>
                </div>
                <p
                    v-if="!record.can_authorize"
                    class="flex items-start gap-2 rounded-xl border border-danger/30 bg-danger-soft/60 p-3 text-xs text-danger-soft-foreground"
                >
                    <AlertOctagon
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Menor de 18 años: tiempo extra prohibido. Sólo puede
                    justificarse.
                </p>
                <p
                    v-else-if="record.over_limit_minutes > 0"
                    class="flex items-start gap-2 rounded-xl border border-danger/30 bg-danger-soft/60 p-3 text-xs text-danger-soft-foreground"
                >
                    <AlertOctagon
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Rebasa los límites legales. Permanece registrado y genera
                    alerta de cumplimiento.
                </p>

                <div
                    class="grid grid-cols-2 gap-2"
                    role="radiogroup"
                    aria-label="Decisión"
                >
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="mode === 'approve'"
                        :disabled="!record.can_authorize"
                        :class="
                            cn(
                                'flex items-center justify-center gap-2 rounded-xl border p-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50',
                                mode === 'approve'
                                    ? 'border-success/40 bg-success-soft text-success-soft-foreground ring-2 ring-success/30'
                                    : 'text-muted-foreground hover:bg-surface-2',
                            )
                        "
                        @click="mode = 'approve'"
                    >
                        <Check class="size-4" aria-hidden="true" /> Autorizar
                    </button>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="mode === 'reject'"
                        :class="
                            cn(
                                'flex items-center justify-center gap-2 rounded-xl border p-2.5 text-sm font-semibold',
                                mode === 'reject'
                                    ? 'border-foreground/30 bg-neutral-soft text-neutral-soft-foreground ring-2 ring-foreground/20'
                                    : 'text-muted-foreground hover:bg-surface-2',
                            )
                        "
                        @click="mode = 'reject'"
                    >
                        <MessageSquareText class="size-4" aria-hidden="true" />
                        Justificar
                    </button>
                </div>

                <FormField
                    v-if="mode === 'approve'"
                    label="Minutos autorizados"
                    required
                    :hint="`Máximo ${max} min`"
                    :error="
                        localErrors.minutes ?? form.errors.authorized_minutes
                    "
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.authorized_minutes"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        :max="max"
                        class="w-40"
                    />
                </FormField>
                <FormField
                    label="Justificación"
                    required
                    :error="
                        localErrors.justification ?? form.errors.justification
                    "
                    v-slot="{ id }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.justification"
                        rows="3"
                        maxlength="1000"
                        :placeholder="
                            mode === 'reject'
                                ? 'Por qué el tiempo no se paga como extra…'
                                : 'Motivo operativo de la autorización…'
                        "
                    />
                </FormField>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="overtime-form"
                    :variant="mode === 'reject' ? 'secondary' : 'default'"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        mode === 'reject'
                            ? 'Registrar justificación'
                            : 'Autorizar'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
