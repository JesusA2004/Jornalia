<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';

/** Cancela una solicitud propia (queda registrada como cancelada). */
const props = withDefaults(defineProps<{ action: string; label?: string }>(), {
    label: 'Cancelar solicitud',
});

const open = ref(false);
const loading = ref(false);

function confirm(): void {
    router.post(
        props.action,
        {},
        {
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => {
                loading.value = false;
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        title="¿Cancelar la solicitud?"
        description="Quedará registrada como cancelada en el historial. Esta acción no se puede deshacer."
        confirm-label="Sí, cancelar"
        cancel-label="Volver"
        tone="danger"
        :icon="Ban"
        :loading="loading"
        :close-on-confirm="false"
        @confirm="confirm"
    >
        <template #trigger>
            <Button variant="outline" size="sm">
                <Ban aria-hidden="true" />
                {{ label }}
            </Button>
        </template>
    </ConfirmDialog>
</template>
