<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Palmtree, Send } from '@lucide/vue';
import { computed } from 'vue';
import VacationController from '@/actions/App/Http/Controllers/Web/Operacion/VacationController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DateRangePicker from '@/components/app/DateRangePicker.vue';
import FormField from '@/components/app/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types';

/**
 * Solicitud de vacaciones. Con `vacations.create` se elige colaborador; si no,
 * sólo la propia. Los días laborables y el saldo los calcula el servidor.
 */
const props = defineProps<{
    employees: SelectOption[];
    canChooseEmployee: boolean;
    ownEmployeeId: number | null;
    today: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    employee_id: props.ownEmployeeId
        ? String(props.ownEmployeeId)
        : (null as string | null),
    starts_on: null as string | null,
    ends_on: null as string | null,
    comment: '',
});

const employeeOptions = computed(() =>
    props.canChooseEmployee
        ? props.employees
        : props.employees.filter(
              (e) => e.value === String(props.ownEmployeeId),
          ),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        ends_on: data.ends_on ?? data.starts_on,
        comment: data.comment || null,
    })).post(VacationController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('starts_on', 'ends_on', 'comment');
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
            <SheetHeader class="border-b p-5">
                <SheetTitle
                    class="flex items-center gap-2 font-display text-lg"
                >
                    <Palmtree
                        class="size-5 text-brand-strong"
                        aria-hidden="true"
                    />
                    Solicitar vacaciones
                </SheetTitle>
                <SheetDescription
                    >Se descuentan sólo días laborables
                    programados.</SheetDescription
                >
            </SheetHeader>
            <form
                id="vacation-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    label="Colaborador"
                    required
                    :error="form.errors.employee_id"
                    v-slot="{ id, invalid }"
                >
                    <ComboboxField
                        :id="id"
                        v-model="form.employee_id"
                        :options="employeeOptions"
                        :invalid="invalid"
                        :disabled="!canChooseEmployee"
                        placeholder="Busca por nombre o número"
                    />
                </FormField>
                <FormField
                    label="Periodo"
                    required
                    :error="form.errors.starts_on ?? form.errors.ends_on"
                    v-slot="{ id, invalid }"
                >
                    <DateRangePicker
                        :id="id"
                        v-model:start="form.starts_on"
                        v-model:end="form.ends_on"
                        :min="today"
                        :invalid="invalid"
                        :presets="false"
                    />
                </FormField>
                <FormField
                    label="Comentario"
                    hint="Opcional"
                    :error="form.errors.comment"
                    v-slot="{ id }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.comment"
                        rows="3"
                        maxlength="1000"
                        placeholder="Algo que deba saber quien aprueba…"
                    />
                </FormField>
            </form>
            <SheetFooter class="border-t p-5">
                <Button
                    type="submit"
                    form="vacation-form"
                    size="lg"
                    class="w-full"
                    :disabled="form.processing || !form.employee_id"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else aria-hidden="true" />
                    Enviar solicitud
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
