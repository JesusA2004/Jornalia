<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    Gavel,
    History,
    Palmtree,
    Scale,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import VacationController from '@/actions/App/Http/Controllers/Web/Operacion/VacationController';
import {
    DateTimeDisplay,
    EmptyState,
    KeyValueGrid,
    PageHeader,
    SectionCard,
    StatusBadge,
} from '@/components/app';
import { formatDate } from '@/lib/format';
import type { HelpStep, KeyValueItem } from '@/types';
import type { VacationShowProps } from '@/types/operacion';
import CancelRequestButton from '../rectificaciones/components/CancelRequestButton.vue';
import DecisionHistory from '../rectificaciones/components/DecisionHistory.vue';
import DecisionPanel from '../rectificaciones/components/DecisionPanel.vue';
import EmployeeCell from '../rectificaciones/components/EmployeeCell.vue';
import LevelsStepper from '../rectificaciones/components/LevelsStepper.vue';
import { approvalTone } from '../rectificaciones/components/shared';
import BalanceMeter from './components/BalanceMeter.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Vacaciones', href: '/vacaciones' },
            { title: 'Detalle' },
        ],
    },
});

const props = defineProps<VacationShowProps>();
const v = computed(() => props.request);

const details = computed<KeyValueItem[]>(() => [
    {
        key: 'period',
        label: 'Periodo',
        value: `${formatDate(v.value.starts_on, 'date-long')} – ${formatDate(v.value.ends_on, 'date-long')}`,
        full: true,
    },
    { key: 'days', label: 'Días laborables', value: v.value.days },
    { key: 'year', label: 'Año de servicio', value: v.value.service_year },
    {
        key: 'comment',
        label: 'Comentario',
        value: v.value.comment ?? '—',
        full: true,
    },
    ...(v.value.review_comment
        ? [
              {
                  key: 'review',
                  label: 'Comentario de resolución',
                  value: v.value.review_comment,
                  full: true,
              },
          ]
        : []),
]);

const help: HelpStep[] = [
    {
        title: 'Saldo vigente',
        text: 'Se calcula al momento con la regla legal y la política.',
        icon: Scale,
    },
    {
        title: 'Cobertura',
        text: 'Revisa quién más de la sucursal estará ausente.',
        icon: Users,
    },
    {
        title: 'Cancelación',
        text: 'Se puede cancelar mientras esté pendiente o antes de iniciar.',
        icon: CalendarDays,
    },
];
</script>

<template>
    <PageHeader
        title="Vacaciones"
        :description="`Folio #${v.id} · ${v.days} días`"
        :icon="Palmtree"
        :help="help"
        back-href="/vacaciones"
        back-label="Vacaciones"
    >
        <template #meta>
            <StatusBadge
                :status="v.status"
                :label="v.status_label"
                :tone="approvalTone(v.status)"
            />
            <span class="text-xs text-muted-foreground">
                Solicitada
                <DateTimeDisplay
                    :value="v.created_at"
                    :timezone="timezone"
                    format="datetime"
                />
                por {{ v.requested_by ?? '—' }}
            </span>
        </template>
        <template #actions>
            <CancelRequestButton
                v-if="v.can_cancel"
                :action="VacationController.cancel.url(v.id)"
            />
        </template>
    </PageHeader>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="grid min-w-0 content-start gap-5">
            <SectionCard title="Solicitud" :icon="Palmtree" divided>
                <template #actions>
                    <EmployeeCell :employee="v.employee" />
                </template>
                <KeyValueGrid :items="details" :columns="2" />
            </SectionCard>

            <SectionCard title="Saldo" :icon="Wallet" divided>
                <template v-if="balance">
                    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs text-muted-foreground">Derecho</p>
                            <p class="font-display numeric text-2xl font-bold">
                                {{ balance.entitled_days }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs text-muted-foreground">
                                Disfrutados
                            </p>
                            <p class="font-display numeric text-2xl font-bold">
                                {{ balance.used_days }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-warning-soft/60 p-3">
                            <p class="text-xs text-warning-soft-foreground">
                                Pendientes
                            </p>
                            <p class="font-display numeric text-2xl font-bold">
                                {{ balance.pending_days }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-success-soft/60 p-3">
                            <p class="text-xs text-success-soft-foreground">
                                Disponibles
                            </p>
                            <p class="font-display numeric text-2xl font-bold">
                                {{ balance.available_days }}
                            </p>
                        </div>
                    </div>
                    <BalanceMeter :balance="balance" />
                    <p
                        class="mt-3 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                    >
                        <Scale class="size-3.5" aria-hidden="true" />
                        <span>{{ balance.rule_source }}</span>
                        <span aria-hidden="true">·</span>
                        <span
                            >Prima
                            {{ balance.vacation_premium_percent }} %</span
                        >
                        <span aria-hidden="true">·</span>
                        <span
                            >Año {{ balance.service_year }}:
                            {{ formatDate(balance.period_starts_on) }} –
                            {{ formatDate(balance.period_ends_on) }}</span
                        >
                    </p>
                </template>
                <EmptyState
                    v-else
                    :icon="Wallet"
                    size="sm"
                    title="Saldo no disponible"
                    description="El colaborador no tiene relación laboral vigente."
                />
            </SectionCard>

            <SectionCard
                title="Ausentes en el mismo periodo"
                :icon="Users"
                divided
                padding="none"
            >
                <EmptyState
                    v-if="overlaps.length === 0"
                    :icon="Users"
                    size="sm"
                    class="py-8"
                    title="Cobertura sin conflictos"
                    description="Nadie más de la sucursal estará de vacaciones."
                />
                <ul v-else class="divide-y">
                    <li v-for="o in overlaps" :key="o.id">
                        <Link
                            :href="VacationController.show.url(o.id)"
                            class="flex flex-wrap items-center gap-3 px-5 py-3 transition-colors hover:bg-surface-2"
                        >
                            <EmployeeCell
                                :employee="o.employee"
                                class="min-w-0 flex-1"
                            />
                            <span class="numeric text-sm"
                                >{{ formatDate(o.starts_on, 'date-short') }} –
                                {{ formatDate(o.ends_on, 'date-short') }}</span
                            >
                            <StatusBadge
                                :status="o.status"
                                :label="o.status_label"
                                :tone="approvalTone(o.status)"
                                size="sm"
                            />
                        </Link>
                    </li>
                </ul>
            </SectionCard>
        </div>

        <aside class="grid content-start gap-5">
            <DecisionPanel
                v-if="v.can_decide"
                :action="VacationController.decide.url(v.id)"
                :level-label="v.current_level_label"
            />
            <SectionCard title="Autorización" :icon="Gavel" divided>
                <LevelsStepper :levels="levels" />
                <p
                    v-if="v.reviewed_by"
                    class="mt-4 text-xs text-muted-foreground"
                >
                    Resolvió {{ v.reviewed_by }} ·
                    <DateTimeDisplay
                        :value="v.reviewed_at"
                        :timezone="timezone"
                        format="datetime"
                    />
                </p>
            </SectionCard>
            <SectionCard title="Historial" :icon="History" divided>
                <DecisionHistory
                    :decisions="decisions"
                    :created-at="v.created_at"
                    :requested-by="v.requested_by"
                    :timezone="timezone"
                />
            </SectionCard>
        </aside>
    </div>
</template>
