<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    FileClock,
    FilePen,
    Fingerprint,
    Gavel,
    History,
    Paperclip,
    ShieldCheck,
    Workflow,
} from '@lucide/vue';
import { computed } from 'vue';
import AttendanceController from '@/actions/App/Http/Controllers/Web/Operacion/AttendanceController';
import RectificationController from '@/actions/App/Http/Controllers/Web/Operacion/RectificationController';
import {
    DateTimeDisplay,
    KeyValueGrid,
    PageHeader,
    SectionCard,
    StatusBadge,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/lib/format';
import type { HelpStep, KeyValueItem } from '@/types';
import type { RectificationShowProps } from '@/types/operacion';
import AttachmentList from './components/AttachmentList.vue';
import CancelRequestButton from './components/CancelRequestButton.vue';
import DayLedger from './components/DayLedger.vue';
import DecisionHistory from './components/DecisionHistory.vue';
import DecisionPanel from './components/DecisionPanel.vue';
import EmployeeCell from './components/EmployeeCell.vue';
import LevelsStepper from './components/LevelsStepper.vue';
import { approvalTone } from './components/shared';

const props = defineProps<RectificationShowProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Rectificaciones', href: '/rectificaciones' },
            { title: 'Detalle' },
        ],
    },
});

const { can } = usePermissions();
const r = computed(() => props.request);

const details = computed<KeyValueItem[]>(() => [
    { key: 'kind', label: 'Tipo', value: r.value.kind_label },
    {
        key: 'date',
        label: 'Fecha a corregir',
        value: formatDate(r.value.date, 'date-long'),
    },
    {
        key: 'proposed',
        label: 'Propuesta',
        value: r.value.proposed_type_label ?? '—',
    },
    { key: 'time', label: 'Hora propuesta' },
    { key: 'reason', label: 'Motivo', value: r.value.reason, full: true },
    ...(r.value.review_comment
        ? [
              {
                  key: 'review',
                  label: 'Comentario de resolución',
                  value: r.value.review_comment,
                  full: true,
              },
          ]
        : []),
]);

const help: HelpStep[] = [
    {
        title: 'Tres capas',
        text: 'Original (inmutable), rectificación y horario reconocido.',
        icon: Fingerprint,
    },
    {
        title: 'Excluir del cálculo',
        text: 'Deja de contar un marcaje erróneo sin borrarlo.',
        icon: ShieldCheck,
    },
    {
        title: 'Flujo multinivel',
        text: 'La solicitud avanza nivel por nivel hasta la resolución final.',
        icon: Workflow,
    },
];
</script>

<template>
    <PageHeader
        :title="`Rectificación #${r.id}`"
        :description="r.kind_label"
        :icon="FilePen"
        :help="help"
        back-href="/rectificaciones"
        back-label="Bandeja"
    >
        <template #meta>
            <StatusBadge
                :status="r.status"
                :label="r.status_label"
                :tone="approvalTone(r.status)"
            />
            <span class="text-xs text-muted-foreground">
                Solicitada
                <DateTimeDisplay
                    :value="r.created_at"
                    :timezone="timezone"
                    format="datetime"
                />
                por {{ r.requested_by ?? '—' }}
            </span>
        </template>
        <template #actions>
            <Button
                v-if="r.employee && can('punches.view')"
                variant="outline"
                as-child
            >
                <Link
                    :href="
                        AttendanceController.show.url({
                            employee: r.employee.id,
                            date: r.date,
                        })
                    "
                >
                    <CalendarDays aria-hidden="true" />
                    Ver jornada
                </Link>
            </Button>
            <CancelRequestButton
                v-if="r.can_cancel"
                :action="RectificationController.cancel.url(r.id)"
            />
        </template>
    </PageHeader>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="grid min-w-0 content-start gap-5">
            <SectionCard title="Solicitud" :icon="FileClock" divided>
                <template #actions>
                    <EmployeeCell :employee="r.employee" />
                </template>
                <KeyValueGrid :items="details" :columns="2">
                    <template #value-time>
                        <DateTimeDisplay
                            v-if="r.proposed_time"
                            :value="r.proposed_time"
                            :timezone="day.timezone"
                            format="time"
                            class="numeric font-semibold"
                        />
                        <span v-else>—</span>
                    </template>
                </KeyValueGrid>
            </SectionCard>

            <SectionCard
                title="Jornada del día"
                :icon="Fingerprint"
                description="Original, ajustes y resultado."
                divided
            >
                <DayLedger :day="day" :highlight-uuid="target_punch?.uuid" />
            </SectionCard>

            <SectionCard title="Evidencias" :icon="Paperclip" divided>
                <AttachmentList
                    :attachments="attachments"
                    :timezone="timezone"
                />
            </SectionCard>
        </div>

        <aside class="grid content-start gap-5">
            <DecisionPanel
                v-if="r.can_decide"
                :action="RectificationController.decide.url(r.id)"
                :level-label="r.current_level_label"
                :allow-void="target_punch !== null"
            />
            <SectionCard title="Autorización" :icon="Gavel" divided>
                <LevelsStepper :levels="levels" />
                <p
                    v-if="r.reviewed_by"
                    class="mt-4 text-xs text-muted-foreground"
                >
                    Resolvió {{ r.reviewed_by }} ·
                    <DateTimeDisplay
                        :value="r.reviewed_at"
                        :timezone="timezone"
                        format="datetime"
                    />
                </p>
            </SectionCard>
            <SectionCard title="Historial" :icon="History" divided>
                <DecisionHistory
                    :decisions="decisions"
                    :created-at="r.created_at"
                    :requested-by="r.requested_by"
                    :timezone="timezone"
                />
            </SectionCard>
        </aside>
    </div>
</template>
