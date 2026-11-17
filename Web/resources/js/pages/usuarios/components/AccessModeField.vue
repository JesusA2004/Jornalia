<script setup lang="ts">
import { KeyRound, MailCheck } from '@lucide/vue';
import FormField from '@/components/app/FormField.vue';
import { cn } from '@/lib/utils';

/** Cómo recibe el acceso: contraseña temporal (se muestra una vez) o enlace por correo. */
defineProps<{ error?: string }>();

const mode = defineModel<'password' | 'reset'>({ required: true });

const modes = [
    {
        value: 'reset',
        title: 'Enviar enlace',
        text: 'Define su contraseña por correo',
        icon: MailCheck,
    },
    {
        value: 'password',
        title: 'Contraseña temporal',
        text: 'Se muestra una sola vez',
        icon: KeyRound,
    },
] as const;
</script>

<template>
    <FormField label="Acceso" :error="error">
        <div
            class="grid grid-cols-2 gap-2"
            role="radiogroup"
            aria-label="Método de acceso"
        >
            <button
                v-for="m in modes"
                :key="m.value"
                type="button"
                role="radio"
                :aria-checked="mode === m.value"
                :class="
                    cn(
                        'flex items-start gap-2.5 rounded-xl border px-3 py-2.5 text-left transition-standard',
                        mode === m.value
                            ? 'border-brand-strong bg-brand-soft text-brand-soft-foreground shadow-xs'
                            : 'border-border hover:bg-accent',
                    )
                "
                @click="mode = m.value"
            >
                <component
                    :is="m.icon"
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span class="min-w-0">
                    <span class="block text-sm font-semibold">{{
                        m.title
                    }}</span>
                    <span class="block text-xs opacity-80">{{ m.text }}</span>
                </span>
            </button>
        </div>
    </FormField>
</template>
