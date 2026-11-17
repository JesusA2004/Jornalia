<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, MailCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Recupera tu acceso',
        description: 'Te enviaremos un enlace a tu correo.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Recuperar contraseña" />

    <div
        v-if="status"
        role="status"
        class="flex items-start gap-2.5 rounded-lg border border-success/20 bg-success-soft px-3.5 py-3 text-sm text-success-soft-foreground"
    >
        <MailCheck class="mt-0.5 icon-sm shrink-0" aria-hidden="true" />
        <span>{{ status }}</span>
    </div>

    <Form
        v-bind="email.form()"
        v-slot="{ errors, processing }"
        class="grid gap-5"
    >
        <div class="grid gap-2">
            <Label for="email">Correo electrónico</Label>
            <Input
                id="email"
                type="email"
                name="email"
                autocomplete="email"
                inputmode="email"
                v-focus
                required
                placeholder="nombre@empresa.com"
                class="h-10"
                :aria-invalid="errors.email ? true : undefined"
            />
            <InputError :message="errors.email" />
        </div>

        <Button
            size="lg"
            class="h-11 w-full"
            :disabled="processing"
            data-test="email-password-reset-link-button"
        >
            <Spinner v-if="processing" />
            Enviar enlace de recuperación
        </Button>
    </Form>

    <Link
        href="/login"
        class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-standard hover:text-foreground"
    >
        <ArrowLeft class="icon-sm" aria-hidden="true" />
        Volver a iniciar sesión
    </Link>
</template>
