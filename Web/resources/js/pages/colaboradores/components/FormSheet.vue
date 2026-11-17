<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';

/**
 * Hoja lateral con formulario: encabezado, cuerpo desplazable y pie fijo.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: LucideIcon;
        submitLabel?: string;
        processing?: boolean;
        disabled?: boolean;
        tone?: 'default' | 'danger';
    }>(),
    {
        submitLabel: 'Guardar',
        processing: false,
        disabled: false,
        tone: 'default',
    },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ submit: [] }>();
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 p-0 sm:max-w-xl">
            <form
                class="flex h-full min-h-0 flex-col"
                novalidate
                @submit.prevent="emit('submit')"
            >
                <SheetHeader class="border-b px-6 py-5">
                    <div class="flex items-center gap-3 pr-6">
                        <span
                            v-if="icon"
                            :class="[
                                'flex size-10 shrink-0 items-center justify-center rounded-xl',
                                tone === 'danger'
                                    ? 'bg-danger-soft text-danger-soft-foreground'
                                    : 'bg-brand-soft text-brand-soft-foreground',
                            ]"
                        >
                            <component
                                :is="icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="min-w-0">
                            <SheetTitle class="font-display text-lg">{{
                                title
                            }}</SheetTitle>
                            <SheetDescription v-if="description">{{
                                description
                            }}</SheetDescription>
                        </div>
                    </div>
                </SheetHeader>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                    <div class="grid gap-5">
                        <slot />
                    </div>
                </div>

                <SheetFooter
                    class="flex-row justify-end gap-2 border-t bg-surface-2 px-6 py-4"
                >
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :variant="tone === 'danger' ? 'destructive' : 'default'"
                        :disabled="processing || disabled"
                    >
                        <Spinner v-if="processing" />
                        {{ submitLabel }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
