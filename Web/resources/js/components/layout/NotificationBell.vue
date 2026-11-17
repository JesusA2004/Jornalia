<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, BellOff } from '@lucide/vue';
import { computed } from 'vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { NOTIFICATIONS_URL } from '@/lib/navigation';
import { TONE_DOT_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';

const page = usePage();

const unread = computed(() => Number(page.props.notifications_unread ?? 0));
const items = computed(() => page.props.notifications ?? []);
const badge = computed(() =>
    unread.value > 99 ? '99+' : String(unread.value),
);
const label = computed(() =>
    unread.value === 0
        ? 'Notificaciones'
        : `Notificaciones, ${unread.value} sin leer`,
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative size-9 rounded-lg text-muted-foreground hover:text-foreground"
                :aria-label="label"
            >
                <Bell class="icon-md" />
                <span
                    v-if="unread > 0"
                    class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 numeric text-[0.625rem] leading-none font-bold text-brand-foreground ring-2 ring-background"
                    >{{ badge }}</span
                >
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[22rem] p-0">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <p class="text-sm font-semibold">Notificaciones</p>
                <span
                    v-if="unread > 0"
                    class="rounded-full bg-brand-soft px-2 py-0.5 numeric text-xs font-medium text-brand-soft-foreground"
                    >{{ unread }} sin leer</span
                >
            </div>

            <ScrollArea v-if="items.length > 0" class="max-h-80">
                <ul class="divide-y">
                    <li v-for="item in items" :key="item.id">
                        <component
                            :is="item.url ? Link : 'div'"
                            :href="item.url ?? undefined"
                            class="flex gap-3 px-4 py-3 transition-standard hover:bg-surface-2"
                        >
                            <span
                                :class="
                                    cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        item.read_at
                                            ? 'bg-transparent'
                                            : TONE_DOT_CLASSES[
                                                  item.tone ?? 'brand'
                                              ],
                                    )
                                "
                                aria-hidden="true"
                            />
                            <span class="grid min-w-0 gap-0.5">
                                <span
                                    class="text-sm font-medium text-foreground"
                                    >{{ item.title }}</span
                                >
                                <span
                                    v-if="item.body"
                                    class="line-clamp-2 text-xs text-muted-foreground"
                                    >{{ item.body }}</span
                                >
                                <DateTimeDisplay
                                    :value="item.created_at"
                                    format="relative"
                                    :tooltip="false"
                                    class="text-xs text-subtle-foreground"
                                />
                            </span>
                        </component>
                    </li>
                </ul>
            </ScrollArea>

            <div
                v-else
                class="flex flex-col items-center gap-2 px-6 py-10 text-center"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-full bg-surface-3 text-muted-foreground"
                >
                    <BellOff class="icon-md" aria-hidden="true" />
                </span>
                <p class="text-sm font-medium">
                    {{
                        unread > 0
                            ? `Tienes ${unread} sin leer`
                            : 'Estás al día'
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        unread > 0
                            ? 'Abre la bandeja para revisarlas.'
                            : 'No hay notificaciones pendientes.'
                    }}
                </p>
            </div>

            <div class="border-t p-2">
                <Button variant="ghost" size="sm" class="w-full" as-child>
                    <Link :href="NOTIFICATIONS_URL">Ver todas</Link>
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
