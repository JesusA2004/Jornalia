<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { ORGANIZATION_SWITCH_URL } from '@/lib/navigation';
import type { OrganizationSummary } from '@/types';

/**
 * Selector de organización activa. Lee `auth.organization` y
 * `auth.organizations`; si el backend aún no los comparte no se muestra.
 * Cambiar de empresa hace PUT a ORGANIZATION_SWITCH_URL con
 * `organization_id` (ruta a cargo del backend).
 */
const page = usePage();
const switching = ref(false);

const current = computed<OrganizationSummary | null>(
    () => page.props.auth?.organization ?? null,
);
const organizations = computed<OrganizationSummary[]>(
    () => page.props.auth?.organizations ?? [],
);
const canSwitch = computed(() => organizations.value.length > 1);

function initials(name: string): string {
    return name
        .split(/\s+/u)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => Array.from(word)[0] ?? '')
        .join('')
        .toUpperCase();
}

function switchTo(organization: OrganizationSummary): void {
    if (organization.id === current.value?.id) {
        return;
    }

    router.put(
        ORGANIZATION_SWITCH_URL,
        { organization_id: organization.id },
        {
            preserveScroll: false,
            onStart: () => {
                switching.value = true;
            },
            onFinish: () => {
                switching.value = false;
            },
        },
    );
}
</script>

<template>
    <template v-if="current">
        <DropdownMenu v-if="canSwitch">
            <DropdownMenuTrigger
                class="group flex h-9 max-w-56 min-w-0 items-center gap-2 rounded-lg border bg-card px-2 text-left text-sm shadow-elev-1 transition-standard outline-none hover:border-border-strong focus-visible:ring-[3px] focus-visible:ring-ring/40 data-[state=open]:border-border-strong dark:bg-input/30"
                aria-label="Cambiar de empresa"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-md bg-brand-soft text-[0.625rem] font-bold text-brand-soft-foreground"
                >
                    <Spinner v-if="switching" class="size-3" />
                    <template v-else>{{ initials(current.name) }}</template>
                </span>
                <span class="hidden min-w-0 truncate font-medium lg:block">{{
                    current.name
                }}</span>
                <ChevronsUpDown
                    class="ml-auto icon-xs shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-72">
                <DropdownMenuLabel
                    class="text-xs font-medium text-muted-foreground"
                >
                    Empresas
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="organization in organizations"
                    :key="organization.id"
                    class="gap-3 py-2"
                    @select="switchTo(organization)"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-md border bg-surface-2 text-[0.625rem] font-bold text-muted-foreground"
                    >
                        {{ initials(organization.name) }}
                    </span>
                    <span class="grid min-w-0 flex-1">
                        <span class="truncate font-medium">{{
                            organization.name
                        }}</span>
                        <span
                            v-if="organization.rfc || organization.legal_name"
                            class="truncate text-xs text-muted-foreground"
                            >{{
                                organization.rfc ?? organization.legal_name
                            }}</span
                        >
                    </span>
                    <Check
                        v-if="organization.id === current.id"
                        class="icon-sm text-brand-strong"
                    />
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <p class="px-2 py-1.5 text-xs text-muted-foreground">
                    Los datos mostrados corresponden a la empresa activa.
                </p>
            </DropdownMenuContent>
        </DropdownMenu>
        <div
            v-else
            class="hidden h-9 max-w-56 min-w-0 items-center gap-2 rounded-lg px-2 text-sm text-muted-foreground lg:flex"
        >
            <Building2 class="icon-sm shrink-0" aria-hidden="true" />
            <span class="truncate font-medium text-foreground/80">{{
                current.name
            }}</span>
        </div>
    </template>
</template>
