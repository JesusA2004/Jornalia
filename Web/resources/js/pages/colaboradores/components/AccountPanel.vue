<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    KeyRound,
    Mail,
    ShieldAlert,
    Unlink,
    UserRoundCheck,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref } from 'vue';
import EmployeeAccountController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeAccountController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { EmployeeAccount } from '../types';

const props = defineProps<{
    employeeId: number;
    employeeEmail: string | null;
    account: EmployeeAccount | null;
    canManage: boolean;
    temporaryPassword: string | null;
}>();

const form = useForm({
    email: props.employeeEmail ?? '',
    mode: 'reset' as 'reset' | 'password',
});
const errors = computed(() => form.errors as Record<string, string>);

const copied = ref(false);
const unlinkOpen = ref(false);
const resetting = ref(false);
const unlinking = ref(false);

const modes = [
    {
        value: 'reset',
        title: 'Enviar enlace',
        text: 'Recibe un correo para definir su contraseña.',
        icon: Mail,
    },
    {
        value: 'password',
        title: 'Contraseña temporal',
        text: 'Se muestra una sola vez para entregarla en persona.',
        icon: KeyRound,
    },
] as const;

function create(): void {
    form.submit(EmployeeAccountController.store(props.employeeId), {
        preserveScroll: true,
    });
}

function sendReset(): void {
    router.post(
        EmployeeAccountController.reset.url(props.employeeId),
        {},
        {
            preserveScroll: true,
            onStart: () => (resetting.value = true),
            onFinish: () => (resetting.value = false),
        },
    );
}

function unlink(): void {
    router.post(
        EmployeeAccountController.unlink.url(props.employeeId),
        {},
        {
            preserveScroll: true,
            onStart: () => (unlinking.value = true),
            onFinish: () => {
                unlinking.value = false;
                unlinkOpen.value = false;
            },
        },
    );
}

// `legacy`: también funciona sin HTTPS (servidor interno por IP).
const { copy: copyText } = useClipboard({ legacy: true });

async function copy(): Promise<void> {
    if (!props.temporaryPassword) {
        return;
    }

    try {
        await copyText(props.temporaryPassword);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="grid gap-6">
        <div
            v-if="temporaryPassword"
            class="relative overflow-hidden rounded-2xl border border-warning/30 bg-warning-soft p-5 shadow-elev-1"
            role="status"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-start gap-3">
                    <ShieldAlert
                        class="mt-0.5 size-5 shrink-0 text-warning-soft-foreground"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-semibold text-warning-soft-foreground">
                            Contraseña temporal
                        </p>
                        <p class="text-sm text-warning-soft-foreground/80">
                            Cópiala ahora: no volverá a mostrarse.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <code
                        class="rounded-lg border bg-card px-4 py-2 font-mono text-base tracking-wider select-all"
                        >{{ temporaryPassword }}</code
                    >
                    <Button
                        variant="outline"
                        size="icon"
                        :aria-label="copied ? 'Copiada' : 'Copiar'"
                        @click="copy"
                    >
                        <Check v-if="copied" aria-hidden="true" />
                        <Copy v-else aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </div>

        <SectionCard
            v-if="account"
            title="Cuenta vinculada"
            :icon="UserRoundCheck"
            divided
        >
            <template v-if="canManage" #actions>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="resetting"
                    @click="sendReset"
                >
                    <Spinner v-if="resetting" />
                    <Mail v-else aria-hidden="true" />
                    Enviar restablecimiento
                </Button>
                <Button variant="ghost" size="sm" @click="unlinkOpen = true">
                    <Unlink aria-hidden="true" />
                    Desvincular
                </Button>
            </template>

            <div
                class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <UserAvatar
                    :name="account.name"
                    :subtitle="account.email"
                    size="lg"
                    show-name
                />
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge :status="account.status" size="sm" />
                    <Badge
                        v-for="role in account.roles"
                        :key="role"
                        variant="secondary"
                        >{{ role }}</Badge
                    >
                </div>
            </div>
            <dl class="mt-5 grid gap-3 border-t pt-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">Último acceso</dt>
                    <dd class="mt-0.5 font-medium">
                        <DateTimeDisplay
                            :value="account.last_login_at"
                            fallback="Nunca"
                        />
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Correo</dt>
                    <dd class="mt-0.5 font-medium">
                        {{
                            account.email_verified
                                ? 'Verificado'
                                : 'Sin verificar'
                        }}
                    </dd>
                </div>
            </dl>
        </SectionCard>

        <SectionCard
            v-else-if="canManage"
            title="Crear acceso"
            description="Para la app y el portal del colaborador."
            :icon="KeyRound"
            divided
        >
            <form class="grid gap-5" novalidate @submit.prevent="create">
                <FormField
                    label="Correo de acceso"
                    :error="errors.email"
                    required
                    v-slot="{ id, invalid }"
                >
                    <Input
                        :id="id"
                        v-model="form.email"
                        type="email"
                        inputmode="email"
                        autocomplete="off"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>

                <FormField label="Entrega de contraseña" :error="errors.mode">
                    <RadioGroup
                        v-model="form.mode"
                        class="grid gap-3 sm:grid-cols-2"
                    >
                        <Label
                            v-for="mode in modes"
                            :key="mode.value"
                            :class="
                                cn(
                                    'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-standard hover:bg-accent',
                                    form.mode === mode.value &&
                                        'border-brand/40 bg-brand-soft',
                                )
                            "
                        >
                            <RadioGroupItem
                                :value="mode.value"
                                class="mt-0.5"
                            />
                            <span class="grid gap-1">
                                <span
                                    class="flex items-center gap-1.5 text-sm font-semibold"
                                >
                                    <component
                                        :is="mode.icon"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ mode.title }}
                                </span>
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >{{ mode.text }}</span
                                >
                            </span>
                        </Label>
                    </RadioGroup>
                </FormField>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.email"
                    >
                        <Spinner v-if="form.processing" />
                        <KeyRound v-else aria-hidden="true" />
                        Crear acceso
                    </Button>
                </div>
            </form>
        </SectionCard>

        <EmptyState
            v-else
            :icon="KeyRound"
            bordered
            class="rounded-2xl"
            title="Sin cuenta de acceso"
            description="Un administrador puede crearla."
        />

        <ConfirmDialog
            v-model:open="unlinkOpen"
            title="Desvincular cuenta"
            description="El usuario deja de estar asociado a este expediente. Sus registros se conservan."
            confirm-label="Desvincular"
            tone="danger"
            :icon="Unlink"
            :loading="unlinking"
            :close-on-confirm="false"
            @confirm="unlink"
        />
    </div>
</template>
