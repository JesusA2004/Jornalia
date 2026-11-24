<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: {
        title: 'Doble factor',
        description: 'Escribe el código de tu app autenticadora.',
    },
});

// Alterna entre código TOTP y código de recuperación (Fortify).
const useRecovery = ref(false);
</script>

<template>
    <Head title="Doble factor" />

    <Form
        action="/two-factor-challenge"
        method="post"
        v-slot="{ errors, processing }"
        class="grid gap-5"
    >
        <div v-if="!useRecovery" class="grid gap-2">
            <Label for="code">Código</Label>
            <Input
                id="code"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                required
                v-focus
                class="h-10 font-mono"
                :aria-invalid="errors.code ? true : undefined"
            />
            <InputError :message="errors.code" />
        </div>

        <div v-else class="grid gap-2">
            <Label for="recovery_code">Código de recuperación</Label>
            <Input
                id="recovery_code"
                name="recovery_code"
                autocomplete="off"
                required
                v-focus
                class="h-10 font-mono"
                :aria-invalid="errors.recovery_code ? true : undefined"
            />
            <InputError :message="errors.recovery_code" />
        </div>

        <Button type="submit" class="h-10" :disabled="processing">
            <Spinner v-if="processing" />
            Continuar
        </Button>

        <button
            type="button"
            class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            @click="useRecovery = !useRecovery"
        >
            {{
                useRecovery
                    ? 'Usar código de la app'
                    : 'Usar código de recuperación'
            }}
        </button>
    </Form>
</template>
