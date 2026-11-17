<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Spinner } from '@/components/ui/spinner';

/**
 * La raíz `/` debe redirigir al inicio de sesión desde rutas (backend).
 * Esta vista sólo existe como respaldo: redirige de inmediato.
 */
onMounted(() => {
    router.visit('/login', { replace: true });
});
</script>

<template>
    <Head title="Redirigiendo" />
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6"
    >
        <AppLogo />
        <p class="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner class="size-4" />
            Redirigiendo al inicio de sesión…
        </p>
        <Link
            href="/login"
            class="text-sm font-medium text-brand-strong underline-offset-4 hover:underline"
        >
            Continuar
        </Link>
    </div>
</template>
