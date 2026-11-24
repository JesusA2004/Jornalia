<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Anchor,
    Blocks,
    CircleCheck,
    CircleX,
    Clock3,
    Fingerprint,
    History,
    KeyRound,
    Link2,
    ScanSearch,
    ShieldCheck,
    ShieldX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import IntegrityController from '@/actions/App/Http/Controllers/Web/Operacion/IntegrityController';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import { formatNumber } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn } from '@/types';
import type { IntegrityIndexProps } from '@/types/operacion';
import HashChip from '@/pages/integridad/components/HashChip.vue';
import ComplianceTabs from './components/ComplianceTabs.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Análisis' },
            { title: 'Cumplimiento', href: '/cumplimiento' },
            { title: 'Integridad del registro' },
        ],
    },
});

const props = defineProps<IntegrityIndexProps>();
const page = usePage();
const { can } = usePermissions();
const canVerify = computed(() => props.can.verify && can('integrity.verify'));

const running = ref<'verify' | 'anchor' | null>(null);

function run(kind: 'verify' | 'anchor'): void {
    const action =
        kind === 'verify'
            ? IntegrityController.verify()
            : IntegrityController.anchor();
    router.visit(action.url, {
        method: action.method,
        preserveScroll: true,
        onStart: () => (running.value = kind),
        onFinish: () => (running.value = null),
    });
}

const anchorError = computed(
    () =>
        (page.props.errors as Record<string, string> | undefined)?.anchor ??
        null,
);

type AnchorRow = IntegrityIndexProps['anchors']['data'][number];

const anchorColumns: DataTableColumn<AnchorRow>[] = [
    { key: 'created_at', label: 'Fecha', class: 'w-44' },
    { key: 'chain', label: 'Cadena', class: 'w-32' },
    { key: 'sequence', label: 'Secuencia', numeric: true, class: 'w-28' },
    { key: 'hash', label: 'Hash anclado' },
    { key: 'method', label: 'Destino', hideBelow: 'lg' },
    { key: 'signature', label: 'Firma', class: 'w-32' },
];

const help = [
    {
        title: 'Cadena de hash',
        text: 'Cada marcaje incluye el hash del anterior: cualquier cambio rompe la cadena.',
        icon: Link2,
    },
    {
        title: 'Anclas externas',
        text: 'La cabeza de la cadena se firma (HMAC) y se guarda fuera de la base.',
        icon: Anchor,
    },
    {
        title: 'Automático',
        text: 'Cada día se verifica y ancla sola. «Verificar ahora» es opcional. Si falla, RH y Sistemas reciben una alerta crítica.',
        icon: ScanSearch,
    },
];
</script>

<template>
    <PageHeader
        title="Cumplimiento"
        description="Integridad del registro electrónico inalterable."
        :icon="Fingerprint"
        :help="help"
        help-title="Integridad del registro"
    >
        <template v-if="canVerify" #actions>
            <Button
                variant="outline"
                :disabled="running !== null"
                @click="run('anchor')"
            >
                <Spinner v-if="running === 'anchor'" />
                <Anchor v-else aria-hidden="true" />
                Anclar
            </Button>
            <Button :disabled="running !== null" @click="run('verify')">
                <Spinner v-if="running === 'verify'" />
                <ScanSearch v-else aria-hidden="true" />
                Verificar ahora
            </Button>
        </template>
    </PageHeader>

    <ComplianceTabs />

    <div class="space-y-6">
        <section
            class="grid gap-3 rounded-2xl border bg-card p-4 text-sm sm:grid-cols-3"
            aria-label="Verificación automática"
        >
            <div class="flex items-start gap-2">
                <Clock3
                    class="mt-0.5 icon-sm text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">Verificación automática</p>
                    <p class="text-xs text-muted-foreground">
                        {{ automation.schedule }}
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-2">
                <Anchor
                    class="mt-0.5 icon-sm text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">
                        Anclaje externo
                        <StatusBadge
                            v-if="automation.last_anchor"
                            :status="
                                automation.last_anchor.ok ? 'valid' : 'invalid'
                            "
                            :label="
                                automation.last_anchor.ok ? 'Correcto' : 'Falló'
                            "
                            size="sm"
                        />
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <DateTimeDisplay
                            v-if="automation.last_anchor"
                            :value="automation.last_anchor.at"
                            :timezone="timezone"
                        />
                        <template v-else>Sin anclas aún</template>
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-2">
                <KeyRound
                    class="mt-0.5 icon-sm text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">Destino del ancla</p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="!automation.anchor_key_configured"
                            >Clave de anclaje sin configurar</template
                        >
                        <template v-else-if="automation.anchor_storage.external"
                            >Externo ({{
                                automation.anchor_storage.driver
                            }})</template
                        >
                        <template v-else
                            >Local · almacenamiento WORM pendiente</template
                        >
                    </p>
                </div>
            </div>
        </section>

        <Alert v-if="anchorError" variant="destructive">
            <KeyRound aria-hidden="true" />
            <AlertTitle>No se pudo anclar</AlertTitle>
            <AlertDescription>{{ anchorError }}</AlertDescription>
        </Alert>

        <!-- Resultado de la última verificación -->
        <section
            v-if="latest"
            :class="[
                'relative overflow-hidden rounded-2xl border p-5 shadow-elev-1 sm:p-6',
                latest.valid
                    ? 'border-success/30 bg-gradient-to-br from-success-soft via-card to-card'
                    : 'border-danger/40 bg-gradient-to-br from-danger-soft via-card to-card',
            ]"
        >
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center">
                <div class="flex items-center gap-4">
                    <span
                        :class="[
                            'flex size-14 shrink-0 items-center justify-center rounded-2xl shadow-elev-2',
                            latest.valid
                                ? 'bg-success text-success-foreground'
                                : 'bg-danger text-danger-foreground',
                        ]"
                    >
                        <ShieldCheck
                            v-if="latest.valid"
                            class="size-7"
                            aria-hidden="true"
                        />
                        <ShieldX v-else class="size-7" aria-hidden="true" />
                    </span>
                    <div>
                        <p class="font-display text-xl font-bold">
                            {{
                                latest.valid
                                    ? 'Registro íntegro'
                                    : 'Se detectaron hallazgos'
                            }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                latest.automatic
                                    ? 'Verificación automática'
                                    : 'Verificación manual'
                            }}
                            <DateTimeDisplay
                                :value="latest.at"
                                :timezone="timezone"
                                format="relative"
                            />
                            <template v-if="latest.by">
                                · {{ latest.by }}</template
                            >
                        </p>
                    </div>
                </div>
                <div class="grid flex-1 gap-3 sm:grid-cols-2">
                    <div
                        v-for="c in latest.chains"
                        :key="c.chain"
                        class="rounded-xl border bg-card/80 p-3.5 backdrop-blur"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold">{{ c.label }}</p>
                            <StatusBadge
                                :status="c.valid ? 'valid' : 'invalid'"
                                size="sm"
                            />
                        </div>
                        <p class="mt-1 numeric text-xs text-muted-foreground">
                            {{ formatNumber(c.checked) }} registros ·
                            {{ c.anchors_checked }} anclas
                            <template v-if="!c.valid">
                                · {{ c.errors_total }} hallazgos</template
                            >
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="latest.chains.some((c) => c.errors.length > 0)"
                class="mt-5 space-y-2"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-danger-soft-foreground uppercase"
                >
                    Hallazgos
                </p>
                <ul
                    class="max-h-72 divide-y overflow-auto rounded-xl border bg-card"
                >
                    <template v-for="c in latest.chains" :key="c.chain">
                        <li
                            v-for="(e, i) in c.errors"
                            :key="`${c.chain}-${i}`"
                            class="flex items-start gap-3 px-4 py-2.5 text-sm"
                        >
                            <CircleX
                                class="mt-0.5 size-4 shrink-0 text-danger"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">{{ e.error }}</span>
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                                >{{ c.label }} #{{ e.sequence }}</span
                            >
                        </li>
                    </template>
                </ul>
            </div>
        </section>

        <EmptyState
            v-else
            :icon="ScanSearch"
            tone="primary"
            bordered
            class="rounded-2xl"
            title="Aún no se ha verificado"
            :description="
                canVerify
                    ? 'Ejecuta la primera verificación de las cadenas.'
                    : 'Un responsable con permiso puede ejecutar la verificación.'
            "
        >
            <template v-if="canVerify" #actions>
                <Button :disabled="running !== null" @click="run('verify')">
                    <Spinner v-if="running === 'verify'" />
                    <ScanSearch v-else aria-hidden="true" />
                    Verificar ahora
                </Button>
            </template>
        </EmptyState>

        <!-- Cadenas -->
        <div class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="c in chains"
                :key="c.chain"
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-elev-1"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground"
                        >
                            <Blocks class="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <h2
                                class="font-display text-lg leading-tight font-bold"
                            >
                                {{ c.label }}
                            </h2>
                            <p
                                v-if="c.last_record_at"
                                class="text-xs text-muted-foreground"
                            >
                                Último registro
                                <DateTimeDisplay
                                    :value="c.last_record_at"
                                    :timezone="timezone"
                                    format="relative"
                                />
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                Sin registros
                            </p>
                        </div>
                    </div>
                    <StatusBadge
                        v-if="c.records > 0"
                        :label="
                            c.unanchored === 0
                                ? 'Anclada'
                                : `${formatNumber(c.unanchored)} sin anclar`
                        "
                        :tone="c.unanchored === 0 ? 'success' : 'warning'"
                        size="sm"
                    />
                </div>

                <dl class="grid grid-cols-3 gap-3">
                    <div class="rounded-xl bg-surface-2 px-3 py-2.5">
                        <dt class="text-[0.6875rem] text-muted-foreground">
                            Registros
                        </dt>
                        <dd class="font-display numeric text-xl font-bold">
                            {{ formatNumber(c.records) }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-surface-2 px-3 py-2.5">
                        <dt class="text-[0.6875rem] text-muted-foreground">
                            Cabeza
                        </dt>
                        <dd class="font-display numeric text-xl font-bold">
                            #{{ formatNumber(c.head_sequence) }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-surface-2 px-3 py-2.5">
                        <dt class="text-[0.6875rem] text-muted-foreground">
                            Anclada hasta
                        </dt>
                        <dd class="font-display numeric text-xl font-bold">
                            #{{ formatNumber(c.anchored_sequence) }}
                        </dd>
                    </div>
                </dl>

                <div class="space-y-1.5">
                    <p class="text-xs text-muted-foreground">Hash de cabeza</p>
                    <HashChip
                        :value="c.head_hash"
                        :head="20"
                        :tail="12"
                        label="Copiar hash"
                        class="w-full justify-between"
                    />
                </div>

                <div
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Anchor class="size-3.5" aria-hidden="true" />
                    Último anclaje:
                    <DateTimeDisplay
                        :value="c.last_anchor_at"
                        :timezone="timezone"
                        format="datetime"
                        fallback="nunca"
                    />
                </div>

                <div
                    v-if="c.records > 0"
                    class="h-1.5 overflow-hidden rounded-full bg-surface-3"
                >
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-brand to-success transition-all"
                        :style="{
                            width: `${Math.min(100, (c.anchored_sequence / Math.max(1, c.head_sequence)) * 100)}%`,
                        }"
                    />
                </div>
            </article>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <SectionCard
                title="Anclas externas"
                :icon="Anchor"
                padding="none"
                divided
                class="min-w-0"
            >
                <DataTable
                    :rows="anchors.data"
                    :columns="anchorColumns"
                    :pagination="toPaginationMeta(anchors)"
                    :only="['anchors']"
                    :per-page-options="[10, 25, 50]"
                    :card="false"
                    caption="Anclas de integridad"
                    :empty-icon="Anchor"
                    empty-title="Sin anclas registradas"
                    empty-description="El anclaje se ejecuta de forma programada o manualmente."
                >
                    <template #cell-created_at="{ row }">
                        <DateTimeDisplay
                            :value="row.created_at"
                            :timezone="timezone"
                        />
                    </template>
                    <template #cell-chain="{ row }">
                        <span class="text-sm font-medium">{{
                            row.chain_label
                        }}</span>
                    </template>
                    <template #cell-sequence="{ row }">
                        <span class="font-mono">#{{ row.sequence }}</span>
                    </template>
                    <template #cell-hash="{ row }">
                        <HashChip :value="row.hash" />
                    </template>
                    <template #cell-method="{ row }">
                        <span
                            class="block max-w-56 truncate text-xs text-muted-foreground"
                            :title="row.external_reference ?? ''"
                        >
                            {{ row.method
                            }}<template v-if="row.external_reference">
                                · {{ row.external_reference }}</template
                            >
                        </span>
                    </template>
                    <template #cell-signature="{ row }">
                        <StatusBadge
                            :label="
                                row.signature_valid === null
                                    ? 'Sin clave'
                                    : row.signature_valid
                                      ? 'Válida'
                                      : 'Inválida'
                            "
                            :tone="
                                row.signature_valid === null
                                    ? 'neutral'
                                    : row.signature_valid
                                      ? 'success'
                                      : 'danger'
                            "
                            size="sm"
                        />
                    </template>
                </DataTable>
            </SectionCard>

            <SectionCard title="Historial" :icon="History" divided>
                <EmptyState
                    v-if="history.length === 0"
                    :icon="History"
                    size="sm"
                    title="Sin verificaciones"
                />
                <ol
                    v-else
                    class="relative space-y-4 border-l border-border pl-5"
                >
                    <li v-for="h in history" :key="h.id" class="relative">
                        <span
                            :class="[
                                'absolute top-0.5 -left-[1.6rem] flex size-5 items-center justify-center rounded-full ring-4 ring-card',
                                h.valid
                                    ? 'bg-success text-success-foreground'
                                    : 'bg-danger text-danger-foreground',
                            ]"
                        >
                            <CircleCheck
                                v-if="h.valid"
                                class="size-3"
                                aria-hidden="true"
                            />
                            <CircleX v-else class="size-3" aria-hidden="true" />
                        </span>
                        <p class="text-sm font-medium">
                            {{ h.valid ? 'Íntegro' : 'Con hallazgos' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <DateTimeDisplay
                                :value="h.at"
                                :timezone="timezone"
                            />
                            <template v-if="h.by"> · {{ h.by }}</template>
                        </p>
                        <p
                            class="numeric text-[0.6875rem] text-muted-foreground"
                        >
                            <template v-for="(c, i) in h.chains" :key="c.chain"
                                ><template v-if="i > 0"> · </template
                                >{{ c.label }}:
                                {{ formatNumber(c.checked) }}</template
                            >
                        </p>
                    </li>
                </ol>
            </SectionCard>
        </div>
    </div>
</template>
