<script setup lang="ts">
import UserAvatar from '@/components/app/UserAvatar.vue';
import type { EmployeeCard } from '@/types/operacion';

/** Colaborador en tablas: avatar, nombre y número / sucursal. */
withDefaults(
    defineProps<{
        employee: EmployeeCard | null;
        size?: 'xs' | 'sm' | 'md' | 'lg';
    }>(),
    { size: 'sm' },
);
</script>

<template>
    <UserAvatar
        v-if="employee"
        :name="employee.name"
        :subtitle="
            [employee.number, employee.branch].filter(Boolean).join(' · ')
        "
        :size="size"
        show-name
    />
    <span v-else class="text-sm text-muted-foreground">—</span>
</template>
