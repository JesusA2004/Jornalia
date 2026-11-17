<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Crea una nueva contraseña',
        description: 'Elige una contraseña segura.',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head title="Restablecer contraseña" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
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
                v-model="inputEmail"
                class="h-10 bg-muted text-muted-foreground"
                readonly
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <Label for="password">Nueva contraseña</Label>
            <PasswordInput
                id="password"
                name="password"
                autocomplete="new-password"
                class="h-10"
                autofocus
                placeholder="Escribe tu nueva contraseña"
                :passwordrules="passwordRules"
                :aria-invalid="errors.password ? true : undefined"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirma la contraseña</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                class="h-10"
                placeholder="Repite la nueva contraseña"
                :passwordrules="passwordRules"
                :aria-invalid="errors.password_confirmation ? true : undefined"
            />
            <InputError :message="errors.password_confirmation" />
        </div>

        <Button
            type="submit"
            size="lg"
            class="mt-2 h-11 w-full"
            :disabled="processing"
            data-test="reset-password-button"
        >
            <Spinner v-if="processing" />
            Guardar contraseña
        </Button>
    </Form>
</template>
