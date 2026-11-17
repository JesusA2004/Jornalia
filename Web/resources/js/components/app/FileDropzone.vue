<script setup lang="ts">
import { FileText, ImageIcon, UploadCloud, X } from '@lucide/vue';
import { ref, useId } from 'vue';
import type { HTMLAttributes } from 'vue';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** Tipos aceptados (atributo `accept`). */
        accept?: string;
        multiple?: boolean;
        /** Tamaño máximo por archivo en MB. */
        maxSizeMb?: number;
        maxFiles?: number;
        /** Nombre del campo (para <Form>; usa `name[]` si es múltiple). */
        name?: string;
        disabled?: boolean;
        invalid?: boolean;
        title?: string;
        description?: string;
        id?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        accept: 'image/jpeg,image/png,image/webp,application/pdf',
        multiple: true,
        maxSizeMb: 10,
        maxFiles: 5,
        disabled: false,
        invalid: false,
        title: 'Arrastra tus evidencias aquí',
        description: undefined,
    },
);

const files = defineModel<File[]>({ default: () => [] });
const emit = defineEmits<{
    rejected: [items: Array<{ file: File; reason: string }>];
}>();

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);
const inputId = props.id ?? `dropzone-${useId()}`;
const rejectedMessages = ref<string[]>([]);

function accepts(file: File): boolean {
    if (!props.accept) {
        return true;
    }

    return props.accept.split(',').some((rule) => {
        const pattern = rule.trim().toLowerCase();

        if (pattern.startsWith('.')) {
            return file.name.toLowerCase().endsWith(pattern);
        }

        if (pattern.endsWith('/*')) {
            return file.type.startsWith(pattern.slice(0, -1));
        }

        return file.type === pattern;
    });
}

function addFiles(list: FileList | File[] | null): void {
    if (!list || props.disabled) {
        return;
    }

    const rejected: Array<{ file: File; reason: string }> = [];
    const next = props.multiple ? [...files.value] : [];

    for (const file of Array.from(list)) {
        if (!accepts(file)) {
            rejected.push({ file, reason: 'Tipo de archivo no permitido' });
        } else if (file.size > props.maxSizeMb * 1024 * 1024) {
            rejected.push({
                file,
                reason: `Supera ${props.maxSizeMb} MB`,
            });
        } else if (next.length >= (props.multiple ? props.maxFiles : 1)) {
            rejected.push({ file, reason: 'Límite de archivos alcanzado' });
        } else {
            next.push(file);
        }
    }

    files.value = next;
    rejectedMessages.value = rejected.map((r) => `${r.file.name}: ${r.reason}`);

    if (rejected.length > 0) {
        emit('rejected', rejected);
    }

    syncInput();
}

/** Mantiene el <input type=file> sincronizado para envíos con <Form>. */
function syncInput(): void {
    if (!input.value || typeof DataTransfer === 'undefined') {
        return;
    }

    const transfer = new DataTransfer();

    files.value.forEach((file) => transfer.items.add(file));
    input.value.files = transfer.files;
}

function remove(index: number): void {
    files.value = files.value.filter((_, i) => i !== index);
    syncInput();
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? null);
}

function onChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    const picked = target.files ? Array.from(target.files) : [];

    addFiles(picked);
}

const hint =
    props.description ??
    `JPG, PNG, WEBP o PDF · hasta ${props.maxSizeMb} MB${props.multiple ? ` · máx. ${props.maxFiles} archivos` : ''}`;
</script>

<template>
    <div :class="cn('grid gap-3', props.class)">
        <label
            :for="inputId"
            :aria-invalid="invalid || undefined"
            :class="
                cn(
                    'group/drop relative flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border-strong bg-surface-2/50 px-6 py-8 text-center transition-standard focus-within:ring-[3px] focus-within:ring-ring/40 hover:border-primary/50 hover:bg-primary-soft/40',
                    dragging && 'border-primary bg-primary-soft/60',
                    invalid && 'border-danger/60',
                    disabled && 'pointer-events-none opacity-50',
                )
            "
            @dragenter.prevent="dragging = true"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <span
                class="flex size-11 items-center justify-center rounded-xl bg-card text-brand-strong shadow-elev-1 ring-1 ring-border transition-standard group-hover/drop:-translate-y-0.5"
            >
                <UploadCloud class="icon-lg" aria-hidden="true" />
            </span>
            <span class="space-y-1">
                <span class="block text-sm font-medium text-foreground">
                    {{ title }}
                </span>
                <span class="block text-xs text-muted-foreground">
                    o
                    <span
                        class="font-medium text-brand-strong underline-offset-4 group-hover/drop:underline"
                        >{{ multiple ? 'selecciónalos' : 'selecciónalo' }} desde
                        tu equipo</span
                    >
                </span>
                <span class="block pt-1 text-xs text-subtle-foreground">{{
                    hint
                }}</span>
            </span>
            <input
                :id="inputId"
                ref="input"
                type="file"
                class="sr-only"
                :name="name"
                :accept="accept"
                :multiple="multiple"
                :disabled="disabled"
                @change="onChange"
            />
        </label>

        <ul v-if="rejectedMessages.length > 0" class="grid gap-1" role="alert">
            <li
                v-for="message in rejectedMessages"
                :key="message"
                class="text-xs font-medium text-danger-soft-foreground"
            >
                {{ message }}
            </li>
        </ul>

        <ul v-if="files.length > 0" class="grid gap-2">
            <li
                v-for="(file, index) in files"
                :key="`${file.name}-${file.size}-${index}`"
                class="flex items-center gap-3 rounded-lg border bg-card px-3 py-2.5 shadow-elev-1"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-surface-3 text-muted-foreground"
                >
                    <ImageIcon
                        v-if="file.type.startsWith('image/')"
                        class="icon-sm"
                        aria-hidden="true"
                    />
                    <FileText v-else class="icon-sm" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{
                        file.name
                    }}</span>
                    <span class="block numeric text-xs text-muted-foreground">{{
                        formatBytes(file.size)
                    }}</span>
                </span>
                <button
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-standard hover:bg-danger-soft hover:text-danger-soft-foreground"
                    :aria-label="`Quitar ${file.name}`"
                    @click="remove(index)"
                >
                    <X class="icon-sm" />
                </button>
            </li>
        </ul>
    </div>
</template>
