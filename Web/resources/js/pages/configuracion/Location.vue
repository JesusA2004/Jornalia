<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Briefcase,
    Check,
    Clock3,
    LocateFixed,
    PauseCircle,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import PlatformSettingsController from '@/actions/App/Http/Controllers/Web/Admin/PlatformSettingsController';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import NumberInput from './components/NumberInput.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import type { Option } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Ubicación' },
        ],
    },
});

type PositionRow = {
    id: number;
    name: string;
    code: string;
    active: boolean;
    location_tracking: 'on_punch' | 'during_shift';
};

const props = defineProps<{
    positions: PositionRow[];
    settings: {
        retention_days: number;
        contingency_minutes: number;
        retention_confirmed: boolean;
    };
    modes: Option[];
    pending_purge: number;
    can: { manage: boolean };
}>();

const form = useForm({
    retention_days: props.settings.retention_days as number | null,
    contingency_minutes: props.settings.contingency_minutes as number | null,
    retention_confirmed: props.settings.retention_confirmed,
});

function save(): void {
    form.put(PlatformSettingsController.updateLocation.url(), {
        preserveScroll: true,
    });
}

const saving = ref<number | null>(null);

function setMode(position: PositionRow, mode: string | null): void {
    if (!mode || mode === position.location_tracking) {
        return;
    }
    router.put(
        PlatformSettingsController.updatePosition.url(position.id),
        { location_tracking: mode },
        {
            preserveScroll: true,
            onStart: () => (saving.value = position.id),
            onFinish: () => (saving.value = null),
        },
    );
}

const help = [
    {
        title: 'Por puesto',
        text: '«Solo ubicación al marcar» para oficina y sucursal; «Seguimiento durante la jornada» sólo para puestos de campo.',
        icon: Briefcase,
    },
    {
        title: 'Durante la jornada',
        text: 'Inicia con la entrada, se pausa en descanso o comida y termina con la salida. La app muestra un aviso mientras comparte.',
        icon: PauseCircle,
    },
    {
        title: 'Salida omitida',
        text: 'El seguimiento se corta al fin del turno más el margen. Nunca se registra una salida automática.',
        icon: Clock3,
    },
    {
        title: 'Conservación',
        text: 'Los recorridos se depuran solos al vencer el plazo que defina Jurídico (LFPDPPP). Los marcajes no se borran.',
        icon: Trash2,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Ubicación: reglas de uso y conservación."
        :icon="LocateFixed"
        :help="help"
        help-title="Ubicación"
    />

    <SettingsNav />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <SectionCard
            title="Ubicación por puesto"
            :icon="Briefcase"
            divided
            padding="none"
        >
            <EmptyState
                v-if="positions.length === 0"
                :icon="Briefcase"
                title="Sin puestos"
                description="Registra los puestos en Organización."
            />
            <ul v-else class="divide-y">
                <li
                    v-for="position in positions"
                    :key="position.id"
                    class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ position.name }}
                        </p>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ position.code }}
                            <StatusBadge
                                v-if="!position.active"
                                status="inactive"
                                label="Inactivo"
                                size="sm"
                            />
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Spinner v-if="saving === position.id" />
                        <SelectField
                            :model-value="position.location_tracking"
                            :options="modes"
                            :disabled="!can.manage || saving !== null"
                            class="w-full sm:w-64"
                            @update:model-value="
                                (v) => setMode(position, v as string | null)
                            "
                        />
                    </div>
                </li>
            </ul>
        </SectionCard>

        <SectionCard title="Reglas" :icon="Clock3" divided>
            <form class="grid gap-5" @submit.prevent="save">
                <FormField
                    label="Margen tras el fin de turno"
                    :error="form.errors.contingency_minutes"
                    v-slot="{ id }"
                >
                    <NumberInput
                        :id="id"
                        v-model="form.contingency_minutes"
                        :min="0"
                        :max="240"
                        :step="15"
                        suffix="min"
                        :disabled="!can.manage"
                    />
                </FormField>
                <FormField
                    label="Conservar recorridos"
                    :error="form.errors.retention_days"
                    v-slot="{ id }"
                >
                    <NumberInput
                        :id="id"
                        v-model="form.retention_days"
                        :min="7"
                        :max="3650"
                        suffix="días"
                        :disabled="!can.manage"
                    />
                </FormField>
                <ToggleRow
                    v-model="form.retention_confirmed"
                    label="Plazo validado por Jurídico"
                    :description="
                        form.retention_confirmed
                            ? 'Confirmado'
                            : 'Pendiente: valor inicial editable'
                    "
                    :disabled="!can.manage"
                />
                <p class="text-xs text-muted-foreground tabular-nums">
                    Por depurar en la próxima ejecución: {{ pending_purge }}
                    punto(s).
                </p>
                <div v-if="can.manage" class="flex justify-end border-t pt-4">
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.isDirty"
                    >
                        <Spinner v-if="form.processing" />
                        <Check v-else class="icon-sm" />
                        Guardar
                    </Button>
                </div>
            </form>
        </SectionCard>
    </div>
</template>
