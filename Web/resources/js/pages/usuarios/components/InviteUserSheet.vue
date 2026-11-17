<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Link2, Send, UserPlus } from '@lucide/vue';
import { watch } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Web/Admin/UserController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import FormField from '@/components/app/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import type { SelectOption } from '@/types';
import type { EmployeeOption } from '../types';
import AccessModeField from './AccessModeField.vue';
import ScopeFields from './ScopeFields.vue';

const props = defineProps<{
    roles: SelectOption[];
    branches: SelectOption[];
    employees: EmployeeOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    email: '',
    mode: 'reset' as 'password' | 'reset',
    roles: [] as string[],
    access_all_branches: false,
    branch_ids: [] as string[],
    employee_id: null as string | null,
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

function linkEmployee(value: string | string[] | null): void {
    const id = Array.isArray(value) ? (value[0] ?? null) : value;
    form.employee_id = id;
    const employee = props.employees.find((e) => e.value === id);

    if (employee) {
        form.name = form.name || employee.label;
        form.email = form.email || employee.email || '';

        if (
            form.roles.length === 0 &&
            props.roles.some((r) => r.value === 'Colaborador')
        ) {
            form.roles = ['Colaborador'];
        }
    }
}

function submit(): void {
    form.post(UserController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-lg">
            <SheetHeader class="border-b">
                <SheetTitle class="flex items-center gap-2">
                    <UserPlus
                        class="size-5 text-brand-strong"
                        aria-hidden="true"
                    />
                    Invitar usuario
                </SheetTitle>
                <SheetDescription
                    >Si el correo ya tiene cuenta, se vincula.</SheetDescription
                >
            </SheetHeader>

            <form
                id="invite-user-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    v-if="employees.length > 0"
                    label="Colaborador"
                    hint="Opcional"
                    :error="form.errors.employee_id"
                    v-slot="{ id, describedBy, invalid }"
                >
                    <ComboboxField
                        :id="id"
                        :options="employees"
                        placeholder="Vincular con un expediente"
                        search-placeholder="Nombre o número…"
                        clearable
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        :model-value="form.employee_id"
                        @update:model-value="linkEmployee"
                    >
                    </ComboboxField>
                    <p
                        v-if="form.employee_id"
                        class="flex items-center gap-1.5 text-xs text-brand-strong"
                    >
                        <Link2 class="size-3.5" aria-hidden="true" />
                        Se vinculará al expediente.
                    </p>
                </FormField>

                <FormField
                    label="Nombre"
                    required
                    :error="form.errors.name"
                    v-slot="{ id, describedBy, invalid }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        autocomplete="off"
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
                        autocomplete="off"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>

                <AccessModeField
                    v-model="form.mode"
                    :error="form.errors.mode"
                />

                <ScopeFields
                    v-model:roles="form.roles"
                    v-model:all-branches="form.access_all_branches"
                    v-model:branch-ids="form.branch_ids"
                    :role-options="roles"
                    :branch-options="branches"
                    :errors="form.errors"
                />
            </form>

            <SheetFooter class="flex-row justify-end gap-2 border-t">
                <Button variant="outline" type="button" @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="invite-user-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else />
                    Invitar
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
