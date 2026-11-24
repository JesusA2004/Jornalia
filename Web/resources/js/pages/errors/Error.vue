<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Clock3,
    FileQuestion,
    LayoutDashboard,
    LockKeyhole,
    RefreshCw,
    ServerCrash,
    Wrench,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { BRAND } from '@/lib/brand';

/**
 * Página de error propia (403, 404, 419, 429, 500 y 503). La renderiza el
 * manejador de excepciones de `bootstrap/app.php` para peticiones web; no
 * usa el layout de la aplicación para funcionar también sin sesión.
 */
const props = defineProps<{
    status: number;
}>();

type ErrorCopy = {
    title: string;
    text: string;
    icon: LucideIcon;
    reload?: boolean;
};

const COPY: Record<number, ErrorCopy> = {
    403: {
        title: 'Sin acceso',
        text: 'Tu rol no tiene permiso para ver esta sección.',
        icon: LockKeyhole,
    },
    404: {
        title: 'Página no encontrada',
        text: 'La dirección no existe o ya no está disponible.',
        icon: FileQuestion,
    },
    419: {
        title: 'La sesión expiró',
        text: 'Recarga la página para continuar.',
        icon: Clock3,
        reload: true,
    },
    429: {
        title: 'Demasiados intentos',
        text: 'Espera un minuto y vuelve a intentarlo.',
        icon: Clock3,
        reload: true,
    },
    500: {
        title: 'Algo salió mal',
        text: 'No pudimos completar la solicitud. Si se repite, avisa a Sistemas.',
        icon: ServerCrash,
        reload: true,
    },
    503: {
        title: 'En mantenimiento',
        text: 'Estamos actualizando el sistema. Vuelve en unos minutos.',
        icon: Wrench,
        reload: true,
    },
};

const copy = computed<ErrorCopy>(() => COPY[props.status] ?? COPY[500]);
const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));
const year = new Date().getFullYear();

function reload(): void {
    window.location.reload();
}

function goBack(): void {
    window.history.back();
}
</script>

<template>
    <Head :title="copy.title" />
    <div class="flex min-h-svh flex-col bg-background">
        <header class="flex h-16 items-center bg-brand-panel px-4 sm:px-8">
            <AppLogo size="sm" inverted />
        </header>

        <main
            class="flex flex-1 items-center justify-center px-4 py-12 sm:px-8"
        >
            <section
                class="w-full max-w-md text-center"
                aria-labelledby="error-title"
            >
                <span
                    class="mx-auto flex size-12 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                >
                    <component
                        :is="copy.icon"
                        class="icon-lg"
                        aria-hidden="true"
                    />
                </span>
                <p
                    class="mt-6 text-[0.6875rem] font-semibold tracking-[0.18em] text-gold-strong uppercase"
                >
                    Error {{ status }}
                </p>
                <h1
                    id="error-title"
                    class="mt-2 text-2xl font-semibold text-foreground"
                >
                    {{ copy.title }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ copy.text }}
                </p>

                <div
                    class="mt-8 flex flex-col-reverse justify-center gap-2 sm:flex-row"
                >
                    <Button variant="outline" @click="goBack">
                        <ArrowLeft aria-hidden="true" />
                        Regresar
                    </Button>
                    <Button v-if="copy.reload" @click="reload">
                        <RefreshCw aria-hidden="true" />
                        Recargar
                    </Button>
                    <Button v-else as-child>
                        <Link :href="signedIn ? '/dashboard' : '/login'">
                            <LayoutDashboard aria-hidden="true" />
                            {{ signedIn ? 'Ir al panel' : 'Iniciar sesión' }}
                        </Link>
                    </Button>
                </div>
            </section>
        </main>

        <footer class="px-4 pb-6 text-center text-xs text-subtle-foreground">
            © {{ year }} {{ BRAND.name }} {{ BRAND.product }}
        </footer>
    </div>
</template>
