<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Eye, Landmark, Send } from '@lucide/vue';
import { computed, watch } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Web/Admin/UserController';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import AccessModeField from './AccessModeField.vue';

/** Acceso de sólo lectura para inspectores de la autoridad laboral. */
const open = defineModel<boolean>('open', { default: false });

const today = new Date().toISOString().slice(0, 10);
// Vigencia máxima: 365 días (UserAccessService::AUTHORITY_MAX_DAYS).
const maxDate = new Date(Date.now() + 365 * 86400000)
    .toISOString()
    .slice(0, 10);

const form = useForm({
    name: '',
    email: '',
    expires_on: null as string | null,
    mode: 'reset' as 'password' | 'reset',
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

/** El alta reutiliza la de usuarios: los errores de rol llegan bajo `roles`. */
const roleError = computed(
    () => (form.errors as Record<string, string | undefined>).roles ?? null,
);

function submit(): void {
    form.post(UserController.storeAuthority.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <div
                    class="mb-1 flex size-11 items-center justify-center rounded-2xl bg-primary text-primary-foreground"
                >
                    <Landmark class="size-5" aria-hidden="true" />
                </div>
                <DialogTitle>Acceso para autoridad laboral</DialogTitle>
                <DialogDescription
                    >Sólo lectura: consulta y exporta
                    registros.</DialogDescription
                >
            </DialogHeader>

            <form
                id="authority-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <Alert v-if="roleError" variant="destructive">
                    <AlertDescription>{{ roleError }}</AlertDescription>
                </Alert>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Nombre"
                        required
                        :error="form.errors.name"
                        class="sm:col-span-2"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.name"
                            placeholder="Inspector(a) federal del trabajo"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Correo"
                        required
                        :error="form.errors.email"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <Input
                            :id="id"
                            v-model="form.email"
                            type="email"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid || undefined"
                        />
                    </FormField>
                    <FormField
                        label="Vigencia hasta"
                        hint="Obligatoria"
                        :error="form.errors.expires_on"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="form.expires_on"
                            :min="today"
                            :max="maxDate"
                            placeholder="Elige la fecha"
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </div>

                <AccessModeField
                    v-model="form.mode"
                    :error="form.errors.mode"
                />

                <p
                    class="flex items-center gap-2 rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted-foreground"
                >
                    <Eye class="size-4 shrink-0" aria-hidden="true" />
                    Rol «Autoridad laboral» en todas las sucursales, sin
                    permisos de escritura.
                </p>
            </form>

            <DialogFooter>
                <Button variant="outline" type="button" @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="authority-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else />
                    Crear acceso
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
