<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import FormField from '@/components/app/FormField.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

/** Cancela una incidencia con motivo obligatorio (no hay borrado; queda en bitácora). */
const props = withDefaults(defineProps<{ action: string; label?: string }>(), {
    label: 'Cancelar',
});

const open = ref(false);
const form = useForm({ reason: '' });

function confirm(): void {
    if (form.reason.trim().length < 5) {
        form.setError('reason', 'Indica el motivo (mínimo 5 caracteres).');

        return;
    }
    form.post(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        title="Cancelar incidencia"
        description="Se conserva en el historial con el motivo."
        confirm-label="Cancelar incidencia"
        cancel-label="Volver"
        tone="danger"
        :icon="Ban"
        :loading="form.processing"
        :close-on-confirm="false"
        @confirm="confirm"
    >
        <template #trigger>
            <Button variant="outline" size="sm">
                <Ban aria-hidden="true" />
                {{ label }}
            </Button>
        </template>
        <FormField
            label="Motivo"
            required
            :error="form.errors.reason"
            v-slot="{ id }"
        >
            <Textarea
                :id="id"
                v-model="form.reason"
                rows="3"
                maxlength="1000"
            />
        </FormField>
    </ConfirmDialog>
</template>
