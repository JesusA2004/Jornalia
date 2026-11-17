<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    FileClock,
    GitBranch,
    Plus,
    Sunrise,
    TimerReset,
    TriangleAlert,
    X,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import ApprovalFlowController from '@/actions/App/Http/Controllers/Web/Admin/ApprovalFlowController';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import NumberInput from './components/NumberInput.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import type {
    ApprovalFlowRow,
    ApprovalLevel,
    ManageCan,
    Option,
    OvertimePolicy,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Autorizaciones' },
        ],
    },
});

const props = defineProps<{
    flows: ApprovalFlowRow[];
    overtime: OvertimePolicy;
    permissions: Option[];
    can: ManageCan;
}>();

const ICONS: Record<ApprovalFlowRow['subject'], LucideIcon> = {
    rectification: FileClock,
    incidence: TriangleAlert,
    vacation: Sunrise,
    overtime: TimerReset,
};

type LevelsForm = {
    levels: Array<Pick<ApprovalLevel, 'permission' | 'label'>>;
};

const forms = Object.fromEntries(
    props.flows.map((flow) => [
        flow.subject,
        useForm<LevelsForm>({
            levels: flow.levels.map((l) => ({
                permission: l.permission,
                label: l.label,
            })),
        }),
    ]),
) as Record<ApprovalFlowRow['subject'], ReturnType<typeof useForm<LevelsForm>>>;

function addLevel(flow: ApprovalFlowRow): void {
    forms[flow.subject].levels.push({
        permission: flow.default_permission,
        label: `Nivel ${forms[flow.subject].levels.length + 1}`,
    });
}

function removeLevel(flow: ApprovalFlowRow, index: number): void {
    forms[flow.subject].levels.splice(index, 1);
}

function move(flow: ApprovalFlowRow, index: number, delta: number): void {
    const levels = forms[flow.subject].levels;
    const target = index + delta;

    if (target < 0 || target >= levels.length) {
        return;
    }

    [levels[index], levels[target]] = [levels[target], levels[index]];
}

function save(flow: ApprovalFlowRow): void {
    forms[flow.subject].put(ApprovalFlowController.update.url(flow.subject), {
        preserveScroll: true,
    });
}

function levelError(
    flow: ApprovalFlowRow,
    index: number,
    field: 'permission' | 'label',
): string | undefined {
    return (forms[flow.subject].errors as Record<string, string | undefined>)[
        `levels.${index}.${field}`
    ];
}

const overtimeForm = useForm<{
    requires_prior_authorization: boolean;
    min_minutes: number | null;
    allow_employee_request: boolean;
}>({ ...props.overtime });

function saveOvertime(): void {
    overtimeForm.put(ApprovalFlowController.updateOvertime.url(), {
        preserveScroll: true,
    });
}

const help = [
    {
        title: 'Niveles',
        text: 'Cada solicitud pasa por los niveles en orden; el último decide.',
        icon: GitBranch,
    },
    {
        title: 'Permiso por nivel',
        text: 'Sólo quien tenga ese permiso y alcance sobre el colaborador puede decidir.',
        icon: Check,
    },
    {
        title: 'Horas extra',
        text: 'Define si requieren autorización previa y el mínimo a considerar.',
        icon: TimerReset,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Quién autoriza cada tipo de solicitud."
        :icon="GitBranch"
        :help="help"
        help-title="Flujos de autorización"
    />

    <SettingsNav />

    <div class="grid gap-6 xl:grid-cols-2">
        <SectionCard
            v-for="flow in flows"
            :key="flow.subject"
            :title="flow.label"
            :description="flow.description"
            :icon="ICONS[flow.subject]"
            divided
        >
            <template #actions>
                <StatusBadge
                    :status="flow.configured ? 'active' : 'draft'"
                    :label="
                        flow.configured ? 'Personalizado' : 'Predeterminado'
                    "
                    :tone="flow.configured ? 'brand' : 'neutral'"
                    size="sm"
                />
            </template>

            <ol class="relative grid gap-3">
                <li
                    v-for="(level, index) in forms[flow.subject].levels"
                    :key="index"
                    class="relative grid grid-cols-[2rem_minmax(0,1fr)] gap-3"
                >
                    <div class="flex flex-col items-center">
                        <span
                            class="flex size-8 items-center justify-center rounded-full bg-brand-gradient text-sm font-bold text-black shadow-elev-1"
                        >
                            {{ index + 1 }}
                        </span>
                        <span
                            v-if="index < forms[flow.subject].levels.length - 1"
                            class="mt-1 w-px flex-1 bg-border"
                        />
                    </div>
                    <div
                        class="grid gap-3 rounded-xl border bg-surface-2/50 p-3 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_auto] sm:items-start"
                    >
                        <FormField
                            :error="levelError(flow, index, 'label')"
                            v-slot="{ id }"
                        >
                            <Input
                                :id="id"
                                v-model="level.label"
                                placeholder="Etiqueta"
                                :disabled="!can.manage"
                                aria-label="Etiqueta del nivel"
                            />
                        </FormField>
                        <FormField
                            :error="levelError(flow, index, 'permission')"
                            v-slot="{ id }"
                        >
                            <SelectField
                                :id="id"
                                :model-value="level.permission"
                                :options="permissions"
                                :disabled="!can.manage"
                                placeholder="Permiso requerido"
                                @update:model-value="
                                    (v) => (level.permission = v ?? '')
                                "
                            />
                        </FormField>
                        <div v-if="can.manage" class="flex gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                :disabled="index === 0"
                                aria-label="Subir nivel"
                                @click="move(flow, index, -1)"
                            >
                                <ArrowUp class="icon-sm" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                :disabled="
                                    index ===
                                    forms[flow.subject].levels.length - 1
                                "
                                aria-label="Bajar nivel"
                                @click="move(flow, index, 1)"
                            >
                                <ArrowDown class="icon-sm" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                :disabled="
                                    forms[flow.subject].levels.length === 1
                                "
                                aria-label="Quitar nivel"
                                @click="removeLevel(flow, index)"
                            >
                                <X class="icon-sm" />
                            </Button>
                        </div>
                    </div>
                </li>
            </ol>
            <p
                v-if="forms[flow.subject].errors.levels"
                class="mt-2 text-xs text-danger-soft-foreground"
            >
                {{ forms[flow.subject].errors.levels }}
            </p>

            <div
                v-if="can.manage"
                class="mt-4 flex items-center justify-between gap-2 border-t pt-4"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="forms[flow.subject].levels.length >= 5"
                    @click="addLevel(flow)"
                >
                    <Plus class="icon-sm" />
                    Agregar nivel
                </Button>
                <Button
                    size="sm"
                    :disabled="
                        forms[flow.subject].processing ||
                        !forms[flow.subject].isDirty
                    "
                    @click="save(flow)"
                >
                    <Spinner v-if="forms[flow.subject].processing" />
                    <Check v-else class="icon-sm" />
                    Guardar
                </Button>
            </div>
        </SectionCard>

        <SectionCard
            title="Política de horas extra"
            :icon="TimerReset"
            divided
            class="xl:col-span-2"
        >
            <form
                class="grid gap-4 md:grid-cols-3"
                @submit.prevent="saveOvertime"
            >
                <ToggleRow
                    v-model="overtimeForm.requires_prior_authorization"
                    label="Autorización previa"
                    description="El tiempo extra sin autorizar queda como pendiente."
                    :disabled="!can.manage"
                />
                <ToggleRow
                    v-model="overtimeForm.allow_employee_request"
                    label="Solicitud del colaborador"
                    description="Puede pedir autorización desde la app."
                    :disabled="!can.manage"
                />
                <FormField
                    label="Mínimo a considerar"
                    :error="overtimeForm.errors.min_minutes"
                    v-slot="{ id }"
                >
                    <NumberInput
                        :id="id"
                        v-model="overtimeForm.min_minutes"
                        :min="0"
                        :max="120"
                        :step="5"
                        suffix="min"
                        :disabled="!can.manage"
                    />
                </FormField>
                <div v-if="can.manage" class="flex justify-end md:col-span-3">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="
                            overtimeForm.processing || !overtimeForm.isDirty
                        "
                    >
                        <Spinner v-if="overtimeForm.processing" />
                        <Check v-else class="icon-sm" />
                        Guardar política
                    </Button>
                </div>
            </form>
        </SectionCard>
    </div>
</template>
