<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Download,
    FileSpreadsheet,
    FileText,
    FileType,
    Fingerprint,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ReportController from '@/actions/App/Http/Controllers/Web/Operacion/ReportController';
import FormField from '@/components/app/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';

type Format = 'xlsx' | 'pdf' | 'csv';

const props = defineProps<{
    report: string;
    title: string;
    params: {
        from: string | null;
        to: string | null;
        branch_id: string | null;
        department_id: string | null;
        position_id?: string | null;
        region?: string | null;
        employee_id: string | null;
        status: string | null;
        period_unit?: string | null;
    };
}>();

const open = ref(false);

const FORMATS: Array<{
    value: Format;
    label: string;
    hint: string;
    icon: typeof FileText;
    tone: string;
}> = [
    {
        value: 'xlsx',
        label: 'Excel',
        hint: 'Filtros, totales y hoja de información',
        icon: FileSpreadsheet,
        tone: 'bg-success-soft text-success-soft-foreground',
    },
    {
        value: 'pdf',
        label: 'PDF',
        hint: 'Encabezado institucional y paginación',
        icon: FileText,
        tone: 'bg-danger-soft text-danger-soft-foreground',
    },
    {
        value: 'csv',
        label: 'CSV',
        hint: 'UTF-8, protegido contra fórmulas',
        icon: FileType,
        tone: 'bg-info-soft text-info-soft-foreground',
    },
];

const form = useForm({
    report: props.report,
    format: 'xlsx' as Format,
    from: '',
    to: '',
    branch_id: null as string | null,
    department_id: null as string | null,
    position_id: null as string | null,
    region: null as string | null,
    period_unit: null as string | null,
    employee_id: null as string | null,
    status: null as string | null,
    observations: '',
});

const period = computed(() =>
    props.params.from && props.params.to
        ? `${formatDate(props.params.from, 'date')} – ${formatDate(props.params.to, 'date')}`
        : '',
);

function openWith(format: Format): void {
    form.clearErrors();
    form.format = format;
    open.value = true;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        from: props.params.from ?? '',
        to: props.params.to ?? '',
        branch_id: props.params.branch_id,
        department_id: props.params.department_id,
        position_id: props.params.position_id ?? null,
        region: props.params.region ?? null,
        period_unit: props.params.period_unit ?? null,
        employee_id: props.params.employee_id,
        status: props.params.status,
        observations: data.observations.trim() || null,
    })).submit(ReportController.exportMethod(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('observations');
        },
    });
}

defineExpose({ openWith });

const errorList = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key !== 'observations')
        .map(([, message]) => message),
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button
            v-for="f in FORMATS"
            :key="f.value"
            :variant="f.value === 'xlsx' ? 'default' : 'outline'"
            size="sm"
            @click="openWith(f.value)"
        >
            <component :is="f.icon" aria-hidden="true" />
            {{ f.label }}
        </Button>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Exportar reporte</DialogTitle>
                <DialogDescription
                    >{{ title }} · {{ period }}</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="submit">
                <div
                    class="grid grid-cols-3 gap-2"
                    role="radiogroup"
                    aria-label="Formato"
                >
                    <button
                        v-for="f in FORMATS"
                        :key="f.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.format === f.value"
                        :class="[
                            'flex flex-col items-center gap-2 rounded-xl border p-3 text-center transition-all focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none',
                            form.format === f.value
                                ? 'border-primary bg-primary-soft/40 shadow-elev-1 ring-1 ring-primary'
                                : 'hover:border-border-strong hover:bg-accent/40',
                        ]"
                        @click="form.format = f.value"
                    >
                        <span
                            :class="[
                                'flex size-9 items-center justify-center rounded-lg',
                                f.tone,
                            ]"
                        >
                            <component
                                :is="f.icon"
                                class="size-4.5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="text-sm font-semibold">{{ f.label }}</span>
                        <span
                            class="hidden text-[0.6875rem] leading-tight text-muted-foreground sm:block"
                            >{{ f.hint }}</span
                        >
                    </button>
                </div>

                <FormField
                    label="Observaciones"
                    hint="Opcional"
                    :error="form.errors.observations"
                    v-slot="{ id, describedBy }"
                >
                    <Textarea
                        :id="id"
                        v-model="form.observations"
                        :aria-describedby="describedBy"
                        maxlength="1000"
                        rows="3"
                        placeholder="Se imprimen en el encabezado del documento."
                    />
                </FormField>

                <div
                    class="flex items-start gap-2.5 rounded-xl bg-surface-2 p-3 text-xs text-muted-foreground"
                >
                    <Fingerprint
                        class="mt-0.5 size-4 shrink-0 text-brand-strong"
                        aria-hidden="true"
                    />
                    <p>
                        Logotipo, razón social, RFC, centro de trabajo, periodo,
                        fecha y usuario de generación, totales,
                        <strong class="font-semibold text-foreground"
                            >folio y huella SHA-256</strong
                        >. Queda registrado en la bitácora.
                    </p>
                </div>

                <ul
                    v-if="errorList.length"
                    class="space-y-1 text-sm text-danger-soft-foreground"
                >
                    <li v-for="(message, i) in errorList" :key="i">
                        {{ message }}
                    </li>
                </ul>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        <Download v-else aria-hidden="true" />
                        Generar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
