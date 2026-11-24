<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    FileClock,
    FilePen,
    Fingerprint,
    History,
    Lock,
    Paperclip,
    ShieldCheck,
    ShieldX,
    UserCheck,
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
import FlowSteps from './components/FlowSteps.vue';
import { shortHash } from './components/shared';
import {
    SIGNATURE_STATUS,
    rejectionLabel,
    stageTone,
    stepIndex,
} from './components/stages';

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
    {
        key: 'date',
        label: 'Fecha',
        value: formatDate(r.value.date, 'date-long'),
    },
    { key: 'kind', label: 'Tipo', value: r.value.kind_label },
    { key: 'reason', label: 'Motivo', value: r.value.reason, full: true },
]);

const trail = computed<KeyValueItem[]>(() => [
    { key: 'requested', label: 'Solicitó', value: r.value.requested_by ?? '—' },
    {
        key: 'authorized',
        label: 'Autorizó',
        value: r.value.authorized_by ?? 'En espera',
    },
    {
        key: 'signature',
        label: 'Firma colaborador',
        value: SIGNATURE_STATUS[r.value.signature_status].label,
    },
    {
        key: 'validated',
        label: 'Valida RH',
        value: r.value.validated_by ?? 'En espera',
    },
]);

const decisionTitle = computed(() =>
    r.value.can_authorize ? 'Autorización del jefe' : 'Validación RH',
);

const blockedLate = computed(
    () => r.value.can_validate && r.value.is_late && !r.value.can_apply_late,
);

const help: HelpStep[] = [
    {
        title: 'Original contra propuesta',
        text: 'El registro original queda sellado; la propuesta sólo se aplica completa la firma y la validación.',
        icon: Fingerprint,
    },
    {
        title: 'Autoriza el jefe',
        text: 'Luego la solicitud llega a la app del colaborador para firmar.',
        icon: UserCheck,
    },
    {
        title: 'Firma y sello',
        text: 'Se registra fecha, hora, dispositivo y sello de integridad.',
        icon: ShieldCheck,
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
                :status="r.stage"
                :label="r.stage_label"
                :tone="stageTone(r.stage)"
            />
            <StatusBadge
                v-if="r.is_late"
                status="late"
                label="Extemporánea"
                tone="warning"
            />
            <span class="text-xs text-muted-foreground">
                Solicitada
                <DateTimeDisplay
                    :value="r.created_at"
                    :timezone="timezone"
                    format="datetime"
                />
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

    <div class="grid gap-5">
        <FlowSteps
            :current="stepIndex(r.stage)"
            :rejected="r.stage === 'rechazada'"
        />

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid min-w-0 content-start gap-5">
                <SectionCard title="Solicitud" :icon="FileClock" divided>
                    <template #actions>
                        <EmployeeCell :employee="r.employee" />
                    </template>
                    <div class="mb-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border bg-surface-2 p-4">
                            <p class="text-xs text-muted-foreground">
                                Registro original
                            </p>
                            <p class="mt-1 text-lg font-semibold">
                                {{ r.original_summary }}
                            </p>
                        </div>
                        <div
                            class="rounded-xl border border-brand/30 bg-brand-soft p-4"
                        >
                            <p class="text-xs text-brand-soft-foreground">
                                Propuesta
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold text-brand-soft-foreground"
                            >
                                {{ r.proposal_summary }}
                            </p>
                        </div>
                    </div>
                    <KeyValueGrid :items="details" :columns="2" />
                    <p
                        v-if="r.stage === 'rechazada'"
                        class="mt-4 flex items-start gap-2 rounded-lg bg-danger-soft p-3 text-sm text-danger-soft-foreground"
                    >
                        <ShieldX
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            <strong class="font-semibold">{{
                                rejectionLabel(r.rejection_stage)
                            }}</strong>
                            <template v-if="r.rejection_reason">
                                · {{ r.rejection_reason }}</template
                            >
                        </span>
                    </p>
                </SectionCard>

                <SectionCard
                    title="Jornada del día"
                    :icon="Fingerprint"
                    description="Original, ajustes y resultado."
                    divided
                >
                    <DayLedger
                        :day="day"
                        :highlight-uuid="target_punch?.uuid"
                    />
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
                    v-if="(r.can_authorize || r.can_validate) && !blockedLate"
                    :action="RectificationController.decide.url(r.id)"
                    :stage="r.stage"
                    :title="decisionTitle"
                    :approve-label="
                        r.can_authorize ? 'Autorizar' : 'Validar y aplicar'
                    "
                />
                <p
                    v-if="blockedLate"
                    class="flex items-start gap-2 rounded-xl border bg-warning-soft p-4 text-sm text-warning-soft-foreground"
                >
                    <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    Extemporánea: la aplica sólo RH con permiso después del
                    cierre de nómina.
                </p>

                <SectionCard title="Trazabilidad" :icon="UserCheck" divided>
                    <KeyValueGrid :items="trail" :columns="1" />
                    <p
                        v-if="r.stage === 'por_firmar' && r.signature_due_at"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        Firma antes de
                        <DateTimeDisplay
                            :value="r.signature_due_at"
                            :timezone="timezone"
                            format="datetime"
                        />
                    </p>
                    <p
                        v-if="r.legacy_without_signature"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        Aplicada antes del flujo con firma.
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="signature"
                    title="Firma del colaborador"
                    :icon="ShieldCheck"
                    divided
                >
                    <dl class="grid gap-2 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">Método</dt>
                            <dd class="font-medium">
                                {{ signature.method_label }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">Fecha</dt>
                            <dd>
                                <DateTimeDisplay
                                    :value="signature.signed_at"
                                    :timezone="timezone"
                                    format="datetime"
                                />
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">Dispositivo</dt>
                            <dd>{{ signature.device ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted-foreground">Sello</dt>
                            <dd
                                class="font-mono text-xs"
                                :title="signature.hash"
                            >
                                {{ shortHash(signature.hash) }}
                            </dd>
                        </div>
                    </dl>
                    <StatusBadge
                        class="mt-3"
                        :status="signature.sealed ? 'valid' : 'invalid'"
                        :label="
                            signature.sealed
                                ? 'Sello íntegro'
                                : 'Sello alterado'
                        "
                        :tone="signature.sealed ? 'success' : 'danger'"
                        size="sm"
                    />
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
    </div>
</template>
