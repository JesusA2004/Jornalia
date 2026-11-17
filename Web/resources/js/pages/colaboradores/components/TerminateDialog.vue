<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserMinus } from '@lucide/vue';
import { computed, watch } from 'vue';
import EmploymentController from '@/actions/App/Http/Controllers/Web/Admin/EmploymentController';
import DatePicker from '@/components/app/DatePicker.vue';
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
import { todayIso } from '../lib';

const props = defineProps<{
    employeeId: number;
    employeeName: string;
    startsOn: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    ends_on: todayIso() as string | null,
    reason: '',
});

const errors = computed(() => form.errors as Record<string, string>);

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.submit(EmploymentController.terminate(props.employeeId), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="grid gap-5" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-danger-soft text-danger-soft-foreground"
                        >
                            <UserMinus class="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <DialogTitle>Dar de baja</DialogTitle>
                            <DialogDescription
                                >{{ employeeName }} · el historial se
                                conserva.</DialogDescription
                            >
                        </div>
                    </div>
                </DialogHeader>

                <FormField
                    label="Último día laborado"
                    :error="errors.ends_on"
                    required
                    v-slot="{ id, invalid }"
                >
                    <DatePicker
                        :id="id"
                        v-model="form.ends_on"
                        :min="startsOn ?? undefined"
                        :invalid="invalid"
                    />
                </FormField>
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
                        placeholder="Renuncia voluntaria, término de contrato…"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            form.processing ||
                            !form.ends_on ||
                            form.reason.trim().length < 3
                        "
                    >
                        <Spinner v-if="form.processing" />
                        Confirmar baja
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
