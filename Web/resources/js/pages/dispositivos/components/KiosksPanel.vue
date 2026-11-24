<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Ban, Copy, KeyRound, MonitorSmartphone, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import KioskController from '@/actions/App/Http/Controllers/Web/Admin/KioskController';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types';
import type { KioskActivationFlash, KioskRow, KioskStatus } from '../types';

/**
 * Kioscos de sucursal (tableta compartida) para quien no tiene celular.
 * El código de activación es de un solo uso y se muestra una sola vez.
 */
const props = defineProps<{
    kiosks: KioskRow[];
    branches: SelectOption[];
    activation: KioskActivationFlash | null;
    canManage: boolean;
}>();

const TONE: Record<KioskStatus, string> = {
    pending: 'pending',
    active: 'approved',
    revoked: 'rejected',
};

/* ------------------------------------------------------------ Alta -- */
const createOpen = ref(false);
const form = useForm({ name: '', branch_id: null as string | null });

function openCreate(): void {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
}

function submit(): void {
    form.submit(KioskController.store(), {
        preserveScroll: true,
        onSuccess: () => (createOpen.value = false),
    });
}

/* ------------------------------------------------ Código de un uso -- */
const codeOpen = ref(false);

watch(
    () => props.activation,
    (value) => {
        if (value) {
            codeOpen.value = true;
        }
    },
    { immediate: true },
);

async function copyCode(): Promise<void> {
    if (!props.activation) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.activation.code);
        toast.success('Código copiado.');
    } catch {
        toast.error('No se pudo copiar.');
    }
}

function regenerate(kiosk: KioskRow): void {
    router.visit(KioskController.code(kiosk.id), { preserveScroll: true });
}

/* --------------------------------------------------------- Revocar -- */
const revokeOpen = ref(false);
const target = ref<KioskRow | null>(null);
const revokeForm = useForm({ reason: '' });

function askRevoke(kiosk: KioskRow): void {
    target.value = kiosk;
    revokeForm.reset();
    revokeForm.clearErrors();
    revokeOpen.value = true;
}

function submitRevoke(): void {
    if (!target.value) {
        return;
    }

    revokeForm.submit(KioskController.revoke(target.value.id), {
        preserveScroll: true,
        onSuccess: () => (revokeOpen.value = false),
    });
}
</script>

<template>
    <SectionCard
        title="Kioscos de sucursal"
        description="Tableta compartida: número de empleado + NIP."
        :icon="MonitorSmartphone"
        padding="none"
        divided
    >
        <template v-if="canManage" #actions>
            <Button size="sm" variant="outline" @click="openCreate">
                <Plus aria-hidden="true" />
                Nuevo kiosco
            </Button>
        </template>

        <EmptyState
            v-if="kiosks.length === 0"
            :icon="MonitorSmartphone"
            title="Sin kioscos"
            description="Para personal sin celular."
            class="py-10"
        />

        <ul v-else class="divide-y">
            <li
                v-for="kiosk in kiosks"
                :key="kiosk.id"
                class="flex flex-wrap items-center gap-3 px-5 py-3"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">{{ kiosk.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ kiosk.branch ?? '—' }}
                        <span v-if="kiosk.model">· {{ kiosk.model }}</span>
                        <span v-if="kiosk.os_version">
                            · {{ kiosk.platform }} {{ kiosk.os_version }}</span
                        >
                        <span v-if="kiosk.app_version">
                            · app {{ kiosk.app_version }}</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Última actividad:
                        <DateTimeDisplay
                            :value="kiosk.last_seen_at"
                            format="relative"
                            fallback="Nunca"
                        />
                    </p>
                </div>
                <StatusBadge
                    :status="TONE[kiosk.status]"
                    :label="kiosk.status_label"
                    size="sm"
                />
                <div
                    v-if="canManage && kiosk.status !== 'revoked'"
                    class="flex gap-1"
                >
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="regenerate(kiosk)"
                    >
                        <KeyRound aria-hidden="true" />
                        Código
                    </Button>
                    <Button size="sm" variant="ghost" @click="askRevoke(kiosk)">
                        <Ban aria-hidden="true" />
                        Revocar
                    </Button>
                </div>
            </li>
        </ul>
    </SectionCard>

    <Dialog v-model:open="createOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Nuevo kiosco</DialogTitle>
                <DialogDescription
                    >Se genera un código de activación de un solo
                    uso.</DialogDescription
                >
            </DialogHeader>
            <form id="kiosk-form" class="grid gap-4" @submit.prevent="submit">
                <FormField
                    label="Nombre"
                    :error="form.errors.name"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        placeholder="Tableta recepción"
                    />
                </FormField>
                <FormField
                    label="Sucursal"
                    :error="form.errors.branch_id"
                    required
                    v-slot="{ id }"
                >
                    <SelectField
                        :id="id"
                        v-model="form.branch_id"
                        :options="branches"
                        placeholder="Elige la sucursal"
                    />
                </FormField>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="createOpen = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="kiosk-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    Crear
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="codeOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Código de activación</DialogTitle>
                <DialogDescription
                    >{{ activation?.name }} · se muestra una sola
                    vez.</DialogDescription
                >
            </DialogHeader>
            <p
                class="rounded-xl bg-surface-2 py-4 text-center font-mono text-3xl font-semibold tracking-widest"
            >
                {{ activation?.code }}
            </p>
            <p
                v-if="activation?.expires_at"
                class="text-center text-xs text-muted-foreground"
            >
                Vence
                <DateTimeDisplay
                    :value="activation.expires_at"
                    format="relative"
                />
            </p>
            <DialogFooter>
                <Button variant="outline" @click="copyCode">
                    <Copy aria-hidden="true" />
                    Copiar
                </Button>
                <Button @click="codeOpen = false">Listo</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="revokeOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Revocar «{{ target?.name }}»</DialogTitle>
                <DialogDescription
                    >La tableta deja de registrar marcajes de
                    inmediato.</DialogDescription
                >
            </DialogHeader>
            <FormField
                label="Motivo"
                :error="revokeForm.errors.reason"
                required
                v-slot="{ id }"
            >
                <Textarea :id="id" v-model="revokeForm.reason" rows="3" />
            </FormField>
            <DialogFooter>
                <Button variant="outline" @click="revokeOpen = false"
                    >Cancelar</Button
                >
                <Button
                    variant="destructive"
                    :disabled="
                        revokeForm.processing ||
                        revokeForm.reason.trim().length < 5
                    "
                    @click="submitRevoke"
                >
                    <Spinner v-if="revokeForm.processing" class="size-4" />
                    Revocar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
