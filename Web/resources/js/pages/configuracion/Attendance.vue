<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Check,
    Clock4,
    Fingerprint,
    Globe,
    MapPinned,
    Pencil,
    Plus,
    RotateCcw,
    Satellite,
    ShieldCheck,
    Star,
    WifiOff,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AttendancePolicyController from '@/actions/App/Http/Controllers/Web/Admin/AttendancePolicyController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import InactiveToggle from './components/InactiveToggle.vue';
import NumberInput from './components/NumberInput.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import type { AttendancePolicyRow, ManageCan, Option } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Asistencia' },
        ],
    },
});

const props = withDefaults(
    defineProps<{
        policies: AttendancePolicyRow[];
        geofences: Option[];
        location_modes: Option[];
        auth_methods: Option[];
        face_provider?: { available: boolean; name: string | null };
        can: ManageCan;
    }>(),
    { face_provider: () => ({ available: false, name: null }) },
);

const showInactive = ref(false);
const inactiveCount = computed(
    () => props.policies.filter((p) => p.deleted).length,
);
const visible = computed(() =>
    props.policies.filter((p) => showInactive.value || !p.deleted),
);

const modeLabel = (value: string) =>
    props.location_modes.find((m) => m.value === value)?.label ?? value;
const methodLabel = (value: string) =>
    props.auth_methods.find((m) => m.value === value)?.label ?? value;

type PolicyForm = {
    name: string;
    location_mode: string;
    allowed_auth_methods: string[];
    require_strong_biometric: boolean;
    allow_offline: boolean;
    offline_max_hours: number | null;
    face_verification_required: boolean;
    web_punch_allowed: boolean;
    max_clock_skew_seconds: number | null;
    min_location_accuracy_meters: number | null;
    is_default: boolean;
    geofence_ids: string[];
};

const blank = (): PolicyForm => ({
    name: '',
    location_mode: 'record',
    allowed_auth_methods: ['device_biometric', 'device_credential'],
    require_strong_biometric: false,
    allow_offline: true,
    offline_max_hours: 72,
    face_verification_required: false,
    web_punch_allowed: false,
    max_clock_skew_seconds: 300,
    min_location_accuracy_meters: 150,
    is_default: false,
    geofence_ids: [],
});

const open = ref(false);
const editing = ref<AttendancePolicyRow | null>(null);
const form = useForm<PolicyForm>(blank());

function create(): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(policy: AttendancePolicyRow): void {
    editing.value = policy;
    const data: PolicyForm = {
        name: policy.name,
        location_mode: policy.location_mode,
        allowed_auth_methods: [...policy.allowed_auth_methods],
        require_strong_biometric: policy.require_strong_biometric,
        allow_offline: policy.allow_offline,
        offline_max_hours: policy.offline_max_hours,
        face_verification_required: policy.face_verification_required,
        web_punch_allowed: policy.web_punch_allowed,
        max_clock_skew_seconds: policy.max_clock_skew_seconds,
        min_location_accuracy_meters: policy.min_location_accuracy_meters,
        is_default: policy.is_default,
        geofence_ids: [...policy.geofence_ids],
    };
    form.defaults(data);
    form.reset();
    form.clearErrors();
    open.value = true;
}

function toggleMethod(value: string, checked: boolean | 'indeterminate'): void {
    const set = new Set(form.allowed_auth_methods);

    if (checked === true) {
        set.add(value);
    } else {
        set.delete(value);
    }

    form.allowed_auth_methods = [...set];
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.put(
            AttendancePolicyController.update.url(editing.value.id),
            options,
        );
    } else {
        form.post(AttendancePolicyController.store.url(), options);
    }
}

const confirmTarget = ref<AttendancePolicyRow | null>(null);
const confirmOpen = ref(false);
const busyId = ref<number | null>(null);

function askDeactivate(policy: AttendancePolicyRow): void {
    confirmTarget.value = policy;
    confirmOpen.value = true;
}

function deactivate(): void {
    const policy = confirmTarget.value;

    if (!policy) {
        return;
    }

    router.post(
        AttendancePolicyController.deactivate.url(policy.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (busyId.value = policy.id),
            onFinish: () => (busyId.value = null),
        },
    );
}

function restore(policy: AttendancePolicyRow): void {
    router.post(
        AttendancePolicyController.restore.url(policy.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (busyId.value = policy.id),
            onFinish: () => (busyId.value = null),
        },
    );
}

const errorOf = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const help = [
    {
        title: 'Modo de ubicación',
        text: 'Define si el marcaje requiere ubicación o geocerca.',
        icon: MapPinned,
    },
    {
        title: 'Métodos',
        text: 'Biometría y PIN del teléfono no guardan datos biométricos en el sistema.',
        icon: Fingerprint,
    },
    {
        title: 'Sin conexión',
        text: 'Los marcajes offline se sellan en el dispositivo y se envían al reconectar.',
        icon: WifiOff,
    },
    {
        title: 'Asignación',
        text: 'La política por defecto aplica a quien no tenga una asignada en su expediente.',
        icon: Star,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Reglas para registrar asistencia."
        :icon="MapPinned"
        :help="help"
        help-title="Políticas de asistencia"
    />

    <SettingsNav />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-display text-lg font-bold">Políticas de asistencia</h2>
        <div class="flex items-center gap-2">
            <InactiveToggle v-model="showInactive" :count="inactiveCount" />
            <Button v-if="can.manage" @click="create">
                <Plus class="icon-sm" />
                Nueva política
            </Button>
        </div>
    </div>

    <EmptyState
        v-if="visible.length === 0"
        :icon="MapPinned"
        tone="primary"
        bordered
        class="rounded-2xl"
        title="Sin políticas"
        description="Crea la primera política para definir cómo se registran los marcajes."
    />

    <div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
        <article
            v-for="policy in visible"
            :key="policy.id"
            :class="
                cn(
                    'group relative flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-elev-1 transition-standard hover:border-border-strong',
                    policy.is_default && 'ring-1 ring-brand/40',
                    policy.deleted && 'opacity-70',
                )
            "
        >
            <header class="flex items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate font-semibold">
                            {{ policy.name }}
                        </h3>
                        <StatusBadge
                            v-if="policy.is_default"
                            status="default"
                            label="Por defecto"
                            tone="brand"
                            size="sm"
                        />
                        <StatusBadge
                            v-if="policy.deleted"
                            status="inactive"
                            label="Inactiva"
                            size="sm"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ policy.assigned }}
                        {{
                            policy.assigned === 1
                                ? 'colaborador asignado'
                                : 'colaboradores asignados'
                        }}
                    </p>
                </div>
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                >
                    <MapPinned class="icon-sm" />
                </div>
            </header>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="col-span-2 rounded-xl bg-surface-2 px-3 py-2">
                    <dt class="text-xs text-muted-foreground">Ubicación</dt>
                    <dd class="font-medium">
                        {{ modeLabel(policy.location_mode) }}
                    </dd>
                    <dd
                        v-if="policy.geofences.length"
                        class="mt-1 flex flex-wrap gap-1"
                    >
                        <span
                            v-for="g in policy.geofences"
                            :key="g.name"
                            class="rounded-full border bg-card px-2 py-0.5 text-xs"
                        >
                            {{ g.name }} · {{ g.radius_meters }} m
                        </span>
                    </dd>
                </div>
                <div class="flex items-center gap-2">
                    <WifiOff class="icon-sm text-muted-foreground" />
                    <span>{{
                        policy.allow_offline
                            ? `Offline ${policy.offline_max_hours} h`
                            : 'Sin offline'
                    }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <Globe class="icon-sm text-muted-foreground" />
                    <span>{{
                        policy.web_punch_allowed
                            ? 'Marcaje web'
                            : 'Sin marcaje web'
                    }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <Clock4 class="icon-sm text-muted-foreground" />
                    <span>Reloj ±{{ policy.max_clock_skew_seconds }} s</span>
                </div>
                <div class="flex items-center gap-2">
                    <Satellite class="icon-sm text-muted-foreground" />
                    <span
                        >Precisión
                        {{ policy.min_location_accuracy_meters }} m</span
                    >
                </div>
            </dl>

            <div class="flex flex-wrap gap-1.5">
                <span
                    v-for="method in policy.allowed_auth_methods"
                    :key="method"
                    class="inline-flex items-center gap-1 rounded-full bg-surface-3 px-2.5 py-0.5 text-xs font-medium"
                >
                    <ShieldCheck class="icon-xs" />
                    {{ methodLabel(method) }}
                </span>
            </div>

            <footer v-if="can.manage" class="mt-auto flex gap-2 border-t pt-4">
                <template v-if="!policy.deleted">
                    <Button variant="outline" size="sm" @click="edit(policy)">
                        <Pencil class="icon-sm" />
                        Editar
                    </Button>
                    <Button
                        v-if="!policy.is_default"
                        variant="ghost"
                        size="sm"
                        :disabled="busyId === policy.id"
                        @click="askDeactivate(policy)"
                    >
                        <Archive class="icon-sm" />
                        Desactivar
                    </Button>
                </template>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    :disabled="busyId === policy.id"
                    @click="restore(policy)"
                >
                    <RotateCcw class="icon-sm" />
                    Reactivar
                </Button>
            </footer>
        </article>
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-xl">
            <SheetHeader class="border-b">
                <SheetTitle>{{
                    editing ? 'Editar política' : 'Nueva política'
                }}</SheetTitle>
                <SheetDescription
                    >Aplica a los marcajes nuevos.</SheetDescription
                >
            </SheetHeader>

            <form
                id="policy-form"
                class="grid flex-1 gap-6 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    label="Nombre"
                    :error="form.errors.name"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        placeholder="Ej. Ventas en campo"
                    />
                </FormField>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Ubicación</legend>
                    <RadioGroup v-model="form.location_mode" class="grid gap-2">
                        <label
                            v-for="mode in location_modes"
                            :key="mode.value"
                            :class="
                                cn(
                                    'flex cursor-pointer items-start gap-3 rounded-xl border bg-card px-4 py-3 transition-standard hover:border-border-strong',
                                    form.location_mode === mode.value &&
                                        'border-primary bg-primary-soft/40',
                                )
                            "
                        >
                            <RadioGroupItem
                                :value="mode.value"
                                class="mt-0.5"
                            />
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium">{{
                                    mode.label
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ mode.description }}</span
                                >
                            </span>
                        </label>
                    </RadioGroup>
                    <p
                        v-if="form.errors.location_mode"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ form.errors.location_mode }}
                    </p>
                </fieldset>

                <FormField
                    v-if="
                        form.location_mode === 'geofence' ||
                        form.geofence_ids.length > 0
                    "
                    label="Geocercas"
                    :error="form.errors.geofence_ids"
                    :required="form.location_mode === 'geofence'"
                    v-slot="{ id }"
                >
                    <ComboboxField
                        :id="id"
                        :model-value="form.geofence_ids"
                        :options="geofences"
                        multiple
                        placeholder="Selecciona geocercas"
                        empty-text="No hay geocercas activas. Créalas en Organización."
                        :invalid="!!form.errors.geofence_ids"
                        @update:model-value="
                            (v) =>
                                (form.geofence_ids = Array.isArray(v) ? v : [])
                        "
                    />
                </FormField>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Métodos permitidos
                    </legend>
                    <label
                        v-for="method in auth_methods"
                        :key="method.value"
                        :class="
                            cn(
                                'flex cursor-pointer items-start gap-3 rounded-xl border bg-card px-4 py-3 transition-standard hover:border-border-strong',
                                method.value === 'face_verification' &&
                                    !face_provider.available &&
                                    'cursor-not-allowed opacity-60',
                            )
                        "
                    >
                        <Checkbox
                            :model-value="
                                form.allowed_auth_methods.includes(method.value)
                            "
                            :disabled="
                                method.value === 'face_verification' &&
                                !face_provider.available &&
                                !form.allowed_auth_methods.includes(
                                    method.value,
                                )
                            "
                            class="mt-0.5"
                            @update:model-value="
                                (v) => toggleMethod(method.value, v)
                            "
                        />
                        <span class="space-y-0.5">
                            <span class="block text-sm font-medium">{{
                                method.label
                            }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{
                                    method.value === 'face_verification' &&
                                    !face_provider.available
                                        ? 'Requiere proveedor configurado'
                                        : method.description
                                }}
                            </span>
                        </span>
                    </label>
                    <p
                        v-if="errorOf('allowed_auth_methods')"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ errorOf('allowed_auth_methods') }}
                    </p>
                </fieldset>

                <div class="grid gap-2">
                    <ToggleRow
                        v-model="form.require_strong_biometric"
                        label="Biometría fuerte"
                        description="Rechaza biometría débil del dispositivo."
                    />
                    <ToggleRow
                        v-model="form.face_verification_required"
                        label="Verificación facial obligatoria"
                        :description="
                            face_provider.available
                                ? (face_provider.name ?? undefined)
                                : 'Requiere proveedor configurado'
                        "
                        :disabled="
                            !face_provider.available &&
                            !form.face_verification_required
                        "
                    />
                    <p
                        v-if="form.errors.face_verification_required"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ form.errors.face_verification_required }}
                    </p>
                    <ToggleRow
                        v-model="form.web_punch_allowed"
                        label="Marcaje web"
                        description="Permite marcar desde el navegador."
                    />
                    <ToggleRow
                        v-model="form.allow_offline"
                        label="Marcaje sin conexión"
                    />
                </div>

                <div class="grid gap-5 sm:grid-cols-3">
                    <FormField
                        v-if="form.allow_offline"
                        label="Máx. offline"
                        :error="form.errors.offline_max_hours"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form.offline_max_hours"
                            :min="1"
                            :max="168"
                            suffix="h"
                        />
                    </FormField>
                    <FormField
                        label="Tolerancia de reloj"
                        :error="form.errors.max_clock_skew_seconds"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form.max_clock_skew_seconds"
                            :min="30"
                            :max="3600"
                            :step="30"
                            suffix="s"
                        />
                    </FormField>
                    <FormField
                        label="Precisión GPS"
                        :error="form.errors.min_location_accuracy_meters"
                        v-slot="{ id }"
                    >
                        <NumberInput
                            :id="id"
                            v-model="form.min_location_accuracy_meters"
                            :min="5"
                            :max="1000"
                            :step="5"
                            suffix="m"
                        />
                    </FormField>
                </div>

                <ToggleRow
                    v-model="form.is_default"
                    label="Política por defecto"
                    description="Para quien no tenga una política asignada."
                    :disabled="editing?.is_default"
                />
                <p
                    v-if="form.errors.is_default"
                    class="text-xs text-danger-soft-foreground"
                >
                    {{ form.errors.is_default }}
                </p>
            </form>

            <SheetFooter class="border-t">
                <Button
                    type="submit"
                    form="policy-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Check v-else class="icon-sm" />
                    {{ editing ? 'Guardar cambios' : 'Crear política' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="confirmOpen"
        tone="danger"
        :title="`¿Desactivar «${confirmTarget?.name ?? ''}»?`"
        description="Se conserva su historial y podrás reactivarla."
        confirm-label="Desactivar"
        @confirm="deactivate"
    />
</template>
