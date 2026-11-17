<script setup lang="ts">
import UserAvatar from '@/components/app/UserAvatar.vue';
import type { User } from '@/types';

type Props = {
    user: User;
    showEmail?: boolean;
};

withDefaults(defineProps<Props>(), {
    showEmail: false,
});
</script>

<template>
    <UserAvatar
        :name="user.name"
        :src="user.avatar || null"
        size="sm"
        shape="rounded"
    />
    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-semibold">{{ user.name }}</span>
        <span
            v-if="showEmail"
            class="truncate text-xs text-muted-foreground in-data-[sidebar=sidebar]:text-sidebar-foreground/60"
            >{{ user.email }}</span
        >
    </div>
</template>
