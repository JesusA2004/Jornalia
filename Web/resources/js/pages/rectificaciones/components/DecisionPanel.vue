<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Gavel, ShieldAlert, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

/**
 * Panel de resolución (aprobar / rechazar). El comentario es obligatorio al
 * rechazar; el servidor vuelve a validarlo y aplica el flujo multinivel.
 */
const props = withDefaults(
    defineProps<{
        /** URL de la acción `decide` (Wayfinder). */
        action: string;
        /** Nivel actual que resuelve (etiqueta). */
        levelLabel?: string | null;
        /** Muestra la opción "excluir marcaje del cálculo" (rectificaciones). */
        allowVoid?: boolean;
        title?: string;
        /** Etapa que se resuelve (rectificaciones): si cambió, el servidor rechaza la decisión. */
        stage?: string | null;
        approveLabel?: string;
    }>(),
    {
        levelLabel: null,
        allowVoid: false,
        title: 'Resolver',
        stage: null,
        approveLabel: 'Aprobar',
    },
);

const mode = ref<'approve' | 'reject'>('approve');
const localError = ref<string | null>(null);

const form = useForm({
    decision: 'approve' as 'approve' | 'reject',
    comment: '',
    void_target: false,
});

/** Errores de dominio (etapa, firma, evidencia) que no son de un campo del panel. */
const domainError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.decision ??
        errors.stage ??
        errors.signature ??
        errors.evidence ??
        null
    );
});

const commentError = computed(
    () => localError.value ?? form.errors.comment ?? null,
);

function submit(): void {
    localError.value = null;
    if (mode.value === 'reject' && form.comment.trim().length === 0) {
        localError.value = 'Indica el motivo del rechazo.';

        return;
    }
    form.decision = mode.value;
    form.transform((data) => ({
        ...data,
        ...(props.stage ? { stage: props.stage } : {}),
        void_target:
            props.allowVoid && mode.value === 'approve'
                ? data.void_target
                : false,
    })).post(props.action, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <SectionCard
        :title="title"
        :icon="Gavel"
        divided
        class="ring-1 ring-brand/20"
    >
        <template #actions>
            <span
                v-if="levelLabel"
                class="rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-soft-foreground"
            >
                {{ levelLabel }}
            </span>
        </template>
        <form class="grid gap-4" @submit.prevent="submit">
            <div
                class="grid grid-cols-2 gap-2"
                role="radiogroup"
                aria-label="Decisión"
            >
                <button
                    type="button"
                    role="radio"
                    :aria-checked="mode === 'approve'"
                    :class="
                        cn(
                            'flex items-center justify-center gap-2 rounded-xl border p-3 text-sm font-semibold transition-all',
                            mode === 'approve'
                                ? 'border-success/40 bg-success-soft text-success-soft-foreground ring-2 ring-success/30'
                                : 'text-muted-foreground hover:bg-surface-2',
                        )
                    "
                    @click="mode = 'approve'"
                >
                    <Check class="size-4" aria-hidden="true" />
                    {{ approveLabel }}
                </button>
                <button
                    type="button"
                    role="radio"
                    :aria-checked="mode === 'reject'"
                    :class="
                        cn(
                            'flex items-center justify-center gap-2 rounded-xl border p-3 text-sm font-semibold transition-all',
                            mode === 'reject'
                                ? 'border-danger/40 bg-danger-soft text-danger-soft-foreground ring-2 ring-danger/30'
                                : 'text-muted-foreground hover:bg-surface-2',
                        )
                    "
                    @click="mode = 'reject'"
                >
                    <X class="size-4" aria-hidden="true" /> Rechazar
                </button>
            </div>

            <label
                v-if="allowVoid && mode === 'approve'"
                class="flex cursor-pointer items-start gap-3 rounded-xl border bg-surface-2 p-3"
            >
                <Switch v-model="form.void_target" class="mt-0.5" />
                <span class="grid gap-0.5">
                    <span class="flex items-center gap-1.5 text-sm font-medium">
                        <ShieldAlert
                            class="size-4 text-warning"
                            aria-hidden="true"
                        />
                        Excluir el marcaje original del cálculo
                    </span>
                    <span class="text-xs text-muted-foreground"
                        >El original se conserva intacto; sólo deja de
                        contar.</span
                    >
                </span>
            </label>

            <FormField
                :label="mode === 'reject' ? 'Motivo del rechazo' : 'Comentario'"
                :required="mode === 'reject'"
                :hint="mode === 'approve' ? 'Opcional' : undefined"
                :error="commentError"
                v-slot="{ id, describedBy }"
            >
                <Textarea
                    :id="id"
                    v-model="form.comment"
                    rows="3"
                    maxlength="1000"
                    :aria-describedby="describedBy"
                    :placeholder="
                        mode === 'reject'
                            ? 'Explica por qué se rechaza…'
                            : 'Agrega una nota para el historial…'
                    "
                />
            </FormField>
            <p
                v-if="domainError"
                role="alert"
                class="text-sm text-danger-soft-foreground"
            >
                {{ domainError }}
            </p>

            <Button
                type="submit"
                :variant="mode === 'reject' ? 'destructive' : 'default'"
                :disabled="form.processing"
                class="w-full"
                size="lg"
            >
                <Spinner v-if="form.processing" />
                <component
                    :is="mode === 'reject' ? X : Check"
                    v-else
                    aria-hidden="true"
                />
                {{
                    mode === 'reject'
                        ? 'Confirmar rechazo'
                        : `Confirmar: ${approveLabel.toLowerCase()}`
                }}
            </Button>
        </form>
    </SectionCard>
</template>
