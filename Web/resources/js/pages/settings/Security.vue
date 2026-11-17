<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, KeyRound } from '@lucide/vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import FormField from '@/components/app/FormField.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

// oxfmt-ignore
type Props = {
    passwordRules: string;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi cuenta', href: '/settings/profile' },
            { title: 'Seguridad', href: '/settings/security' },
        ],
    },
});
</script>

<template>
    <Head title="Seguridad" />

    <SectionCard
        title="Cambiar contraseña"
        description="Confirma la actual y elige una nueva."
        :icon="KeyRound"
        divided
    >
        <Form
            v-bind="SecurityController.update.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="grid gap-5"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <FormField
                label="Contraseña actual"
                :error="errors.current_password"
                v-slot="{ id, describedBy }"
            >
                <PasswordInput
                    :id="id"
                    name="current_password"
                    autocomplete="current-password"
                    placeholder="Tu contraseña actual"
                    :aria-describedby="describedBy"
                    :aria-invalid="errors.current_password ? true : undefined"
                />
            </FormField>

            <div class="grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Nueva contraseña"
                    :error="errors.password"
                    v-slot="{ id, describedBy }"
                >
                    <PasswordInput
                        :id="id"
                        name="password"
                        autocomplete="new-password"
                        placeholder="Nueva contraseña"
                        :passwordrules="props.passwordRules"
                        :aria-describedby="describedBy"
                        :aria-invalid="errors.password ? true : undefined"
                    />
                </FormField>

                <FormField
                    label="Confirma la nueva contraseña"
                    :error="errors.password_confirmation"
                    v-slot="{ id, describedBy }"
                >
                    <PasswordInput
                        :id="id"
                        name="password_confirmation"
                        autocomplete="new-password"
                        placeholder="Repite la contraseña"
                        :passwordrules="props.passwordRules"
                        :aria-describedby="describedBy"
                        :aria-invalid="
                            errors.password_confirmation ? true : undefined
                        "
                    />
                </FormField>
            </div>

            <div class="flex items-center gap-3 border-t pt-5">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    <Spinner v-if="processing" />
                    Actualizar contraseña
                </Button>
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-active-class="transition-opacity duration-300"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-show="recentlySuccessful"
                        class="flex items-center gap-1.5 text-sm text-success-soft-foreground"
                    >
                        <Check class="icon-sm" aria-hidden="true" />
                        Contraseña actualizada
                    </p>
                </Transition>
            </div>
        </Form>
    </SectionCard>
</template>
