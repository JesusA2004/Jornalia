<script setup lang="ts">
import {
    Bot,
    Check,
    CircleAlert,
    Clock,
    LogIn,
    LogOut,
    Coffee,
    RefreshCw,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import RectificationController from '@/actions/App/Http/Controllers/Web/Operacion/RectificationController';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    DayPunchesResponse,
    DayPunchOption,
    PunchType,
} from '@/types/operacion';

/**
 * Selector visual de los marcajes de un colaborador en un día
 * ("Entrada 09:02 · App móvil"). Consulta al servidor con AccessScope.
 */
const props = defineProps<{
    employeeId: string | null;
    date: string | null;
    invalid?: boolean;
    id?: string;
}>();

const model = defineModel<string>({ default: '' });

const loading = ref(false);
const failed = ref<string | null>(null);
const data = ref<DayPunchesResponse | null>(null);

const ICONS: Record<PunchType, LucideIcon> = {
    clock_in: LogIn,
    clock_out: LogOut,
    break_start: Coffee,
    break_end: Clock,
};

let controller: AbortController | null = null;

async function load(): Promise<void> {
    controller?.abort();
    data.value = null;
    failed.value = null;
    if (!props.employeeId || !props.date) {
        return;
    }
    controller = new AbortController();
    loading.value = true;
    try {
        const response = await fetch(
            RectificationController.punches.url({
                query: { employee_id: props.employeeId, date: props.date },
            }),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        );
        if (!response.ok) {
            failed.value =
                response.status === 404 || response.status === 403
                    ? 'No tienes acceso a los marcajes de este colaborador.'
                    : 'No se pudieron cargar los marcajes.';
            return;
        }
        data.value = (await response.json()) as DayPunchesResponse;
        if (
            model.value &&
            !data.value.punches.some((p) => p.uuid === model.value)
        ) {
            model.value = '';
        }
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            failed.value = 'No se pudieron cargar los marcajes.';
        }
    } finally {
        loading.value = false;
    }
}

watch(() => [props.employeeId, props.date], load, { immediate: true });

const punches = computed<DayPunchOption[]>(() => data.value?.punches ?? []);

function time(p: DayPunchOption): string {
    return formatDateTime(p.time, {
        format: 'time',
        timeZone: data.value?.timezone,
    });
}

function toggle(uuid: string): void {
    model.value = model.value === uuid ? '' : uuid;
}
</script>

<template>
    <div
        :id="id"
        role="radiogroup"
        :aria-invalid="invalid || undefined"
        class="grid gap-2"
    >
        <p
            v-if="!employeeId || !date"
            class="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
        >
            Elige colaborador y fecha para ver sus marcajes.
        </p>

        <template v-else-if="loading">
            <Skeleton v-for="i in 3" :key="i" class="h-12 w-full rounded-lg" />
        </template>

        <div
            v-else-if="failed"
            class="flex items-center justify-between gap-3 rounded-lg border border-danger-soft bg-danger-soft/40 px-3 py-3 text-sm"
        >
            <span class="flex items-center gap-2"
                ><CircleAlert class="size-4" aria-hidden="true" />{{
                    failed
                }}</span
            >
            <Button type="button" size="sm" variant="ghost" @click="load"
                ><RefreshCw aria-hidden="true" />Reintentar</Button
            >
        </div>

        <p
            v-else-if="punches.length === 0"
            class="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
        >
            Sin marcajes ese día. Usa «Marcaje omitido» para proponer uno.
        </p>

        <template v-else>
            <button
                v-for="p in punches"
                :key="p.uuid"
                type="button"
                role="radio"
                :aria-checked="model === p.uuid"
                :class="
                    cn(
                        'group flex w-full items-center gap-3 rounded-lg border bg-card px-3 py-2.5 text-left transition',
                        'hover:border-primary/50 hover:bg-accent/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        model === p.uuid &&
                            'border-primary bg-primary-soft/40 ring-1 ring-primary',
                        p.is_system && 'border-dashed',
                        p.voided && 'opacity-60',
                        invalid && model !== p.uuid && 'border-danger/50',
                    )
                "
                @click="toggle(p.uuid)"
            >
                <span
                    :class="
                        cn(
                            'flex size-9 shrink-0 items-center justify-center rounded-md',
                            p.is_system
                                ? 'bg-info-soft text-info-soft-foreground'
                                : 'bg-muted text-foreground',
                        )
                    "
                >
                    <component
                        :is="p.is_system ? Bot : ICONS[p.type]"
                        class="size-4"
                        aria-hidden="true"
                    />
                </span>
                <span class="min-w-0 flex-1">
                    <span
                        class="flex items-baseline gap-1.5 text-sm font-medium"
                    >
                        {{ p.type_label }}
                        <span class="numeric font-semibold">{{ time(p) }}</span>
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{
                            p.is_system
                                ? 'Generado por sistema (histórico)'
                                : p.source_label
                        }}
                        · {{ p.auth_method_label }}
                        <template v-if="p.status === 'flagged'">
                            ·
                            <span class="text-warning-soft-foreground"
                                >En revisión</span
                            ></template
                        >
                        <template v-if="p.voided">
                            · Excluido del cálculo</template
                        >
                    </span>
                </span>
                <Check
                    v-if="model === p.uuid"
                    class="size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
            </button>
        </template>
    </div>
</template>
