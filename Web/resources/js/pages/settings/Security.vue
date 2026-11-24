<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Check, KeyRound, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import FormField from '@/components/app/FormField.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    canManageTwoFactor?: boolean;
    twoFactorEnabled?: boolean;
    requiresConfirmation?: boolean;
    twoFactorRequired?: boolean;
};

const props = defineProps<Props>();

// Doble factor (TOTP). Endpoints de Fortify.
const qrSvg = ref<string | null>(null);
const setupKey = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);
const code = ref('');
const codeError = ref<string | null>(null);
const busy = ref(false);
const pendingConfirmation = ref(false);

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    return (await response.json()) as T;
}

async function loadSetup(): Promise<void> {
    const qr = await getJson<{ svg: string }>('/user/two-factor-qr-code');
    const key = await getJson<{ secretKey: string }>(
        '/user/two-factor-secret-key',
    );
    qrSvg.value = qr.svg;
    setupKey.value = key.secretKey;
}

async function loadRecoveryCodes(): Promise<void> {
    recoveryCodes.value = await getJson<string[]>(
        '/user/two-factor-recovery-codes',
    );
}

function enable(): void {
    busy.value = true;
    router.post(
        '/user/two-factor-authentication',
        {},
        {
            preserveScroll: true,
            onSuccess: async () => {
                pendingConfirmation.value = props.requiresConfirmation ?? true;
                await loadSetup();
                if (!props.requiresConfirmation) {
                    await loadRecoveryCodes();
                }
            },
            onFinish: () => (busy.value = false),
        },
    );
}

function confirm(): void {
    busy.value = true;
    codeError.value = null;
    router.post(
        '/user/confirmed-two-factor-authentication',
        { code: code.value },
        {
            preserveScroll: true,
            errorBag: 'confirmTwoFactorAuthentication',
            onSuccess: async () => {
                pendingConfirmation.value = false;
                qrSvg.value = null;
                setupKey.value = null;
                code.value = '';
                await loadRecoveryCodes();
            },
            onError: (errors) =>
                (codeError.value = errors.code ?? 'Código inválido.'),
            onFinish: () => (busy.value = false),
        },
    );
}

function regenerate(): void {
    router.post(
        '/user/two-factor-recovery-codes',
        {},
        {
            preserveScroll: true,
            onSuccess: () => loadRecoveryCodes(),
        },
    );
}

function disable(): void {
    router.delete('/user/two-factor-authentication', { preserveScroll: true });
}

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

    <SectionCard
        v-if="props.canManageTwoFactor"
        title="Doble factor"
        description="Código de tu app autenticadora al iniciar sesión."
        :icon="ShieldCheck"
        divided
        class="mt-6"
    >
        <template #actions>
            <Badge v-if="props.twoFactorEnabled" variant="secondary"
                >Activo</Badge
            >
            <Badge v-else-if="props.twoFactorRequired" variant="destructive"
                >Obligatorio</Badge
            >
        </template>

        <div class="grid gap-4">
            <p
                v-if="props.twoFactorRequired && !props.twoFactorEnabled"
                class="text-sm text-destructive"
            >
                Tu rol lo exige para usar el sistema.
            </p>

            <template v-if="!props.twoFactorEnabled && !pendingConfirmation">
                <div>
                    <Button
                        :disabled="busy"
                        data-test="enable-two-factor"
                        @click="enable"
                    >
                        <Spinner v-if="busy" />
                        Activar doble factor
                    </Button>
                </div>
            </template>

            <template v-if="pendingConfirmation || qrSvg">
                <div class="flex flex-wrap items-start gap-6">
                    <!-- SVG generado por el servidor (Fortify / BaconQrCode). -->
                    <div
                        v-if="qrSvg"
                        class="rounded-lg bg-white p-3"
                        v-html="qrSvg"
                    />
                    <div class="grid gap-3">
                        <p class="text-sm text-muted-foreground">
                            Escanéalo y escribe el código de 6 dígitos.
                        </p>
                        <p v-if="setupKey" class="font-mono text-xs break-all">
                            {{ setupKey }}
                        </p>
                        <FormField
                            label="Código"
                            :error="codeError ?? undefined"
                            v-slot="{ id, describedBy }"
                        >
                            <Input
                                :id="id"
                                v-model="code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                class="w-40 font-mono"
                                :aria-describedby="describedBy"
                            />
                        </FormField>
                        <div>
                            <Button
                                :disabled="busy || code.length < 6"
                                data-test="confirm-two-factor"
                                @click="confirm"
                            >
                                <Spinner v-if="busy" />
                                Confirmar
                            </Button>
                        </div>
                    </div>
                </div>
            </template>

            <template v-if="props.twoFactorEnabled">
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" @click="loadRecoveryCodes"
                        >Ver códigos de recuperación</Button
                    >
                    <Button
                        v-if="recoveryCodes.length"
                        variant="outline"
                        @click="regenerate"
                        >Regenerar códigos</Button
                    >
                    <Button
                        v-if="!props.twoFactorRequired"
                        variant="ghost"
                        @click="disable"
                        >Desactivar</Button
                    >
                </div>
            </template>

            <ul
                v-if="recoveryCodes.length"
                class="grid grid-cols-2 gap-1 rounded-lg bg-muted p-3 font-mono text-xs"
            >
                <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
            </ul>
        </div>
    </SectionCard>
</template>
