<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CheckCheck,
    Inbox,
    Megaphone,
    RotateCcw,
    Send,
    TriangleAlert,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AnnouncementController from '@/actions/App/Http/Controllers/Web/Comunicados/AnnouncementController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep, Tone } from '@/types';
import type { AnnouncementShowProps, PushStatus, RecipientRow } from './types';

const props = defineProps<AnnouncementShowProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Comunicados', href: '/comunicados' },
            { title: 'Detalle' },
        ],
    },
});

const help: HelpStep[] = [
    {
        title: 'Entregado',
        text: 'Quedó en la bandeja de la app del colaborador.',
        icon: Inbox,
    },
    {
        title: 'Leído',
        text: 'El colaborador lo abrió en la app.',
        icon: CheckCheck,
    },
    {
        title: 'Push',
        text: 'Aviso al teléfono. «Sin dispositivo»: no tiene la app con avisos activos.',
        icon: Send,
    },
    {
        title: 'Retirar',
        text: 'Deja de mostrarse en la app; se conserva el historial.',
        icon: Undo2,
    },
];

const a = computed(() => props.announcement);

const { filters } = useFilters(
    { state: props.filters.state ?? null },
    { only: ['recipients', 'filters'] },
);

const stateOptions = [
    { value: 'read', label: 'Leídos' },
    { value: 'unread', label: 'Sin leer' },
    { value: 'failed', label: 'Fallidos' },
    ...(props.announcement.requires_confirmation
        ? [{ value: 'unconfirmed', label: 'Sin confirmar' }]
        : []),
];

const PUSH: Record<PushStatus, { label: string; tone: Tone }> = {
    pending: { label: 'En cola', tone: 'info' },
    sent: { label: 'Enviado', tone: 'success' },
    no_device: { label: 'Sin dispositivo', tone: 'neutral' },
    disabled: { label: 'Sin configurar', tone: 'neutral' },
    failed: { label: 'Falló', tone: 'danger' },
};

const columns: DataTableColumn<RecipientRow>[] = [
    { key: 'employee', label: 'Colaborador', mobile: 'title' },
    { key: 'delivered_at', label: 'Entregado', hideBelow: 'md' },
    { key: 'read_at', label: 'Leído' },
    ...(props.announcement.requires_confirmation
        ? [{ key: 'confirmed_at', label: 'Confirmó', hideBelow: 'sm' as const }]
        : []),
    { key: 'push_status', label: 'Push', class: 'w-36' },
];

const statusBadge = computed(() =>
    a.value.status === 'withdrawn'
        ? { label: 'Retirado', tone: 'neutral' as Tone }
        : a.value.status === 'scheduled'
          ? { label: 'Programado', tone: 'info' as Tone }
          : { label: 'Enviado', tone: 'success' as Tone },
);

const confirmOpen = ref(false);
const busy = ref(false);

function toggleWithdrawn(): void {
    const action =
        a.value.status === 'withdrawn'
            ? AnnouncementController.reactivate(a.value.id)
            : AnnouncementController.withdraw(a.value.id);
    router.visit(action, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            :title="a.title"
            :icon="Megaphone"
            :help="help"
            :back-href="AnnouncementController.index()"
            back-label="Comunicados"
        >
            <template #meta>
                <StatusBadge
                    :label="statusBadge.label"
                    :tone="statusBadge.tone"
                    size="sm"
                />
                <Badge v-if="a.priority === 'high'" variant="destructive"
                    >Alta</Badge
                >
                <span class="text-sm text-muted-foreground">
                    {{ a.audience_label
                    }}<template v-if="a.audience_names.length"
                        >: {{ a.audience_names.join(', ') }}</template
                    >
                </span>
            </template>
            <template v-if="can.send" #actions>
                <Button
                    variant="outline"
                    :disabled="busy"
                    @click="confirmOpen = true"
                >
                    <RotateCcw
                        v-if="a.status === 'withdrawn'"
                        aria-hidden="true"
                    />
                    <Undo2 v-else aria-hidden="true" />
                    {{ a.status === 'withdrawn' ? 'Reactivar' : 'Retirar' }}
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <SectionCard title="Mensaje">
                <p class="text-sm break-words whitespace-pre-line">
                    {{ a.body }}
                </p>
                <template #footer>
                    <p class="text-xs text-muted-foreground">
                        {{ a.created_by ?? 'Sistema' }} ·
                        <template v-if="a.status === 'scheduled'"
                            >programado para
                            <DateTimeDisplay :value="a.scheduled_at"
                        /></template>
                        <template v-else
                            >enviado
                            <DateTimeDisplay :value="a.sent_at ?? a.created_at"
                        /></template>
                        <template v-if="a.withdrawn_at">
                            · retirado
                            <DateTimeDisplay :value="a.withdrawn_at" /><template
                                v-if="a.withdrawn_by"
                            >
                                por {{ a.withdrawn_by }}</template
                            ></template
                        >
                    </p>
                </template>
            </SectionCard>

            <div class="grid grid-cols-2 gap-3">
                <KpiTile
                    label="Entregados"
                    :value="a.stats.delivered"
                    :hint="`de ${a.stats.total}`"
                    :icon="Inbox"
                />
                <KpiTile
                    label="Leídos"
                    :value="a.stats.read"
                    :icon="CheckCheck"
                    tone="success"
                />
                <KpiTile
                    v-if="a.requires_confirmation"
                    label="Confirmaron"
                    :value="a.stats.confirmed"
                    :hint="`de ${a.stats.total}`"
                    :icon="CheckCheck"
                    tone="success"
                />
                <KpiTile label="Push" :value="a.stats.push_sent" :icon="Send" />
                <KpiTile
                    label="Fallidos"
                    :value="a.stats.failed"
                    :icon="TriangleAlert"
                    :tone="a.stats.failed > 0 ? 'danger' : 'neutral'"
                />
            </div>
        </div>

        <SectionCard title="Destinatarios" padding="none">
            <template #actions>
                <SelectField
                    v-model="filters.state"
                    :options="stateOptions"
                    placeholder="Todos"
                    clearable
                    size="sm"
                    class="w-36"
                />
            </template>
            <DataTable
                :rows="recipients.data"
                :columns="columns"
                :pagination="toPaginationMeta(recipients)"
                :only="['recipients', 'filters']"
                :card="false"
                :empty-icon="Inbox"
                :empty-title="
                    a.status === 'scheduled'
                        ? 'Aún no se envía'
                        : 'Sin destinatarios'
                "
                caption="Destinatarios"
            >
                <template #cell-employee="{ row }">
                    <div v-if="row.employee" class="min-w-0">
                        <span class="block truncate font-medium">{{
                            row.employee.name
                        }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{
                            row.employee.employee_number
                        }}</span>
                    </div>
                </template>
                <template #cell-delivered_at="{ row }">
                    <DateTimeDisplay
                        :value="row.delivered_at"
                        format="relative"
                        :fallback="
                            row.error === 'sin_cuenta'
                                ? 'Sin cuenta en la app'
                                : '—'
                        "
                    />
                </template>
                <template #cell-read_at="{ row }">
                    <DateTimeDisplay
                        :value="row.read_at"
                        format="relative"
                        fallback="—"
                    />
                </template>
                <template #cell-confirmed_at="{ row }">
                    <DateTimeDisplay
                        :value="row.confirmed_at"
                        format="relative"
                        fallback="Pendiente"
                    />
                </template>
                <template #cell-push_status="{ row }">
                    <StatusBadge
                        :label="PUSH[row.push_status].label"
                        :tone="PUSH[row.push_status].tone"
                        size="sm"
                    />
                </template>
            </DataTable>
        </SectionCard>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="
                a.status === 'withdrawn'
                    ? '¿Reactivar comunicado?'
                    : '¿Retirar comunicado?'
            "
            :description="
                a.status === 'withdrawn'
                    ? 'Volverá a mostrarse en la app.'
                    : 'Dejará de mostrarse en la app. Se conserva el historial.'
            "
            :confirm-label="a.status === 'withdrawn' ? 'Reactivar' : 'Retirar'"
            :icon="a.status === 'withdrawn' ? RotateCcw : Undo2"
            :loading="busy"
            :close-on-confirm="false"
            @confirm="toggleWithdrawn"
        />
    </div>
</template>
