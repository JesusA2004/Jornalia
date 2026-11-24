<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileSignature, Paperclip, Smartphone, Upload } from '@lucide/vue';
import { ref } from 'vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import type { EmployeeRegistration } from '../types';

/**
 * Alta de registro electrónico: app vinculada + convenio de registro firmado
 * (en la app o en papel adjunto al expediente).
 */
const props = defineProps<{
    employeeId: number;
    registration: EmployeeRegistration;
    canUpload: boolean;
}>();

const input = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null }>({ file: null });

function pick(): void {
    input.value?.click();
}

function upload(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) {
        return;
    }

    form.file = file;
    form.post(`/colaboradores/${props.employeeId}/convenio`, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            form.reset();
            if (input.value) {
                input.value.value = '';
            }
        },
    });
}
</script>

<template>
    <SectionCard title="Registro electrónico" :icon="FileSignature" divided>
        <template v-if="canUpload" #actions>
            <Button
                variant="ghost"
                size="sm"
                :disabled="form.processing"
                @click="pick"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <Upload v-else aria-hidden="true" />
                Adjuntar convenio
            </Button>
            <input
                ref="input"
                type="file"
                class="sr-only"
                accept="application/pdf,image/jpeg,image/png"
                aria-label="Convenio firmado"
                @change="upload"
            />
        </template>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3">
                <span class="flex items-center gap-2 text-sm font-medium">
                    <Smartphone class="icon-sm text-muted-foreground" aria-hidden="true" />
                    App
                </span>
                <StatusBadge
                    :label="registration.app ? 'Vinculada' : 'Pendiente'"
                    :tone="registration.app ? 'success' : 'danger'"
                    size="sm"
                />
            </div>
            <div class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3">
                <span class="flex items-center gap-2 text-sm font-medium">
                    <FileSignature class="icon-sm text-muted-foreground" aria-hidden="true" />
                    Convenio
                </span>
                <StatusBadge
                    :label="registration.agreement ? 'Firmado' : 'Pendiente'"
                    :tone="registration.agreement ? 'success' : 'danger'"
                    size="sm"
                />
            </div>
        </div>

        <p v-if="form.errors.file" class="mt-2 text-sm text-destructive">
            {{ form.errors.file }}
        </p>

        <ul v-if="registration.papers.length" class="mt-3 divide-y text-sm">
            <li
                v-for="p in registration.papers"
                :key="p.id"
                class="flex items-center justify-between gap-3 py-2"
            >
                <a
                    :href="`/colaboradores/${employeeId}/convenio/${p.id}`"
                    class="flex min-w-0 items-center gap-2 hover:underline"
                >
                    <Paperclip class="icon-sm shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="truncate">{{ p.name }}</span>
                </a>
                <span class="shrink-0 text-xs text-muted-foreground">
                    {{ p.uploaded_at ? formatDate(p.uploaded_at, 'date-short') : '' }}
                </span>
            </li>
        </ul>
    </SectionCard>
</template>
