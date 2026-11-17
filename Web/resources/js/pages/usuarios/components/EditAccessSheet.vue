<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Save, ShieldCheck } from '@lucide/vue';
import { watch } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Web/Admin/UserController';
import FormField from '@/components/app/FormField.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import type { SelectOption } from '@/types';
import type { OrgUserRow } from '../types';
import ScopeFields from './ScopeFields.vue';

const props = defineProps<{
    user: OrgUserRow | null;
    roles: SelectOption[];
    branches: SelectOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    status: 'active' as 'active' | 'inactive',
    roles: [] as string[],
    access_all_branches: false,
    branch_ids: [] as string[],
});

watch(open, (value) => {
    if (value && props.user) {
        form.defaults({
            status: props.user.status,
            roles: [...props.user.roles],
            access_all_branches: props.user.access_all_branches,
            branch_ids: [...props.user.branch_ids],
        });
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    if (!props.user) {
        return;
    }

    form.put(UserController.update.url(props.user.id), {
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
                    <ShieldCheck
                        class="size-5 text-brand-strong"
                        aria-hidden="true"
                    />
                    Accesos
                </SheetTitle>
            </SheetHeader>

            <form
                v-if="user"
                id="edit-access-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <div class="rounded-xl border bg-surface-2 p-3">
                    <UserAvatar
                        :name="user.name"
                        :subtitle="user.email"
                        show-name
                    />
                </div>

                <Alert v-if="form.errors.roles" variant="destructive">
                    <AlertDescription>{{ form.errors.roles }}</AlertDescription>
                </Alert>

                <FormField
                    label="Acceso activo"
                    orientation="horizontal"
                    :description="
                        user.is_self
                            ? 'No puedes desactivar tu propio acceso.'
                            : undefined
                    "
                    :error="form.errors.status"
                    v-slot="{ id }"
                >
                    <Switch
                        :id="id"
                        :disabled="user.is_self"
                        :model-value="form.status === 'active'"
                        @update:model-value="
                            (v: boolean) =>
                                (form.status = v ? 'active' : 'inactive')
                        "
                    />
                </FormField>

                <ScopeFields
                    v-model:roles="form.roles"
                    v-model:all-branches="form.access_all_branches"
                    v-model:branch-ids="form.branch_ids"
                    :role-options="roles"
                    :branch-options="branches"
                    :errors="{ ...form.errors, roles: undefined }"
                />
            </form>

            <SheetFooter class="flex-row justify-end gap-2 border-t">
                <Button variant="outline" type="button" @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="edit-access-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else />
                    Guardar
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
