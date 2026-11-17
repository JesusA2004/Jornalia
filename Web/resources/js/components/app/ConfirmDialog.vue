<script setup lang="ts">
import { CircleAlert, ShieldQuestion } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        /** `danger` para acciones destructivas o irreversibles. */
        tone?: 'default' | 'danger';
        icon?: LucideIcon;
        /** Muestra spinner y bloquea botones (p. ej. `form.processing`). */
        loading?: boolean;
        /** Si es false, el diálogo NO se cierra al confirmar (ciérralo tú). */
        closeOnConfirm?: boolean;
    }>(),
    {
        confirmLabel: 'Confirmar',
        cancelLabel: 'Cancelar',
        tone: 'default',
        loading: false,
        closeOnConfirm: true,
    },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

function onConfirm(): void {
    emit('confirm');

    if (props.closeOnConfirm) {
        open.value = false;
    }
}
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </AlertDialogTrigger>
        <AlertDialogContent class="gap-6 sm:max-w-md">
            <AlertDialogHeader class="flex-row items-start gap-4 text-left">
                <div
                    :class="
                        cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-full',
                            tone === 'danger'
                                ? 'bg-danger-soft text-danger-soft-foreground'
                                : 'bg-primary-soft text-primary-soft-foreground',
                        )
                    "
                >
                    <component
                        :is="
                            icon ??
                            (tone === 'danger' ? CircleAlert : ShieldQuestion)
                        "
                        class="icon-md"
                        aria-hidden="true"
                    />
                </div>
                <div class="space-y-1.5">
                    <AlertDialogTitle class="text-base leading-snug">{{
                        title
                    }}</AlertDialogTitle>
                    <AlertDialogDescription
                        v-if="description || $slots.description"
                        class="leading-relaxed"
                    >
                        <slot name="description">{{ description }}</slot>
                    </AlertDialogDescription>
                </div>
            </AlertDialogHeader>
            <div v-if="$slots.default" class="sm:pl-14">
                <slot />
            </div>
            <AlertDialogFooter>
                <AlertDialogCancel
                    :disabled="loading"
                    @click="emit('cancel')"
                    >{{ cancelLabel }}</AlertDialogCancel
                >
                <Button
                    :variant="tone === 'danger' ? 'destructive' : 'default'"
                    :disabled="loading"
                    @click="onConfirm"
                >
                    <Spinner v-if="loading" />
                    {{ confirmLabel }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
