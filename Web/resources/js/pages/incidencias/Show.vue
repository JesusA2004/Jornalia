<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Ban,
    ClipboardList,
    FilePen,
    FileClock,
    Gavel,
    History,
    Paperclip,
    RefreshCcw,
    Workflow,
} from '@lucide/vue';
import { computed } from 'vue';
import IncidenceController from '@/actions/App/Http/Controllers/Web/Operacion/IncidenceController';
import {
    DateTimeDisplay,
    KeyValueGrid,
    PageHeader,
    SectionCard,
    StatusBadge,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import type { HelpStep, KeyValueItem } from '@/types';
import type { IncidenceShowProps } from '@/types/operacion';
import AttachmentList from '../rectificaciones/components/AttachmentList.vue';
import CancelWithReasonButton from './components/CancelWithReasonButton.vue';
import DecisionHistory from '../rectificaciones/components/DecisionHistory.vue';
import DecisionPanel from '../rectificaciones/components/DecisionPanel.vue';
import EmployeeCell from '../rectificaciones/components/EmployeeCell.vue';
import LevelsStepper from '../rectificaciones/components/LevelsStepper.vue';
import { approvalTone } from '../rectificaciones/components/shared';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Incidencias', href: '/incidencias' },
            { title: 'Detalle' },
        ],
    },
});

const props = defineProps<IncidenceShowProps>();
const i = computed(() => props.incidence);
const type = computed(
    () =>
        props.incidence.type as {
            requires_evidence?: boolean;
            requires_approval?: boolean;
            affects_payroll?: boolean;
        },
);

const details = computed<KeyValueItem[]>(() => [
    { key: 'type', label: 'Tipo', value: i.value.type_name },
    { key: 'category', label: 'Categoría', value: i.value.category_label },
    {
        key: 'period',
        label: 'Periodo',
        value:
            i.value.starts_on === i.value.ends_on
                ? formatDate(i.value.starts_on, 'date-long')
                : `${formatDate(i.value.starts_on)} – ${formatDate(i.value.ends_on)}`,
    },
    {
        key: 'days',
        label: 'Duración',
        value: `${i.value.days} ${i.value.days === 1 ? 'día' : 'días'}${i.value.minutes ? ` · ${i.value.minutes} min` : ''}`,
    },
    { key: 'source', label: 'Origen', value: i.value.source_label },
    ...(i.value.folio
        ? [{ key: 'folio', label: 'Folio IMSS', value: i.value.folio }]
        : []),
    { key: 'flags', label: 'Reglas del tipo' },
    {
        key: 'reason',
        label: 'Motivo',
        value: i.value.reason ?? '—',
        full: true,
    },
    ...(i.value.review_comment
        ? [
              {
                  key: 'review',
                  label: 'Comentario de resolución',
                  value: i.value.review_comment,
                  full: true,
              },
          ]
        : []),
    ...(i.value.cancellation_reason
        ? [
              {
                  key: 'cancel',
                  label: `Cancelada por ${i.value.cancelled_by ?? 'People'}`,
                  value: i.value.cancellation_reason,
                  full: true,
              },
          ]
        : []),
]);

const help: HelpStep[] = [
    {
        title: 'Revisa la evidencia',
        text: 'Certificado IMSS y folio, formato autorizado, acta o constancia según el tipo.',
        icon: Paperclip,
    },
    {
        title: 'Jefe inmediato y RH',
        text: 'Cada tipo define quién autoriza. Nadie autoriza su propia captura.',
        icon: Workflow,
    },
    {
        title: 'Sin borrado',
        text: 'Sólo se cancela con motivo; queda en la bitácora.',
        icon: Ban,
    },
    {
        title: 'Recálculo automático',
        text: 'Al aprobarse se recalculan los días afectados.',
        icon: RefreshCcw,
    },
];
</script>

<template>
    <PageHeader
        :title="i.type_name ?? 'Incidencia'"
        :description="`Folio #${i.id}`"
        :icon="ClipboardList"
        :help="help"
        back-href="/incidencias"
        back-label="Bandeja"
    >
        <template #meta>
            <StatusBadge
                :status="i.status"
                :label="i.status_label"
                :tone="approvalTone(i.status)"
            />
            <span class="text-xs text-muted-foreground">
                Registrada
                <DateTimeDisplay
                    :value="i.created_at"
                    :timezone="timezone"
                    format="datetime"
                />
                por {{ i.requested_by ?? '—' }}
            </span>
        </template>
        <template #actions>
            <Button
                v-if="i.rectification_request_id"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="`/rectificaciones/${i.rectification_request_id}`">
                    <FilePen aria-hidden="true" />
                    Ver rectificación
                </Link>
            </Button>
            <CancelWithReasonButton
                v-if="i.can_cancel"
                :action="IncidenceController.cancel.url(i.id)"
                label="Cancelar incidencia"
            />
        </template>
    </PageHeader>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="grid min-w-0 content-start gap-5">
            <SectionCard title="Incidencia" :icon="FileClock" divided>
                <template #actions>
                    <EmployeeCell :employee="i.employee" />
                </template>
                <KeyValueGrid :items="details" :columns="2">
                    <template #value-flags>
                        <div class="flex flex-wrap gap-1.5">
                            <StatusBadge
                                :label="
                                    type.requires_approval
                                        ? 'Con aprobación'
                                        : 'Sin aprobación'
                                "
                                tone="neutral"
                                size="sm"
                                :dot="false"
                            />
                            <StatusBadge
                                v-if="type.requires_evidence"
                                label="Evidencia obligatoria"
                                tone="warning"
                                size="sm"
                                :dot="false"
                            />
                            <StatusBadge
                                v-if="type.affects_payroll"
                                label="Afecta nómina"
                                tone="info"
                                size="sm"
                                :dot="false"
                            />
                        </div>
                    </template>
                </KeyValueGrid>
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
                v-if="i.can_decide"
                :action="IncidenceController.decide.url(i.id)"
                :level-label="i.current_level_label"
            />
            <SectionCard title="Autorización" :icon="Gavel" divided>
                <LevelsStepper :levels="levels" />
                <p
                    v-if="i.reviewed_by"
                    class="mt-4 text-xs text-muted-foreground"
                >
                    Resolvió {{ i.reviewed_by }} ·
                    <DateTimeDisplay
                        :value="i.reviewed_at"
                        :timezone="timezone"
                        format="datetime"
                    />
                </p>
            </SectionCard>
            <SectionCard title="Historial" :icon="History" divided>
                <DecisionHistory
                    :decisions="decisions"
                    :created-at="i.created_at"
                    :requested-by="i.requested_by"
                    :timezone="timezone"
                />
            </SectionCard>
        </aside>
    </div>
</template>
