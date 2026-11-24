<script setup lang="ts">
import { Check, Copy, KeyRound, TriangleAlert } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** Muestra UNA vez la contraseña temporal recién generada. */
const props = defineProps<{
    credentials: { email: string; password: string } | null;
}>();

const open = ref(false);
const copied = ref(false);

watch(
    () => props.credentials,
    (value) => {
        if (value) {
            open.value = true;
            copied.value = false;
        }
    },
    { immediate: true },
);

const { copy: copyText } = useClipboard({ legacy: true });

async function copy(): Promise<void> {
    if (!props.credentials) {
        return;
    }

    try {
        // `legacy`: también funciona sin HTTPS (servidor interno por IP).
        await copyText(props.credentials.password);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="credentials" class="sm:max-w-md">
            <DialogHeader>
                <div
                    class="mb-1 flex size-11 items-center justify-center rounded-2xl bg-primary text-primary-foreground"
                >
                    <KeyRound class="size-5" aria-hidden="true" />
                </div>
                <DialogTitle>Contraseña temporal</DialogTitle>
                <DialogDescription>{{ credentials.email }}</DialogDescription>
            </DialogHeader>

            <div
                class="flex items-center gap-2 rounded-xl border border-brand/30 bg-brand-soft p-3"
            >
                <code
                    class="min-w-0 flex-1 truncate font-mono text-lg font-semibold tracking-wider text-brand-soft-foreground select-all"
                    >{{ credentials.password }}</code
                >
                <Button size="sm" variant="outline" @click="copy">
                    <Check v-if="copied" />
                    <Copy v-else />
                    {{ copied ? 'Copiada' : 'Copiar' }}
                </Button>
            </div>

            <p
                class="flex items-start gap-2 text-xs text-warning-soft-foreground"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                No volverá a mostrarse. Compártela por un medio seguro y pide
                cambiarla al entrar.
            </p>

            <DialogFooter>
                <Button @click="open = false">Entendido</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
