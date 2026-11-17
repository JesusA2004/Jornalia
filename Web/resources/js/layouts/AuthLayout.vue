<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import AuthLayout from '@/layouts/auth/AuthSplitLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <slot />
    </AuthLayout>
    <Toaster position="top-right" />
</template>
