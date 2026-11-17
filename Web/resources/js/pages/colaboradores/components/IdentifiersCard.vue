<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Eye, EyeOff, IdCard, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import SectionCard from '@/components/app/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Identifiers } from '../types';

/**
 * Identificadores oficiales cifrados. Se revelan bajo demanda (queda en bitácora).
 */
const props = defineProps<{
    employeeId: number;
    has: { curp: boolean; rfc: boolean; nss: boolean };
    canReveal: boolean;
}>();

const http = useHttp<Record<string, never>, Identifiers>({});
const revealed = ref<Identifiers | null>(null);
const failed = ref(false);

const items = [
    { key: 'curp', label: 'CURP' },
    { key: 'rfc', label: 'RFC' },
    { key: 'nss', label: 'NSS' },
] as const;

async function reveal(): Promise<void> {
    failed.value = false;

    try {
        revealed.value = await http.post(
            EmployeeController.revealIdentifiers.url(props.employeeId),
        );
    } catch {
        failed.value = true;
    }
}
</script>

<template>
    <SectionCard title="Identificadores oficiales" :icon="IdCard" divided>
        <template v-if="canReveal" #actions>
            <Button
                v-if="!revealed"
                variant="outline"
                size="sm"
                :disabled="http.processing"
                @click="reveal"
            >
                <Spinner v-if="http.processing" />
                <Eye v-else aria-hidden="true" />
                Mostrar
            </Button>
            <Button v-else variant="ghost" size="sm" @click="revealed = null">
                <EyeOff aria-hidden="true" />
                Ocultar
            </Button>
        </template>

        <dl class="grid gap-3 sm:grid-cols-3">
            <div
                v-for="item in items"
                :key="item.key"
                class="rounded-xl border bg-surface-2 px-4 py-3"
            >
                <dt
                    class="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ item.label }}
                </dt>
                <dd class="mt-1 font-mono text-sm">
                    <template v-if="revealed">{{
                        revealed[item.key] ?? '—'
                    }}</template>
                    <template v-else-if="has[item.key]">
                        <span class="tracking-[0.3em] text-muted-foreground"
                            >••••••••</span
                        >
                    </template>
                    <span v-else class="text-subtle-foreground"
                        >Sin capturar</span
                    >
                </dd>
            </div>
        </dl>
        <p class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
            <ShieldCheck class="size-3.5" aria-hidden="true" />
            <template v-if="failed">No fue posible consultarlos.</template>
            <template v-else
                >Cifrados · cada consulta queda en bitácora.</template
            >
        </p>
    </SectionCard>
</template>
