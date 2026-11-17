<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        name: string;
        src?: string | null;
        /** Texto secundario (correo, puesto, número de empleado). */
        subtitle?: string | null;
        size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
        /** Muestra nombre y subtítulo junto al avatar. */
        showName?: boolean;
        shape?: 'circle' | 'rounded';
        class?: HTMLAttributes['class'];
    }>(),
    { src: null, subtitle: null, size: 'md', showName: false, shape: 'circle' },
);

const SIZES = {
    xs: 'size-6 text-[0.625rem]',
    sm: 'size-8 text-xs',
    md: 'size-9 text-sm',
    lg: 'size-11 text-base',
    xl: 'size-16 text-xl',
} as const;

/** Tono de fondo estable a partir del nombre (6 variantes de gráfica). */
const hue = computed(() => {
    let hash = 0;

    for (const char of props.name) {
        hash = (hash * 31 + char.charCodeAt(0)) | 0;
    }

    return (Math.abs(hash) % 6) + 1;
});
</script>

<template>
    <div
        :class="
            cn(
                'flex min-w-0 items-center gap-3',
                !showName && 'inline-flex',
                props.class,
            )
        "
    >
        <Avatar
            :class="
                cn(
                    'shrink-0 overflow-hidden ring-1 ring-border',
                    SIZES[size],
                    shape === 'rounded' ? 'rounded-lg' : 'rounded-full',
                )
            "
        >
            <AvatarImage v-if="src" :src="src" :alt="name" />
            <AvatarFallback
                :class="
                    cn(
                        'font-semibold tracking-tight text-foreground/80',
                        shape === 'rounded' ? 'rounded-lg' : 'rounded-full',
                    )
                "
                :style="{
                    backgroundColor: `color-mix(in oklch, var(--chart-${hue}) 18%, var(--card))`,
                }"
            >
                {{ getInitials(name) }}
            </AvatarFallback>
        </Avatar>
        <div v-if="showName" class="grid min-w-0 leading-tight">
            <span class="truncate text-sm font-medium text-foreground">{{
                name
            }}</span>
            <span
                v-if="subtitle"
                class="truncate text-xs text-muted-foreground"
                >{{ subtitle }}</span
            >
        </div>
    </div>
</template>
