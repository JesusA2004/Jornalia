<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IdCard, UserPen } from '@lucide/vue';
import { computed, watch } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { todayIso } from '../lib';
import type { EmployeeShowProps } from '../types';
import FormSheet from './FormSheet.vue';

const props = defineProps<{
    employee: EmployeeShowProps['employee'];
    canSensitive: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    employee_number: '',
    first_name: '',
    last_name: '',
    second_last_name: '',
    email: '',
    phone: '',
    birth_date: null as string | null,
    update_identifiers: false,
    curp: '',
    rfc: '',
    nss: '',
});

const errors = computed(() => form.errors as Record<string, string>);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.employee_number = props.employee.employee_number;
    form.first_name = props.employee.first_name;
    form.last_name = props.employee.last_name;
    form.second_last_name = props.employee.second_last_name ?? '';
    form.email = props.employee.email ?? '';
    form.phone = props.employee.phone ?? '';
    form.birth_date = props.employee.birth_date;
    form.update_identifiers = false;
    form.curp = '';
    form.rfc = '';
    form.nss = '';
});

function submit(): void {
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };

        if (!props.canSensitive || !data.update_identifiers) {
            delete payload.curp;
            delete payload.rfc;
            delete payload.nss;
            payload.update_identifiers = false;
        }

        return payload;
    }).submit(EmployeeController.update(props.employee.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <FormSheet
        v-model:open="open"
        title="Editar datos"
        description="Datos personales y de contacto."
        :icon="UserPen"
        :processing="form.processing"
        @submit="submit"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <FormField
                label="Nombre(s)"
                :error="errors.first_name"
                required
                v-slot="{ id, invalid }"
            >
                <Input
                    :id="id"
                    v-model="form.first_name"
                    :aria-invalid="invalid || undefined"
                />
            </FormField>
            <FormField
                label="Número de empleado"
                :error="errors.employee_number"
                required
                v-slot="{ id, invalid }"
            >
                <Input
                    :id="id"
                    v-model="form.employee_number"
                    class="font-mono"
                    :aria-invalid="invalid || undefined"
                />
            </FormField>
            <FormField
                label="Apellido paterno"
                :error="errors.last_name"
                required
                v-slot="{ id, invalid }"
            >
                <Input
                    :id="id"
                    v-model="form.last_name"
                    :aria-invalid="invalid || undefined"
                />
            </FormField>
            <FormField
                label="Apellido materno"
                :error="errors.second_last_name"
                v-slot="{ id, invalid }"
            >
                <Input
                    :id="id"
                    v-model="form.second_last_name"
                    :aria-invalid="invalid || undefined"
                />
            </FormField>
            <FormField
                label="Correo"
                :error="errors.email"
                v-slot="{ id, invalid }"
            >
                <Input
                    :id="id"
                    v-model="form.email"
                    type="email"
                    :aria-invalid="invalid || undefined"
                />
            </FormField>
            <FormField
                label="Teléfono"
                :error="errors.phone"
                v-slot="{ id, invalid }"
            >
                <Input
                    :id="id"
                    v-model="form.phone"
                    type="tel"
                    :aria-invalid="invalid || undefined"
                />
            </FormField>
            <FormField
                label="Fecha de nacimiento"
                :error="errors.birth_date"
                v-slot="{ id, invalid }"
            >
                <DatePicker
                    :id="id"
                    v-model="form.birth_date"
                    :max="todayIso()"
                    dropdowns
                    clearable
                    :invalid="invalid"
                />
            </FormField>
        </div>

        <div
            v-if="canSensitive"
            class="rounded-xl border border-dashed bg-surface-2 p-4"
        >
            <div class="flex items-center justify-between gap-3">
                <Label
                    for="update-identifiers"
                    class="flex items-center gap-2 text-sm font-semibold"
                >
                    <IdCard
                        class="size-4 text-brand-strong"
                        aria-hidden="true"
                    />
                    Reemplazar identificadores
                </Label>
                <Switch
                    id="update-identifiers"
                    v-model="form.update_identifiers"
                />
            </div>
            <div
                v-if="form.update_identifiers"
                class="mt-4 grid gap-4 sm:grid-cols-3"
            >
                <FormField
                    label="CURP"
                    :error="errors.curp"
                    v-slot="{ id, invalid }"
                >
                    <Input
                        :id="id"
                        v-model="form.curp"
                        maxlength="18"
                        class="font-mono uppercase"
                        autocomplete="off"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>
                <FormField
                    label="RFC"
                    :error="errors.rfc"
                    v-slot="{ id, invalid }"
                >
                    <Input
                        :id="id"
                        v-model="form.rfc"
                        maxlength="13"
                        class="font-mono uppercase"
                        autocomplete="off"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>
                <FormField
                    label="NSS"
                    :error="errors.nss"
                    v-slot="{ id, invalid }"
                >
                    <Input
                        :id="id"
                        v-model="form.nss"
                        maxlength="11"
                        inputmode="numeric"
                        class="font-mono"
                        autocomplete="off"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>
                <p class="text-xs text-muted-foreground sm:col-span-3">
                    Los campos vacíos se borran del expediente.
                </p>
            </div>
        </div>
    </FormSheet>
</template>
