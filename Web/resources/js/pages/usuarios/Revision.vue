<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Archive,
    Check,
    ClipboardCheck,
    KeyRound,
    Landmark,
    ShieldAlert,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import type { DataTableColumn, HelpStep } from '@/types';
import type { ReviewFlag, ReviewPageProps, ReviewUserRow } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Usuarios y roles', href: '/usuarios' },
            { title: 'Revisión trimestral' },
        ],
    },
});

const props = defineProps<ReviewPageProps>();

const FLAG_LABEL: Record<ReviewFlag, string> = {
    inactive: 'Sin acceso reciente',
    terminated: 'Colaborador de baja',
    authority_expired: 'Autoridad vencida',
    segregation: 'Conflicto de funciones',
    two_factor_missing: 'Sin doble factor',
    scope_missing: 'Sin alcance por sucursal',
};

const help = computed<HelpStep[]>(() => [
    {
        title: 'Cada trimestre',
        text: 'Se abre sola y genera una alerta en Cumplimiento.',
        icon: ClipboardCheck,
    },
    {
        title: 'Revisa hallazgos',
        text: `Sin acceso en ${props.inactive_after_days} días, bajas, conflictos de funciones, doble factor y alcance.`,
        icon: ShieldAlert,
    },
    {
        title: 'Decide',
        text: 'Confirma o desactiva cada usuario. Todo queda en bitácora.',
        icon: Check,
    },
    {
        title: 'Autoridad laboral',
        text: 'Los accesos vencidos se desactivan solos cada día.',
        icon: Landmark,
    },
]);

const columns: DataTableColumn<ReviewUserRow>[] = [
    { key: 'name', label: 'Usuario', mobile: 'title' },
    { key: 'roles', label: 'Roles', hideBelow: 'md' },
    { key: 'last_login_at', label: 'Último acceso', hideBelow: 'lg' },
    { key: 'flags', label: 'Hallazgos' },
    { key: 'decision', label: '', align: 'right', class: 'w-56' },
];

const isOpen = computed(() => props.review?.status === 'open');
const flagged = computed(() => props.users.filter((u) => u.flags.length > 0));
const decided = computed(
    () => Object.keys(props.review?.decisions ?? {}).length,
);

function decisionOf(user: ReviewUserRow) {
    return props.review?.decisions[String(user.id)] ?? null;
}

function onError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

const busy = ref<number | null>(null);

function decide(user: ReviewUserRow, decision: 'keep' | 'deactivate'): void {
    if (!props.review) {
        return;
    }

    router.post(
        `/usuarios/revision/${props.review.id}/usuarios/${user.id}`,
        { decision },
        {
            preserveScroll: true,
            onError,
            onStart: () => (busy.value = user.id),
            onFinish: () => (busy.value = null),
        },
    );
}

const deactivateTarget = ref<ReviewUserRow | null>(null);
const deactivateOpen = ref(false);

function askDeactivate(user: ReviewUserRow): void {
    deactivateTarget.value = user;
    deactivateOpen.value = true;
}

function confirmDeactivate(): void {
    if (deactivateTarget.value) {
        decide(deactivateTarget.value, 'deactivate');
    }

    deactivateOpen.value = false;
}

function openReview(): void {
    router.post(
        '/usuarios/revision/abrir',
        {},
        { preserveScroll: true, onError },
    );
}

const completeOpen = ref(false);

function complete(): void {
    if (!props.review) {
        return;
    }

    router.post(
        `/usuarios/revision/${props.review.id}/cerrar`,
        {},
        {
            preserveScroll: true,
            onError,
            onFinish: () => (completeOpen.value = false),
        },
    );
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Revisión trimestral"
            description="Usuarios activos y sus accesos."
            :icon="ClipboardCheck"
            :help="help"
            back-href="/usuarios"
            back-label="Usuarios"
        >
            <template v-if="can.manage" #actions>
                <Button v-if="!isOpen" variant="outline" @click="openReview">
                    <ClipboardCheck />
                    Abrir revisión
                </Button>
                <Button v-else @click="completeOpen = true">
                    <Check />
                    Cerrar revisión
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="review"
            class="flex flex-wrap items-center gap-3 rounded-2xl border bg-card p-4 text-sm"
        >
            <Badge variant="outline">{{ review.period }}</Badge>
            <StatusBadge
                :status="review.status === 'open' ? 'pending' : 'approved'"
                :label="review.status === 'open' ? 'Abierta' : 'Completada'"
                size="sm"
            />
            <span v-if="isOpen" class="text-muted-foreground"
                >Vence {{ formatDate(review.due_on, 'date') }} ·
                {{ decided }} decisiones · {{ flagged.length }} con
                hallazgos</span
            >
            <span v-else class="text-muted-foreground">
                {{ review.completed_by }} ·
                <DateTimeDisplay :value="review.completed_at" />
            </span>
        </div>

        <EmptyState
            v-if="users.length === 0"
            :icon="UserX"
            title="Sin usuarios activos"
            bordered
            class="rounded-2xl"
        />

        <DataTable
            v-else
            :rows="users"
            :columns="columns"
            :server="false"
            caption="Usuarios activos"
        >
            <template #cell-name="{ row }">
                <p class="font-semibold">{{ row.name }}</p>
                <p class="text-xs text-muted-foreground">{{ row.email }}</p>
            </template>
            <template #cell-roles="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="role in row.roles"
                        :key="role"
                        variant="outline"
                        >{{ role }}</Badge
                    >
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        row.access_all_branches
                            ? 'Todas las sucursales'
                            : row.branches.join(', ') || 'Propio y reportes'
                    }}
                </p>
            </template>
            <template #cell-last_login_at="{ row }">
                <DateTimeDisplay
                    :value="row.last_login_at"
                    format="relative"
                    fallback="Nunca"
                />
            </template>
            <template #cell-flags="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="flag in row.flags"
                        :key="flag"
                        :variant="
                            flag === 'inactive' ? 'secondary' : 'destructive'
                        "
                    >
                        <KeyRound v-if="flag === 'two_factor_missing'" />
                        {{ FLAG_LABEL[flag] }}
                    </Badge>
                    <span
                        v-if="row.flags.length === 0"
                        class="text-xs text-subtle-foreground"
                        >Sin hallazgos</span
                    >
                </div>
            </template>
            <template #cell-decision="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <StatusBadge
                        v-if="decisionOf(row)"
                        :status="
                            decisionOf(row)?.decision === 'keep'
                                ? 'approved'
                                : 'rejected'
                        "
                        :label="
                            decisionOf(row)?.decision === 'keep'
                                ? 'Confirmado'
                                : 'Desactivado'
                        "
                        size="sm"
                    />
                    <template v-else-if="isOpen && can.manage">
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="busy === row.id"
                            @click="decide(row, 'keep')"
                        >
                            <Check />
                            Confirmar
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="busy === row.id"
                            @click="askDeactivate(row)"
                        >
                            <Archive />
                            Desactivar
                        </Button>
                    </template>
                </div>
            </template>
        </DataTable>

        <section v-if="history.length > 1" class="text-sm">
            <h2 class="mb-2 font-semibold">Revisiones anteriores</h2>
            <ul class="flex flex-wrap gap-2">
                <li v-for="h in history" :key="h.id">
                    <Badge variant="outline"
                        >{{ h.period }} ·
                        {{
                            h.status === 'open' ? 'Abierta' : 'Completada'
                        }}</Badge
                    >
                </li>
            </ul>
        </section>

        <div class="flex justify-end">
            <Button variant="ghost" as-child>
                <Link href="/usuarios">Ver usuarios</Link>
            </Button>
        </div>

        <ConfirmDialog
            v-model:open="deactivateOpen"
            :title="`¿Desactivar a ${deactivateTarget?.name ?? ''}?`"
            description="Pierde el acceso de inmediato. Su historial se conserva."
            confirm-label="Desactivar"
            tone="danger"
            :icon="Archive"
            @confirm="confirmDeactivate"
        />
        <ConfirmDialog
            v-model:open="completeOpen"
            title="¿Cerrar la revisión?"
            :description="`${decided} de ${users.length} usuarios con decisión registrada.`"
            confirm-label="Cerrar"
            :icon="ClipboardCheck"
            @confirm="complete"
        />
    </div>
</template>
