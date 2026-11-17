<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlarmClock, LogOut } from '@lucide/vue';
import { watch } from 'vue';
import AnnouncementController from '@/actions/App/Http/Controllers/Web/Comunicados/AnnouncementController';
import FormField from '@/components/app/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import type { ReminderSettings } from '../types';
import NumberInput from './NumberInput.vue';

const props = defineProps<{ settings: ReminderSettings }>();
const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    late_enabled: props.settings.late.enabled,
    late_grace_minutes: props.settings.late.grace_minutes as number | null,
    clock_out_enabled: props.settings.clock_out.enabled,
    clock_out_minutes_after: props.settings.clock_out.minutes_after as
        | number
        | null,
});

watch(open, (value) => {
    if (value) {
        form.defaults({
            late_enabled: props.settings.late.enabled,
            late_grace_minutes: props.settings.late.grace_minutes,
            clock_out_enabled: props.settings.clock_out.enabled,
            clock_out_minutes_after: props.settings.clock_out.minutes_after,
        });
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.submit(AnnouncementController.updateSettings(), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Avisos automáticos</DialogTitle>
                <DialogDescription
                    >Recordatorios que recibe cada colaborador en la
                    app.</DialogDescription
                >
            </DialogHeader>

            <form
                id="reminder-settings"
                class="space-y-5"
                @submit.prevent="submit"
            >
                <div class="space-y-3 rounded-xl border p-4">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2 font-medium">
                            <AlarmClock
                                class="icon-sm text-muted-foreground"
                                aria-hidden="true"
                            />
                            Entrada pendiente
                        </span>
                        <Switch
                            v-model="form.late_enabled"
                            aria-label="Entrada pendiente"
                        />
                    </div>
                    <FormField
                        label="Minutos de gracia"
                        hint="Después de la tolerancia del horario."
                        :error="form.errors.late_grace_minutes"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form.late_grace_minutes"
                            :min="0"
                            :max="120"
                            suffix="min"
                            :disabled="!form.late_enabled"
                        />
                    </FormField>
                </div>

                <div class="space-y-3 rounded-xl border p-4">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2 font-medium">
                            <LogOut
                                class="icon-sm text-muted-foreground"
                                aria-hidden="true"
                            />
                            Salida pendiente
                        </span>
                        <Switch
                            v-model="form.clock_out_enabled"
                            aria-label="Salida pendiente"
                        />
                    </div>
                    <FormField
                        label="Minutos tras el fin de turno"
                        :error="form.errors.clock_out_minutes_after"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form.clock_out_minutes_after"
                            :min="0"
                            :max="240"
                            suffix="min"
                            :disabled="!form.clock_out_enabled"
                        />
                    </FormField>
                </div>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="reminder-settings"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
