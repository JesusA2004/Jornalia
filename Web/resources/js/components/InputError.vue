<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

defineProps<{
    message?: string;
}>();
</script>

<template>
    <p
        v-if="message"
        role="alert"
        class="flex items-start gap-1.5 text-xs font-medium text-danger-soft-foreground"
    >
        <CircleAlert class="mt-px icon-xs shrink-0" aria-hidden="true" />
        <span>{{ message }}</span>
    </p>
</template>
