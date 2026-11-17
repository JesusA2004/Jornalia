<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CircleCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: 'Inicia sesión',
        description: 'Usa tu cuenta corporativa.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Iniciar sesión" />

    <div
        v-if="status"
        role="status"
        class="flex items-start gap-2.5 rounded-lg border border-success/20 bg-success-soft px-3.5 py-3 text-sm text-success-soft-foreground"
    >
        <CircleCheck class="mt-0.5 icon-sm shrink-0" aria-hidden="true" />
        <span>{{ status }}</span>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="grid gap-5"
    >
        <div class="grid gap-2">
            <Label for="email">Correo electrónico</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                v-focus
                :tabindex="1"
                autocomplete="email"
                inputmode="email"
                placeholder="nombre@empresa.com"
                class="h-10"
                :aria-invalid="errors.email ? true : undefined"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <Label for="password">Contraseña</Label>
                <Link
                    v-if="canResetPassword"
                    href="/forgot-password"
                    class="text-xs font-medium text-brand-strong underline-offset-4 hover:underline"
                    :tabindex="5"
                >
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>
            <PasswordInput
                id="password"
                name="password"
                required
                :tabindex="2"
                autocomplete="current-password"
                placeholder="Tu contraseña"
                class="h-10"
                :aria-invalid="errors.password ? true : undefined"
            />
            <InputError :message="errors.password" />
        </div>

        <Label
            for="remember"
            class="flex w-fit cursor-pointer items-center gap-2.5 font-normal text-muted-foreground"
        >
            <Checkbox id="remember" name="remember" :tabindex="3" />
            <span>Mantener la sesión iniciada en este equipo</span>
        </Label>

        <Button
            type="submit"
            size="lg"
            class="mt-2 h-11 w-full text-[0.9375rem]"
            :tabindex="4"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            <template v-else>
                Entrar
                <ArrowRight aria-hidden="true" />
            </template>
        </Button>
    </Form>
</template>
