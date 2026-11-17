<script setup lang="ts">
import { Link, useForm, useHttp } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleCheck,
    Clock3,
    Copy,
    Eraser,
    Hourglass,
    Layers,
    Moon,
    Plus,
    Repeat,
    Save,
    Scale,
    ShieldAlert,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, watch } from 'vue';
import ScheduleController from '@/actions/App/Http/Controllers/Web/Admin/ScheduleController';
import DatePicker from '@/components/app/DatePicker.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { HelpStep } from '@/types';
import NumberInput from './components/NumberInput.vue';
import TimeField from './components/TimeField.vue';
import WeekStrip from './components/WeekStrip.vue';
import {
    crossesMidnight,
    dayLabel,
    hoursLabel,
    segmentMinutes,
    SHIFT_KIND_LABELS,
} from './types';
import type {
    LegalEvaluation,
    LegalSummary,
    ScheduleData,
    ScheduleSegment,
    ScheduleTypeOption,
    ShiftKind,
} from './types';

const props = defineProps<{
    schedule: ScheduleData | null;
    types: ScheduleTypeOption[];
    legal: LegalSummary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Horarios', href: '/horarios' },
            { title: 'Editor' },
        ],
    },
});

const editing = computed(() => props.schedule !== null);

type SegmentDraft = ScheduleSegment & { key: number };

let keySeq = 0;
const draft = (
    s: Omit<ScheduleSegment, 'ordinal'> & { ordinal?: number },
): SegmentDraft => ({
    ordinal: 1,
    ...s,
    key: ++keySeq,
});

const initialSegments: SegmentDraft[] = props.schedule
    ? props.schedule.segments.map((s) => draft(s))
    : [0, 1, 2, 3, 4].map((d) =>
          draft({
              day_index: d,
              starts_at: '09:00',
              ends_at: '18:00',
              break_minutes: 60,
          }),
      );

const form = useForm({
    name: props.schedule?.name ?? '',
    code: props.schedule?.code ?? '',
    type: props.schedule?.type ?? 'fixed',
    shift_kind: (props.schedule?.shift_kind ?? 'day') as ShiftKind,
    cycle_length_days: props.schedule?.cycle_length_days ?? 7,
    cycle_anchor_date:
        props.schedule?.cycle_anchor_date ?? (null as string | null),
    tolerance_minutes: props.schedule?.tolerance_minutes ?? 10,
    flex_minutes: props.schedule?.flex_minutes ?? 0,
    description: props.schedule?.description ?? '',
    active: props.schedule?.active ?? true,
    applies_from: null as string | null,
    segments: initialSegments,
});

const help: HelpStep[] = [
    {
        title: 'Segmentos',
        text: 'Cada día puede tener hasta 4 segmentos (horario partido).',
        icon: Layers,
    },
    {
        title: 'Cruce de medianoche',
        text: 'Si la salida es menor o igual a la entrada, termina al día siguiente.',
        icon: Moon,
    },
    {
        title: 'Ciclos',
        text: 'Para rotativos usa un ciclo de N días y la fecha en que inicia el día 1.',
        icon: Repeat,
    },
    {
        title: 'Validación',
        text: 'El panel lateral calcula la jornada contra la ley vigente hoy, en la vigencia y en las próximas reducciones.',
        icon: Scale,
    },
];

const cyclePresets = [7, 14, 21, 28];
const isWeekly = computed(() => form.cycle_length_days === 7);

/* ----------------------------------------------------------- Segmentos -- */
const days = computed(() =>
    Array.from({ length: form.cycle_length_days }, (_, index) => ({
        index,
        segments: form.segments
            .filter((s) => s.day_index === index)
            .sort((a, b) => a.starts_at.localeCompare(b.starts_at)),
    })),
);

watch(
    () => form.cycle_length_days,
    (cycle) => {
        form.segments = form.segments.filter((s) => s.day_index < cycle);
    },
);

function addSegment(day: number): void {
    const existing = form.segments.filter((s) => s.day_index === day);

    if (existing.length >= 4) {
        return;
    }

    const last = existing
        .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
        .at(-1);
    const start = last ? last.ends_at : '09:00';
    const [h, m] = start.split(':').map(Number);
    const end = `${String((h + 4) % 24).padStart(2, '0')}:${String(m).padStart(2, '0')}`;

    form.segments.push(
        draft({
            day_index: day,
            starts_at: start,
            ends_at: end,
            break_minutes: 0,
        }),
    );
}

function removeSegment(key: number): void {
    form.segments = form.segments.filter((s) => s.key !== key);
}

function clearDay(day: number): void {
    form.segments = form.segments.filter((s) => s.day_index !== day);
}

function copyDayTo(source: number, targets: number[]): void {
    const template = form.segments.filter((s) => s.day_index === source);
    form.segments = [
        ...form.segments.filter(
            (s) => !targets.includes(s.day_index) || s.day_index === source,
        ),
        ...targets
            .filter((t) => t !== source)
            .flatMap((t) => template.map((s) => draft({ ...s, day_index: t }))),
    ];
}

function copyToWeekdays(source: number): void {
    copyDayTo(
        source,
        isWeekly.value
            ? [0, 1, 2, 3, 4]
            : Array.from({ length: form.cycle_length_days }, (_, i) => i),
    );
}

function dayMinutes(day: number): number {
    return form.segments
        .filter((s) => s.day_index === day)
        .reduce((sum, s) => sum + segmentMinutes(s), 0);
}

const localWeekly = computed(() => {
    const total = form.segments.reduce((sum, s) => sum + segmentMinutes(s), 0);

    return Math.floor((total * 7) / Math.max(1, form.cycle_length_days));
});

function payloadSegments(): ScheduleSegment[] {
    const out: ScheduleSegment[] = [];

    for (const day of days.value) {
        day.segments.forEach((s, i) => {
            out.push({
                day_index: s.day_index,
                ordinal: i + 1,
                starts_at: s.starts_at,
                ends_at: s.ends_at,
                break_minutes: Number(s.break_minutes) || 0,
            });
        });
    }

    return out;
}

/* ------------------------------------------------- Validación en vivo -- */
const http = useHttp<
    {
        shift_kind: ShiftKind;
        cycle_length_days: number;
        applies_from: string | null;
        segments: ScheduleSegment[];
    },
    LegalEvaluation
>({
    shift_kind: form.shift_kind,
    cycle_length_days: form.cycle_length_days,
    applies_from: form.applies_from,
    segments: payloadSegments(),
});

let timer: ReturnType<typeof setTimeout> | undefined;

function runCheck(): void {
    clearTimeout(timer);
    timer = setTimeout(() => {
        http.cancel();
        http.shift_kind = form.shift_kind;
        http.cycle_length_days = form.cycle_length_days;
        http.applies_from = form.applies_from;
        http.segments = payloadSegments();
        void http.post(ScheduleController.check.url()).catch(() => undefined);
    }, 450);
}

watch(
    () => [
        form.shift_kind,
        form.cycle_length_days,
        form.applies_from,
        JSON.stringify(payloadSegments()),
    ],
    runCheck,
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));

const evaluation = computed(() => http.response);
const weekly = computed(
    () => evaluation.value?.weekly_minutes ?? localWeekly.value,
);
const violations = computed(() => {
    const v = evaluation.value?.violations ?? {};

    return Object.values(v).flat();
});
const legalOk = computed(
    () => evaluation.value !== null && violations.value.length === 0,
);
const usage = computed(() =>
    Math.min(100, (weekly.value / Math.max(1, props.legal.weekly_max)) * 100),
);

/* ---------------------------------------------------------- Envío -- */
const segmentErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key.startsWith('segments'))
        .map(([, message]) => message),
);

function submit(): void {
    const action = props.schedule
        ? ScheduleController.update(props.schedule.id)
        : ScheduleController.store();

    form.transform((data) => ({
        ...data,
        code: data.code.toUpperCase(),
        cycle_anchor_date:
            data.cycle_length_days === 7
                ? data.cycle_anchor_date || null
                : data.cycle_anchor_date,
        segments: payloadSegments(),
    })).submit(action, { preserveScroll: true });
}

function setShiftKind(value: unknown): void {
    if (typeof value === 'string' && value) {
        form.shift_kind = value as ShiftKind;
    }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            :title="editing ? `Editar ${schedule?.name}` : 'Nuevo horario'"
            description="Define la jornada; la validación legal se calcula al momento."
            :icon="Clock3"
            :help="help"
            help-title="Editor de horarios"
            back-href="/horarios"
            back-label="Horarios"
        />

        <form
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]"
            @submit.prevent="submit"
        >
            <div class="min-w-0 space-y-6">
                <!-- 1. Datos -->
                <SectionCard title="Datos" :icon="Clock3" divided>
                    <div
                        class="grid gap-5 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
                    >
                        <FormField
                            label="Nombre"
                            :error="form.errors.name"
                            required
                            v-slot="{ id, describedBy }"
                        >
                            <Input
                                :id="id"
                                v-model="form.name"
                                placeholder="Matutino L–V"
                                :aria-describedby="describedBy"
                                :aria-invalid="
                                    form.errors.name ? true : undefined
                                "
                            />
                        </FormField>
                        <FormField
                            label="Clave"
                            :error="form.errors.code"
                            required
                            v-slot="{ id, describedBy }"
                        >
                            <Input
                                :id="id"
                                v-model="form.code"
                                class="font-mono uppercase"
                                placeholder="MAT-LV"
                                :aria-describedby="describedBy"
                                :aria-invalid="
                                    form.errors.code ? true : undefined
                                "
                            />
                        </FormField>
                        <FormField
                            label="Notas"
                            hint="Opcional"
                            :error="form.errors.description"
                            class="sm:col-span-2"
                            v-slot="{ id }"
                        >
                            <Textarea
                                :id="id"
                                v-model="form.description"
                                rows="2"
                                placeholder="Uso interno"
                            />
                        </FormField>
                    </div>
                </SectionCard>

                <!-- 2. Tipo y ciclo -->
                <SectionCard title="Tipo y ciclo" :icon="Repeat" divided>
                    <div class="space-y-6">
                        <div
                            role="radiogroup"
                            aria-label="Tipo de horario"
                            class="grid gap-2 sm:grid-cols-3"
                        >
                            <button
                                v-for="t in types"
                                :key="t.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.type === t.value"
                                :class="
                                    cn(
                                        'rounded-xl border px-3 py-2.5 text-left transition-standard hover:border-brand/50',
                                        form.type === t.value
                                            ? 'border-brand bg-brand-soft/60 ring-2 ring-brand/25'
                                            : 'bg-card',
                                    )
                                "
                                @click="form.type = t.value"
                            >
                                <span class="block text-sm font-semibold">{{
                                    t.label
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ t.description }}</span
                                >
                            </button>
                        </div>
                        <p
                            v-if="form.errors.type"
                            class="text-sm text-danger-soft-foreground"
                        >
                            {{ form.errors.type }}
                        </p>

                        <div class="grid gap-5 md:grid-cols-2">
                            <FormField
                                label="Jornada"
                                :error="form.errors.shift_kind"
                                required
                            >
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    :model-value="form.shift_kind"
                                    class="w-full"
                                    @update:model-value="setShiftKind"
                                >
                                    <ToggleGroupItem
                                        v-for="(
                                            label, key
                                        ) in SHIFT_KIND_LABELS"
                                        :key="key"
                                        :value="key"
                                        class="flex-1"
                                    >
                                        {{ label }}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </FormField>

                            <FormField
                                label="Duración del ciclo"
                                :error="form.errors.cycle_length_days"
                                required
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button
                                        v-for="p in cyclePresets"
                                        :key="p"
                                        type="button"
                                        size="sm"
                                        :variant="
                                            form.cycle_length_days === p
                                                ? 'default'
                                                : 'outline'
                                        "
                                        @click="form.cycle_length_days = p"
                                    >
                                        {{ p === 7 ? 'Semanal' : `${p} días` }}
                                    </Button>
                                    <NumberInput
                                        v-model="form.cycle_length_days"
                                        :min="1"
                                        :max="56"
                                        suffix="días"
                                        aria-label="Días del ciclo"
                                        class="w-40"
                                    />
                                </div>
                            </FormField>

                            <FormField
                                v-if="!isWeekly"
                                label="Inicio del ciclo (día 1)"
                                :error="form.errors.cycle_anchor_date"
                                required
                                v-slot="{ id }"
                            >
                                <DatePicker
                                    :id="id"
                                    v-model="form.cycle_anchor_date"
                                    dropdowns
                                    :invalid="!!form.errors.cycle_anchor_date"
                                />
                            </FormField>
                        </div>
                    </div>
                </SectionCard>

                <!-- 3. Segmentos -->
                <SectionCard
                    title="Jornada por día"
                    :icon="Layers"
                    divided
                    padding="none"
                >
                    <template #actions>
                        <Badge variant="outline" class="numeric"
                            >{{ hoursLabel(localWeekly) }} / semana</Badge
                        >
                    </template>

                    <div
                        v-if="segmentErrors.length > 0"
                        class="mx-5 mt-4 space-y-1 rounded-xl border border-danger/25 bg-danger-soft px-4 py-3 text-sm text-danger-soft-foreground"
                        role="alert"
                    >
                        <p
                            v-for="(message, i) in segmentErrors"
                            :key="i"
                            class="flex gap-2"
                        >
                            <ShieldAlert
                                class="mt-0.5 icon-sm shrink-0"
                                aria-hidden="true"
                            />
                            {{ message }}
                        </p>
                    </div>

                    <ul class="divide-y">
                        <li
                            v-for="day in days"
                            :key="day.index"
                            class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start"
                        >
                            <div
                                class="flex w-full items-center justify-between gap-3 lg:w-36 lg:flex-col lg:items-start"
                            >
                                <div>
                                    <p class="text-sm font-semibold">
                                        {{
                                            dayLabel(
                                                day.index,
                                                form.cycle_length_days,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="numeric text-xs text-muted-foreground"
                                    >
                                        {{
                                            day.segments.length > 0
                                                ? hoursLabel(
                                                      dayMinutes(day.index),
                                                  )
                                                : 'Descanso'
                                        }}
                                    </p>
                                </div>
                                <div class="flex gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        :disabled="day.segments.length >= 4"
                                        :aria-label="`Agregar segmento a ${dayLabel(day.index, form.cycle_length_days)}`"
                                        @click="addSegment(day.index)"
                                    >
                                        <Plus aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-if="day.segments.length > 0"
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        :aria-label="
                                            isWeekly
                                                ? 'Copiar a lunes–viernes'
                                                : 'Copiar a todo el ciclo'
                                        "
                                        :title="
                                            isWeekly
                                                ? 'Copiar a lunes–viernes'
                                                : 'Copiar a todo el ciclo'
                                        "
                                        @click="copyToWeekdays(day.index)"
                                    >
                                        <Copy aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-if="day.segments.length > 0"
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Marcar como descanso"
                                        title="Marcar como descanso"
                                        @click="clearDay(day.index)"
                                    >
                                        <Eraser aria-hidden="true" />
                                    </Button>
                                </div>
                            </div>

                            <div class="min-w-0 flex-1 space-y-2">
                                <button
                                    v-if="day.segments.length === 0"
                                    type="button"
                                    class="flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-dashed text-sm text-muted-foreground transition-standard hover:border-brand/50 hover:text-foreground"
                                    @click="addSegment(day.index)"
                                >
                                    <Plus class="icon-sm" aria-hidden="true" />
                                    Día de descanso · agregar jornada
                                </button>
                                <div
                                    v-for="segment in day.segments"
                                    :key="segment.key"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border bg-surface-2 px-3 py-2"
                                >
                                    <TimeField
                                        v-model="segment.starts_at"
                                        aria-label="Entrada"
                                    />
                                    <span class="text-xs text-muted-foreground"
                                        >a</span
                                    >
                                    <TimeField
                                        v-model="segment.ends_at"
                                        aria-label="Salida"
                                    />
                                    <Badge
                                        v-if="
                                            crossesMidnight(
                                                segment.starts_at,
                                                segment.ends_at,
                                            )
                                        "
                                        variant="outline"
                                        class="gap-1 border-info/30 bg-info-soft text-info-soft-foreground"
                                    >
                                        <Moon aria-hidden="true" />
                                        +1 día
                                    </Badge>
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Descanso</span
                                        >
                                        <NumberInput
                                            v-model="segment.break_minutes"
                                            :min="0"
                                            :max="240"
                                            :step="5"
                                            suffix="min"
                                            aria-label="Minutos de descanso"
                                            class="w-36"
                                        />
                                    </div>
                                    <span
                                        class="ml-auto numeric text-sm font-semibold"
                                        >{{
                                            hoursLabel(segmentMinutes(segment))
                                        }}</span
                                    >
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Quitar segmento"
                                        @click="removeSegment(segment.key)"
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </SectionCard>

                <!-- 4. Tolerancias y vigencia -->
                <SectionCard
                    title="Tolerancias y vigencia"
                    :icon="Hourglass"
                    divided
                >
                    <div class="grid gap-5 md:grid-cols-3">
                        <FormField
                            label="Tolerancia de entrada"
                            :error="form.errors.tolerance_minutes"
                            v-slot="{ id }"
                        >
                            <NumberInput
                                :id="id"
                                v-model="form.tolerance_minutes"
                                :min="0"
                                :max="60"
                                suffix="min"
                            />
                        </FormField>
                        <FormField
                            label="Ventana flexible"
                            :error="form.errors.flex_minutes"
                            v-slot="{ id }"
                        >
                            <NumberInput
                                :id="id"
                                v-model="form.flex_minutes"
                                :min="0"
                                :max="240"
                                :step="5"
                                suffix="min"
                            />
                        </FormField>
                        <FormField
                            label="Validar a partir de"
                            hint="Opcional"
                            :error="form.errors.applies_from"
                            v-slot="{ id }"
                        >
                            <DatePicker
                                :id="id"
                                v-model="form.applies_from"
                                :min="legal.today"
                                clearable
                                dropdowns
                            />
                        </FormField>
                        <label class="flex items-center gap-3 md:col-span-3">
                            <Switch v-model="form.active" />
                            <span class="text-sm">Disponible para asignar</span>
                        </label>
                    </div>
                </SectionCard>
            </div>

            <!-- Panel legal -->
            <aside class="space-y-4 xl:sticky xl:top-20 xl:self-start">
                <section
                    :class="
                        cn(
                            'overflow-hidden rounded-2xl border bg-card shadow-elev-1',
                            evaluation && !legalOk && 'border-danger/40',
                        )
                    "
                    aria-live="polite"
                >
                    <div class="space-y-3 p-5">
                        <div class="flex items-center justify-between">
                            <p
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Validación legal
                            </p>
                            <Spinner v-if="http.processing" class="size-4" />
                            <CircleCheck
                                v-else-if="legalOk"
                                class="icon-md text-success"
                                aria-label="Cumple"
                            />
                            <ShieldAlert
                                v-else-if="evaluation"
                                class="icon-md text-danger"
                                aria-label="No cumple"
                            />
                        </div>
                        <p class="font-display numeric text-3xl font-bold">
                            {{ hoursLabel(weekly) }}
                            <span
                                class="text-sm font-medium text-muted-foreground"
                                >/ sem</span
                            >
                        </p>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-surface-3"
                        >
                            <div
                                :class="
                                    cn(
                                        'h-full rounded-full transition-all',
                                        legalOk || !evaluation
                                            ? 'bg-brand'
                                            : 'bg-danger',
                                    )
                                "
                                :style="{ width: `${usage}%` }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Máximo hoy {{ hoursLabel(legal.weekly_max) }} ({{
                                legal.version
                            }})
                        </p>
                    </div>

                    <ul
                        v-if="evaluation && evaluation.checks.length > 0"
                        class="divide-y border-t"
                    >
                        <li
                            v-for="check in evaluation.checks"
                            :key="check.date"
                            class="flex items-center gap-3 px-5 py-2.5 text-sm"
                        >
                            <CalendarClock
                                class="icon-sm text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="flex-1">
                                {{
                                    check.date === legal.today
                                        ? 'Hoy'
                                        : formatDate(check.date, 'date')
                                }}
                                <span class="text-xs text-muted-foreground"
                                    >· máx
                                    {{ hoursLabel(check.weekly_max) }}</span
                                >
                            </span>
                            <Badge
                                variant="outline"
                                :class="
                                    check.ok
                                        ? 'border-success/25 bg-success-soft text-success-soft-foreground'
                                        : 'border-danger/25 bg-danger-soft text-danger-soft-foreground'
                                "
                            >
                                {{ check.ok ? 'Cumple' : 'Excede' }}
                            </Badge>
                        </li>
                    </ul>

                    <ul
                        v-if="violations.length > 0"
                        class="space-y-2 border-t bg-danger-soft/40 px-5 py-4"
                    >
                        <li
                            v-for="(message, i) in violations"
                            :key="i"
                            class="flex gap-2 text-xs text-danger-soft-foreground"
                        >
                            <ShieldAlert
                                class="mt-0.5 icon-xs shrink-0"
                                aria-hidden="true"
                            />
                            {{ message }}
                        </li>
                    </ul>
                </section>

                <SectionCard title="Vista semanal" padding="sm">
                    <WeekStrip
                        :segments="form.segments"
                        :cycle="form.cycle_length_days"
                    />
                </SectionCard>

                <div class="flex gap-2">
                    <Button variant="outline" class="flex-1" as-child>
                        <Link href="/horarios">Cancelar</Link>
                    </Button>
                    <Button
                        type="submit"
                        class="flex-1"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else aria-hidden="true" />
                        {{ editing ? 'Guardar' : 'Crear horario' }}
                    </Button>
                </div>
            </aside>
        </form>
    </div>
</template>
