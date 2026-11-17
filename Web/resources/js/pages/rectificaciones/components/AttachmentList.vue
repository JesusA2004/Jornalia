<script setup lang="ts">
import { Download, FileImage, FileText, Paperclip } from '@lucide/vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatBytes } from '@/lib/format';
import type { AttachmentRow } from '@/types/operacion';
import { shortHash } from './shared';

/** Evidencias con descarga protegida (la autorización ocurre en el servidor). */
defineProps<{ attachments: AttachmentRow[]; timezone?: string }>();
</script>

<template>
    <EmptyState
        v-if="attachments.length === 0"
        :icon="Paperclip"
        size="sm"
        title="Sin evidencias"
        description="No se adjuntaron archivos."
    />
    <ul v-else class="grid gap-2">
        <li
            v-for="file in attachments"
            :key="file.id"
            class="group flex items-center gap-3 rounded-xl border bg-surface-1 p-3 transition-colors hover:bg-surface-2"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
            >
                <component
                    :is="file.mime.startsWith('image/') ? FileImage : FileText"
                    class="size-5"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ file.name }}</p>
                <p
                    class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                >
                    <span>{{ formatBytes(file.size) }}</span>
                    <span aria-hidden="true">·</span>
                    <DateTimeDisplay
                        :value="file.created_at"
                        :timezone="timezone"
                        format="date"
                    />
                    <template v-if="file.uploaded_by">
                        <span aria-hidden="true">·</span>
                        <span>{{ file.uploaded_by }}</span>
                    </template>
                </p>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <p
                                class="mt-0.5 w-fit cursor-help font-mono text-[0.6875rem] text-subtle-foreground"
                            >
                                SHA-256 {{ shortHash(file.sha256, 10) }}
                            </p>
                        </TooltipTrigger>
                        <TooltipContent
                            class="max-w-xs font-mono text-[0.6875rem] break-all"
                        >
                            {{ file.sha256 }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <Button
                as="a"
                :href="file.url"
                variant="outline"
                size="sm"
                class="shrink-0"
            >
                <Download aria-hidden="true" />
                <span class="hidden sm:inline">Descargar</span>
            </Button>
        </li>
    </ul>
</template>
