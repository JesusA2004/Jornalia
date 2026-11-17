<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { BellRing, Lock, Megaphone, Send, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AnnouncementController from '@/actions/App/Http/Controllers/Web/Comunicados/AnnouncementController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { HelpStep, SelectOption } from '@/types';
import TimeField from './components/TimeField.vue';
import type { AnnouncementCreateProps, AudienceType, Priority } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Comunicados', href: '/comunicados' },
            { title: 'Nuevo', href: '/comunicados/nuevo' },
        ],
    },
});

const props = defineProps<AnnouncementCreateProps>();

const help: HelpStep[] = [
    {
        title: 'Mensaje',
        text: 'Título de hasta 80 caracteres y mensaje de hasta 1000.',
        icon: Megaphone,
    },
    {
        title: 'Destinatarios',
        text: 'Sólo personal activo dentro de tu alcance.',
        icon: Users,
    },
    {
        title: 'Privacidad',
        text: 'En la pantalla de bloqueo sólo aparece «Tienes un comunicado nuevo».',
        icon: Lock,
    },
];

const audienceOptions: SelectOption[] = [
    { value: 'all', label: 'Todo el personal' },
    { value: 'branches', label: 'Por sucursal' },
    { value: 'departments', label: 'Por departamento' },
    { value: 'positions', label: 'Por puesto' },
    { value: 'employees', label: 'Colaboradores seleccionados' },
];

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    title: '',
    body: '',
    priority: 'normal' as Priority,
    audience_type: 'all' as AudienceType,
    audience_ids: [] as string[],
    send_mode: 'now' as 'now' | 'scheduled',
    scheduled_date: today as string | null,
    scheduled_time: '09:00',
});

const audienceType = computed<string | null>({
    get: () => form.audience_type,
    set: (value) => {
        form.audience_type = (value ?? 'all') as AudienceType;
        form.audience_ids = [];
    },
});

const groupOptions = computed<SelectOption[]>(() => {
    switch (form.audience_type) {
        case 'branches':
            return props.options.branches;
        case 'departments':
            return props.options.departments;
        case 'positions':
            return props.options.positions;
        default:
            return [];
    }
});

const audienceIds = computed<string[] | null>({
    get: () => form.audience_ids,
    set: (value) => (form.audience_ids = value ?? []),
});

/* Búsqueda remota de colaboradores (recarga parcial). */
const searching = ref(false);

function searchEmployees(term: string): void {
    router.reload({
        only: ['employeeOptions'],
        data: { q: term, selected: form.audience_ids },
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

/* Destinatarios estimados. */
const estimating = ref(false);

function refreshEstimate(): void {
    if (form.audience_type !== 'all' && form.audience_ids.length === 0) {
        return;
    }

    router.reload({
        only: ['estimate'],
        data: {
            audience_type: form.audience_type,
            audience_ids: form.audience_ids,
        },
        onStart: () => (estimating.value = true),
        onFinish: () => (estimating.value = false),
    });
}

watch(
    () => [form.audience_type, form.audience_ids.join(',')],
    refreshEstimate,
    { immediate: true },
);

const estimateLabel = computed(() => {
    if (form.audience_type !== 'all' && form.audience_ids.length === 0) {
        return 'Elige destinatarios';
    }

    if (props.estimate === undefined) {
        return '…';
    }

    return props.estimate === 1
        ? '1 colaborador'
        : `${props.estimate} colaboradores`;
});

function setMode(value: unknown): void {
    if (value === 'now' || value === 'scheduled') {
        form.send_mode = value;
    }
}

function setPriority(value: unknown): void {
    if (value === 'normal' || value === 'high') {
        form.priority = value;
    }
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        audience_ids:
            data.audience_type === 'all' ? [] : data.audience_ids.map(Number),
    })).submit(AnnouncementController.store());
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Nuevo comunicado"
            description="Llega a la bandeja de la app y como aviso push."
            :icon="Megaphone"
            :help="help"
            :back-href="AnnouncementController.index()"
            back-label="Comunicados"
        />

        <form
            class="grid gap-6 lg:grid-cols-[1fr_20rem]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <SectionCard title="Mensaje">
                    <div class="space-y-4">
                        <FormField
                            label="Título"
                            :error="form.errors.title"
                            required
                            :hint="`${form.title.length}/80`"
                            v-slot="{ id, describedBy }"
                        >
                            <Input
                                :id="id"
                                v-model="form.title"
                                maxlength="80"
                                placeholder="Cierre por inventario"
                                :aria-describedby="describedBy"
                                :aria-invalid="
                                    form.errors.title ? true : undefined
                                "
                            />
                        </FormField>
                        <FormField
                            label="Mensaje"
                            :error="form.errors.body"
                            required
                            :hint="`${form.body.length}/1000`"
                            v-slot="{ id, describedBy }"
                        >
                            <Textarea
                                :id="id"
                                v-model="form.body"
                                rows="5"
                                maxlength="1000"
                                placeholder="El viernes la sucursal cierra a las 15:00."
                                :aria-describedby="describedBy"
                                :aria-invalid="
                                    form.errors.body ? true : undefined
                                "
                            />
                        </FormField>
                        <FormField label="Prioridad">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                :model-value="form.priority"
                                @update:model-value="setPriority"
                            >
                                <ToggleGroupItem value="normal"
                                    >Normal</ToggleGroupItem
                                >
                                <ToggleGroupItem value="high"
                                    >Alta</ToggleGroupItem
                                >
                            </ToggleGroup>
                        </FormField>
                    </div>
                </SectionCard>

                <SectionCard title="Destinatarios">
                    <div class="space-y-4">
                        <FormField label="Enviar a" v-slot="{ id }">
                            <SelectField
                                :id="id"
                                v-model="audienceType"
                                :options="audienceOptions"
                            />
                        </FormField>

                        <FormField
                            v-if="
                                form.audience_type !== 'all' &&
                                form.audience_type !== 'employees'
                            "
                            label="Selección"
                            :error="
                                form.errors.audience_ids ??
                                form.errors['audience_ids.0']
                            "
                            required
                            v-slot="{ id }"
                        >
                            <ComboboxField
                                :id="id"
                                v-model="audienceIds"
                                :options="groupOptions"
                                multiple
                                placeholder="Elige uno o varios"
                                :invalid="!!form.errors.audience_ids"
                            />
                        </FormField>

                        <FormField
                            v-if="form.audience_type === 'employees'"
                            label="Colaboradores"
                            :error="
                                form.errors.audience_ids ??
                                form.errors['audience_ids.0']
                            "
                            required
                            v-slot="{ id }"
                        >
                            <ComboboxField
                                :id="id"
                                v-model="audienceIds"
                                :options="employeeOptions"
                                multiple
                                remote
                                :loading="searching"
                                placeholder="Busca por nombre o número"
                                :invalid="!!form.errors.audience_ids"
                                @search="searchEmployees"
                            />
                        </FormField>

                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Users class="icon-sm" aria-hidden="true" />
                            <Spinner v-if="estimating" class="size-3.5" />
                            <span class="numeric">{{ estimateLabel }}</span>
                        </p>
                    </div>
                </SectionCard>

                <SectionCard title="Envío">
                    <div class="space-y-4">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            :model-value="form.send_mode"
                            @update:model-value="setMode"
                        >
                            <ToggleGroupItem value="now">Ahora</ToggleGroupItem>
                            <ToggleGroupItem value="scheduled"
                                >Programar</ToggleGroupItem
                            >
                        </ToggleGroup>

                        <div
                            v-if="form.send_mode === 'scheduled'"
                            class="flex flex-wrap items-end gap-4"
                        >
                            <FormField
                                label="Fecha"
                                :error="form.errors.scheduled_date"
                                v-slot="{ id }"
                            >
                                <DatePicker
                                    :id="id"
                                    v-model="form.scheduled_date"
                                    :min="today"
                                    class="w-48"
                                />
                            </FormField>
                            <FormField
                                label="Hora"
                                :error="form.errors.scheduled_time"
                                :hint="timezone"
                                v-slot="{ id }"
                            >
                                <TimeField
                                    :id="id"
                                    v-model="form.scheduled_time"
                                    :invalid="!!form.errors.scheduled_time"
                                />
                            </FormField>
                        </div>
                    </div>
                </SectionCard>
            </div>

            <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
                <SectionCard title="Así se verá" variant="muted">
                    <div class="space-y-3">
                        <div class="rounded-2xl bg-card p-3 shadow-elev-1">
                            <p
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <BellRing class="icon-xs" aria-hidden="true" />
                                Pantalla de bloqueo
                            </p>
                            <p class="mt-1 text-sm font-semibold">
                                MR LANA Asistencias
                            </p>
                            <p class="text-sm">Tienes un comunicado nuevo.</p>
                        </div>
                        <div class="rounded-2xl bg-card p-3 shadow-elev-1">
                            <p class="text-xs text-muted-foreground">
                                Dentro de la app
                            </p>
                            <p class="mt-1 text-sm font-semibold break-words">
                                {{ form.title || 'Título' }}
                            </p>
                            <p
                                class="text-sm break-words whitespace-pre-line text-muted-foreground"
                            >
                                {{ form.body || 'Mensaje' }}
                            </p>
                        </div>
                        <p
                            v-if="!push.enabled"
                            class="text-xs text-muted-foreground"
                        >
                            Push sin configurar: sólo bandeja.
                        </p>
                    </div>
                </SectionCard>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="
                        form.processing ||
                        !form.title.trim() ||
                        !form.body.trim()
                    "
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    <Send v-else aria-hidden="true" />
                    {{
                        form.send_mode === 'scheduled' ? 'Programar' : 'Enviar'
                    }}
                </Button>
            </aside>
        </form>
    </div>
</template>
