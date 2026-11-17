<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CheckCheck, Eye } from '@lucide/vue';
import { watch } from 'vue';
import ComplianceController from '@/actions/App/Http/Controllers/Web/Operacion/ComplianceController';
import FormField from '@/components/app/FormField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
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
import { formatDate } from '@/lib/format';
import type { ComplianceAlertRow } from '@/types/operacion';
import { SEVERITY_TONE } from './severity';

const props = defineProps<{ alert: ComplianceAlertRow | null }>();
const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    status: 'resolved' as 'acknowledged' | 'resolved',
    note: '',
});

watch(
    () => props.alert?.id,
    () => {
        form.reset();
        form.clearErrors();
        form.status =
            props.alert?.status === 'acknowledged'
                ? 'resolved'
                : 'acknowledged';
    },
);

const OPTIONS = [
    {
        value: 'acknowledged',
        label: 'Atendida',
        hint: 'En seguimiento',
        icon: Eye,
    },
    {
        value: 'resolved',
        label: 'Resuelta',
        hint: 'Cierra la alerta',
        icon: CheckCheck,
    },
] as const;

function submit(): void {
    if (!props.alert) {
        return;
    }
    form.submit(ComplianceController.resolve(props.alert.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Atender alerta</DialogTitle>
                <DialogDescription v-if="alert">
                    {{ alert.rule_label }} ·
                    {{ formatDate(alert.date, 'date') }}
                </DialogDescription>
            </DialogHeader>

            <form v-if="alert" class="grid gap-5" @submit.prevent="submit">
                <div class="rounded-xl border bg-surface-2 p-3.5">
                    <div class="mb-1.5 flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :label="alert.severity_label"
                            :tone="SEVERITY_TONE[alert.severity]"
                            size="sm"
                        />
                        <span
                            v-if="alert.employee"
                            class="text-sm font-medium"
                            >{{ alert.employee.name }}</span
                        >
                    </div>
                    <p class="text-sm">{{ alert.message }}</p>
                    <p
                        v-if="alert.legal_basis"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Fundamento: {{ alert.legal_basis }}
                    </p>
                </div>

                <div
                    class="grid grid-cols-2 gap-2"
                    role="radiogroup"
                    aria-label="Estado"
                >
                    <button
                        v-for="o in OPTIONS"
                        :key="o.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.status === o.value"
                        :class="[
                            'flex items-center gap-3 rounded-xl border p-3 text-left transition-all focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none',
                            form.status === o.value
                                ? 'border-primary bg-primary-soft/40 ring-1 ring-primary'
                                : 'hover:border-border-strong hover:bg-accent/40',
                        ]"
                        @click="form.status = o.value"
                    >
                        <component
                            :is="o.icon"
                            class="size-5 shrink-0 text-primary-soft-foreground"
                            aria-hidden="true"
                        />
                        <span>
                            <span class="block text-sm font-semibold">{{
                                o.label
                            }}</span>
                            <span class="block text-xs text-muted-foreground">{{
                                o.hint
                            }}</span>
                        </span>
                    </button>
                </div>

                <FormField
                    label="Nota"
                    required
                    :error="form.errors.note || form.errors.status"
                    v-slot="{ id, describedBy, invalid }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.note"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid || undefined"
                        rows="4"
                        maxlength="2000"
                        placeholder="Acción tomada, responsable, evidencia…"
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
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
