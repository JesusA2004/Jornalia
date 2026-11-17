<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import {
    Download,
    FileSpreadsheet,
    FileText,
    FileType,
    History,
    Loader2,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatNumber } from '@/lib/format';
import HashChip from '@/pages/integridad/components/HashChip.vue';
import type { ReportExportRow } from '@/types/operacion';

/**
 * Exportaciones recientes del usuario. Mientras alguna esté en cola o en
 * proceso, recarga sólo la prop `exports` cada 5 s.
 */
const props = withDefaults(
    defineProps<{
        exports: ReportExportRow[];
        timezone: string;
        /** Muestra el nombre del reporte (catálogo) o sólo parámetros (detalle). */
        showTitle?: boolean;
        title?: string;
    }>(),
    { showTitle: true, title: 'Exportaciones recientes' },
);

const FORMAT = {
    xlsx: {
        label: 'Excel',
        icon: FileSpreadsheet,
        tone: 'text-success-soft-foreground bg-success-soft',
    },
    pdf: {
        label: 'PDF',
        icon: FileText,
        tone: 'text-danger-soft-foreground bg-danger-soft',
    },
    csv: {
        label: 'CSV',
        icon: FileType,
        tone: 'text-info-soft-foreground bg-info-soft',
    },
} as const;

const STATUS_LABEL: Record<ReportExportRow['status'], string> = {
    queued: 'En cola',
    processing: 'Generando',
    completed: 'Lista',
    failed: 'Fallida',
};

const active = computed(() =>
    props.exports.some(
        (e) => e.status === 'queued' || e.status === 'processing',
    ),
);

const { start, stop } = usePoll(
    5000,
    { only: ['exports'] },
    { autoStart: false },
);

watch(active, (value) => (value ? start() : stop()), { immediate: true });

function period(e: ReportExportRow): string | null {
    const from = e.parameters?.from;
    const to = e.parameters?.to;
    if (typeof from !== 'string' || typeof to !== 'string') {
        return null;
    }

    return `${formatDate(from, 'date-short')} – ${formatDate(to, 'date-short')}`;
}
</script>

<template>
    <SectionCard :title="title" :icon="History" padding="none" divided>
        <template v-if="active" #actions>
            <span
                class="inline-flex items-center gap-1.5 rounded-full bg-info-soft px-2.5 py-1 text-xs font-medium text-info-soft-foreground"
            >
                <Loader2 class="size-3.5 animate-spin" aria-hidden="true" />
                Generando…
            </span>
        </template>

        <EmptyState
            v-if="exports.length === 0"
            :icon="History"
            size="sm"
            title="Aún no hay exportaciones"
            description="Los archivos que generes aparecerán aquí."
            class="py-10"
        />

        <ul v-else class="divide-y">
            <li
                v-for="item in exports"
                :key="item.id"
                class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center"
            >
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span
                        :class="[
                            'flex size-9 shrink-0 items-center justify-center rounded-lg',
                            FORMAT[item.format].tone,
                        ]"
                    >
                        <component
                            :is="FORMAT[item.format].icon"
                            class="size-4.5"
                            aria-hidden="true"
                        />
                    </span>
                    <div class="min-w-0 space-y-0.5">
                        <p class="truncate text-sm font-semibold">
                            <template v-if="showTitle"
                                >{{ item.title }} · </template
                            >{{ FORMAT[item.format].label }}
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground [&>*+*]:before:mr-1.5 [&>*+*]:before:content-['·']"
                        >
                            <span v-if="period(item)">{{ period(item) }}</span>
                            <span v-if="item.row_count !== null"
                                >{{ formatNumber(item.row_count) }}
                                {{
                                    item.row_count === 1 ? 'fila' : 'filas'
                                }}</span
                            >
                            <DateTimeDisplay
                                :value="item.created_at"
                                :timezone="timezone"
                                format="relative"
                            />
                        </p>
                        <p
                            v-if="item.status === 'failed' && item.error"
                            class="text-xs text-danger-soft-foreground"
                        >
                            {{ item.error }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <HashChip
                        v-if="item.sha256"
                        :value="item.sha256"
                        label="Copiar SHA-256"
                        :head="8"
                        :tail="4"
                    />
                    <StatusBadge
                        :status="item.status"
                        :label="STATUS_LABEL[item.status]"
                        size="sm"
                    />
                    <Button
                        v-if="item.download_url"
                        as="a"
                        :href="item.download_url"
                        size="sm"
                        variant="outline"
                    >
                        <Download aria-hidden="true" />
                        Descargar
                    </Button>
                </div>
            </li>
        </ul>
    </SectionCard>
</template>
