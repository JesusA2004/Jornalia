<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Calculator,
    Columns3,
    FileSpreadsheet,
    Plus,
    Tags,
    Utensils,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import PayrollSettingsController from '@/actions/App/Http/Controllers/Web/Admin/PayrollSettingsController';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { HelpStep } from '@/types';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import type {
    MealPauseSettings,
    NoiSettings,
    Option,
    PayrollIncidenceType,
    SettingUpdate,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Nómina' },
        ],
    },
});

const props = defineProps<{
    noi: NoiSettings;
    /** Clave inicial (marcador) de cada concepto. */
    noi_placeholders: Record<string, string>;
    noi_pending: string[];
    column_options: Option[];
    required_columns: string[];
    date_formats: Option[];
    incidence_types: PayrollIncidenceType[];
    meal_pause: MealPauseSettings;
    updated: { noi: SettingUpdate; meal_pause: SettingUpdate };
    can: { manage: boolean };
}>();

/* ── Exportación para NOI ── */
const noi = useForm<NoiSettings>({
    ...props.noi,
    columns: props.noi.columns.map((c) => ({ ...c })),
    concepts: Object.fromEntries(
        Object.entries(props.noi.concepts).map(([k, c]) => [k, { ...c }]),
    ),
    // Todas las incidencias del catálogo, con o sin clave propia.
    incidence_types: Object.fromEntries(
        props.incidence_types.map((t) => [
            t.code,
            props.noi.incidence_types[t.code] ?? '',
        ]),
    ),
});

/** Un concepto activo cuya clave sigue igual al marcador inicial está pendiente de RH. */
function isPending(key: string): boolean {
    const concept = noi.concepts[key];

    return (
        !!concept &&
        concept.enabled &&
        concept.code.trim() === props.noi_placeholders[key]
    );
}

const pendingCount = computed(
    () => Object.keys(noi.concepts).filter((k) => isPending(k)).length,
);

const columnLabel = (key: string) =>
    props.column_options.find((o) => o.value === key)?.label ?? key;

const freeColumns = computed(() =>
    props.column_options.filter(
        (o) => !noi.columns.some((c) => c.key === o.value),
    ),
);

function addColumn(): void {
    const next = freeColumns.value[0];

    if (next) {
        noi.columns.push({ key: String(next.value), label: next.label });
    }
}

function moveColumn(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= noi.columns.length) {
        return;
    }

    const [item] = noi.columns.splice(index, 1);
    noi.columns.splice(target, 0, item);
}

function removeColumn(index: number): void {
    noi.columns.splice(index, 1);
}

function columnOptions(index: number): Option[] {
    const current = noi.columns[index]?.key;

    return props.column_options.filter(
        (o) =>
            o.value === current || !noi.columns.some((c) => c.key === o.value),
    );
}

function setColumnKey(index: number, key: string | null): void {
    const column = noi.columns[index];

    if (column && key) {
        column.key = key;
        column.label = columnLabel(key);
    }
}

const decimalModel = computed<string>({
    get: () => noi.decimal_separator,
    set: (v) => {
        if (v === '.' || v === ',') {
            noi.decimal_separator = v;
        }
    },
});

function fieldError(path: string): string | undefined {
    return (noi.errors as Record<string, string | undefined>)[path];
}

function saveNoi(): void {
    noi.put(PayrollSettingsController.updateNoi.url(), {
        preserveScroll: true,
    });
}

/* ── Pausa de comida ── */
const meal = useForm({
    pause: String(props.meal_pause.pause),
    confirmed_by_hr: props.meal_pause.confirmed_by_hr,
});

const pauseOptions = computed<Option[]>(() => [
    { value: '0', label: 'No se distingue (todas son descanso)' },
    ...Array.from({ length: props.meal_pause.max }, (_, i) => ({
        value: String(i + 1),
        label: `${i + 1}.ª pausa del día`,
    })),
]);

const pauseModel = computed<string | null>({
    get: () => meal.pause,
    set: (v) => (meal.pause = v ?? '0'),
});

function saveMeal(): void {
    meal.transform((data) => ({
        pause: Number(data.pause),
        confirmed_by_hr: data.confirmed_by_hr,
    })).put(PayrollSettingsController.updateMealPause.url(), {
        preserveScroll: true,
    });
}

const help: HelpStep[] = [
    {
        title: 'Claves NOI',
        text: 'Las claves iniciales son marcadores. Captura las de tu catálogo de NOI; mientras no cambien se muestran «Pendiente de RH».',
        icon: Tags,
    },
    {
        title: 'Columnas',
        text: 'Orden y encabezados del archivo para el importador de NOI. Clave de empleado, clave de concepto y cantidad son obligatorias.',
        icon: Columns3,
    },
    {
        title: 'Pausa de comida',
        text: 'Indica qué pausa del día es la comida. Los reportes la separan del descanso.',
        icon: Utensils,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Exportación para NOI y pausa de comida."
        :icon="Calculator"
        :help="help"
        help-title="Nómina"
    />

    <SettingsNav />

    <div class="grid gap-5">
        <Alert v-if="pendingCount > 0">
            <Tags class="icon-sm" />
            <AlertTitle>Claves NOI pendientes de RH</AlertTitle>
            <AlertDescription
                >{{ pendingCount }} concepto(s) usan el marcador
                inicial.</AlertDescription
            >
        </Alert>

        <form class="grid gap-5" @submit.prevent="saveNoi">
            <SectionCard
                title="Conceptos NOI"
                :icon="Tags"
                divided
                padding="none"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Concepto</TableHead>
                            <TableHead>Clave NOI</TableHead>
                            <TableHead class="w-24 text-center"
                                >Activo</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="(concept, key) in noi.concepts"
                            :key="key"
                        >
                            <TableCell class="min-w-48">
                                <Input
                                    v-model="concept.label"
                                    :aria-label="`Nombre de ${key}`"
                                    :disabled="!can.manage"
                                    :aria-invalid="
                                        !!fieldError(`concepts.${key}.label`)
                                    "
                                />
                                <p
                                    v-if="fieldError(`concepts.${key}.label`)"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ fieldError(`concepts.${key}.label`) }}
                                </p>
                            </TableCell>
                            <TableCell class="min-w-56">
                                <div class="flex items-center gap-2">
                                    <Input
                                        v-model="concept.code"
                                        class="w-40 font-mono"
                                        :aria-label="`Clave NOI de ${concept.label}`"
                                        :disabled="!can.manage"
                                        :aria-invalid="
                                            !!fieldError(`concepts.${key}.code`)
                                        "
                                    />
                                    <StatusBadge
                                        v-if="isPending(String(key))"
                                        status="pending"
                                        label="Pendiente de RH"
                                        tone="warning"
                                        size="sm"
                                    />
                                </div>
                                <p
                                    v-if="fieldError(`concepts.${key}.code`)"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ fieldError(`concepts.${key}.code`) }}
                                </p>
                            </TableCell>
                            <TableCell class="text-center">
                                <Switch
                                    v-model="concept.enabled"
                                    :aria-label="`Activar ${concept.label}`"
                                    :disabled="!can.manage"
                                />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <p
                    v-if="noi.errors.concepts"
                    class="px-4 py-2 text-xs text-destructive"
                >
                    {{ noi.errors.concepts }}
                </p>
            </SectionCard>

            <div class="grid gap-5 lg:grid-cols-2">
                <SectionCard
                    title="Columnas del archivo"
                    :icon="Columns3"
                    divided
                >
                    <ol class="grid gap-2">
                        <li
                            v-for="(column, index) in noi.columns"
                            :key="column.key"
                            class="flex flex-wrap items-center gap-2 rounded-xl border bg-surface-2/40 p-2"
                        >
                            <span
                                class="w-5 text-center text-xs text-muted-foreground"
                                >{{ index + 1 }}</span
                            >
                            <SelectField
                                :model-value="column.key"
                                :options="columnOptions(index)"
                                size="sm"
                                class="w-44"
                                :disabled="!can.manage"
                                @update:model-value="
                                    setColumnKey(index, $event)
                                "
                            />
                            <Input
                                v-model="column.label"
                                class="h-8 min-w-32 flex-1"
                                :aria-label="`Encabezado de ${columnLabel(column.key)}`"
                                :disabled="!can.manage"
                            />
                            <div v-if="can.manage" class="flex gap-0.5">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Subir"
                                    :disabled="index === 0"
                                    @click="moveColumn(index, -1)"
                                >
                                    <ArrowUp class="icon-sm" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Bajar"
                                    :disabled="index === noi.columns.length - 1"
                                    @click="moveColumn(index, 1)"
                                >
                                    <ArrowDown class="icon-sm" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Quitar"
                                    :disabled="
                                        required_columns.includes(column.key)
                                    "
                                    @click="removeColumn(index)"
                                >
                                    <X class="icon-sm" />
                                </Button>
                            </div>
                        </li>
                    </ol>
                    <p
                        v-if="noi.errors.columns"
                        class="mt-2 text-xs text-destructive"
                    >
                        {{ noi.errors.columns }}
                    </p>
                    <Button
                        v-if="can.manage && freeColumns.length > 0"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="addColumn"
                    >
                        <Plus class="icon-sm" />
                        Agregar columna
                    </Button>
                </SectionCard>

                <SectionCard title="Formato" :icon="FileSpreadsheet" divided>
                    <div class="grid gap-4">
                        <FormField
                            label="Formato de fecha"
                            required
                            :error="noi.errors.date_format"
                            v-slot="{ id }"
                        >
                            <SelectField
                                :id="id"
                                v-model="noi.date_format"
                                :options="date_formats"
                                class="w-48"
                                :disabled="!can.manage"
                            />
                        </FormField>
                        <FormField
                            label="Separador decimal"
                            required
                            :error="noi.errors.decimal_separator"
                        >
                            <ToggleGroup
                                v-model="decimalModel"
                                type="single"
                                variant="outline"
                                :disabled="!can.manage"
                            >
                                <ToggleGroupItem value="."
                                    >Punto (1.50)</ToggleGroupItem
                                >
                                <ToggleGroupItem value=","
                                    >Coma (1,50)</ToggleGroupItem
                                >
                            </ToggleGroup>
                        </FormField>
                        <ToggleRow
                            v-model="noi.only_payroll_incidences"
                            label="Sólo incidencias que afectan nómina"
                            :disabled="!can.manage"
                        />
                        <ToggleRow
                            v-model="noi.csv_institutional_header"
                            label="Encabezado institucional en el CSV"
                            description="Desactívalo si el importador sólo acepta datos."
                            :disabled="!can.manage"
                        />
                    </div>
                </SectionCard>
            </div>

            <SectionCard
                title="Incidencias en NOI"
                description="Sin clave propia se usa el código del sistema."
                :icon="Tags"
                divided
                padding="none"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Incidencia</TableHead>
                            <TableHead>Clave NOI</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="t in incidence_types" :key="t.code">
                            <TableCell>
                                <p class="font-medium">{{ t.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ t.code
                                    }}<template v-if="!t.affects_payroll">
                                        · No afecta nómina</template
                                    >
                                </p>
                            </TableCell>
                            <TableCell>
                                <Input
                                    v-model="noi.incidence_types[t.code]"
                                    class="w-40 font-mono"
                                    :placeholder="t.code"
                                    :aria-label="`Clave NOI de ${t.name}`"
                                    :disabled="!can.manage"
                                />
                                <p
                                    v-if="
                                        fieldError(`incidence_types.${t.code}`)
                                    "
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{
                                        fieldError(`incidence_types.${t.code}`)
                                    }}
                                </p>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </SectionCard>

            <div v-if="can.manage" class="flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="noi.processing">
                    <Spinner v-if="noi.processing" />
                    Guardar configuración NOI
                </Button>
                <span v-if="updated.noi" class="text-xs text-muted-foreground">
                    Último cambio: {{ updated.noi.by ?? '—' }} ·
                    <DateTimeDisplay :value="updated.noi.at" />
                </span>
            </div>
        </form>

        <SectionCard title="Pausa de comida" :icon="Utensils" divided>
            <form class="grid gap-4 sm:max-w-md" @submit.prevent="saveMeal">
                <FormField
                    label="Pausa que corresponde a la comida"
                    required
                    :error="meal.errors.pause"
                    v-slot="{ id }"
                >
                    <SelectField
                        :id="id"
                        v-model="pauseModel"
                        :options="pauseOptions"
                        :disabled="!can.manage"
                    />
                </FormField>
                <ToggleRow
                    v-model="meal.confirmed_by_hr"
                    label="Confirmado por RH"
                    :disabled="!can.manage"
                />
                <div class="flex flex-wrap items-center gap-3">
                    <StatusBadge
                        v-if="!meal_pause.confirmed_by_hr"
                        status="pending"
                        label="Pendiente de RH"
                        tone="warning"
                        size="sm"
                    />
                    <Button
                        v-if="can.manage"
                        type="submit"
                        :disabled="meal.processing"
                    >
                        <Spinner v-if="meal.processing" />
                        Guardar
                    </Button>
                    <span
                        v-if="updated.meal_pause"
                        class="text-xs text-muted-foreground"
                    >
                        Último cambio: {{ updated.meal_pause.by ?? '—' }} ·
                        <DateTimeDisplay :value="updated.meal_pause.at" />
                    </span>
                </div>
            </form>
        </SectionCard>
    </div>
</template>
