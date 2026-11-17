<script setup lang="ts">
import { Building, Globe2 } from '@lucide/vue';
import ComboboxField from '@/components/app/ComboboxField.vue';
import FormField from '@/components/app/FormField.vue';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';

/** Roles + alcance por sucursal (compartido por alta y edición). */
defineProps<{
    roleOptions: SelectOption[];
    branchOptions: SelectOption[];
    errors: Partial<Record<string, string>>;
}>();

const selectedRoles = defineModel<string[]>('roles', { required: true });
const allBranches = defineModel<boolean>('allBranches', { required: true });
const branchIds = defineModel<string[]>('branchIds', { required: true });

function setRoles(value: string | string[] | null): void {
    selectedRoles.value = Array.isArray(value) ? value : value ? [value] : [];
}

function setBranches(value: string | string[] | null): void {
    branchIds.value = Array.isArray(value) ? value : value ? [value] : [];
}

const scopes = [
    { value: true, title: 'Todas las sucursales', icon: Globe2 },
    { value: false, title: 'Sucursales específicas', icon: Building },
] as const;
</script>

<template>
    <FormField
        label="Roles"
        :error="errors.roles ?? errors['roles.0']"
        v-slot="{ id, describedBy, invalid }"
    >
        <ComboboxField
            :id="id"
            multiple
            :options="roleOptions"
            placeholder="Sin roles"
            search-placeholder="Buscar rol…"
            :invalid="invalid"
            :aria-describedby="describedBy"
            :model-value="selectedRoles"
            @update:model-value="setRoles"
        />
    </FormField>

    <FormField label="Alcance de datos" :error="errors.access_all_branches">
        <div
            class="grid grid-cols-2 gap-2"
            role="radiogroup"
            aria-label="Alcance de datos"
        >
            <button
                v-for="scope in scopes"
                :key="String(scope.value)"
                type="button"
                role="radio"
                :aria-checked="allBranches === scope.value"
                :class="
                    cn(
                        'flex items-center gap-2.5 rounded-xl border px-3 py-2.5 text-left text-sm font-medium transition-standard',
                        allBranches === scope.value
                            ? 'border-brand-strong bg-brand-soft text-brand-soft-foreground shadow-xs'
                            : 'border-border hover:bg-accent',
                    )
                "
                @click="allBranches = scope.value"
            >
                <component
                    :is="scope.icon"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ scope.title }}
            </button>
        </div>
    </FormField>

    <FormField
        v-if="!allBranches"
        label="Sucursales"
        description="También verá a sus reportes directos y su propio expediente."
        :error="errors.branch_ids ?? errors['branch_ids.0']"
        v-slot="{ id, describedBy, invalid }"
    >
        <ComboboxField
            :id="id"
            multiple
            :options="branchOptions"
            placeholder="Selecciona sucursales"
            search-placeholder="Buscar sucursal…"
            :invalid="invalid"
            :aria-describedby="describedBy"
            :model-value="branchIds"
            @update:model-value="setBranches"
        />
    </FormField>
</template>
