<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Coffee,
    Fingerprint,
    LogIn,
    LogOut,
    MapPin,
    ShieldAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { punch } from '@/routes/asistencias';
import type { PunchType, WebPunchState } from '@/types/operacion';

/**
 * Marcaje web propio. La política de asistencia (backend) decide si está
 * permitido y valida la secuencia; aquí sólo se envía la intención.
 */
const props = defineProps<{ state: WebPunchState }>();

const form = useForm<{
    type: PunchType | '';
    client_uuid: string;
    latitude: number | null;
    longitude: number | null;
    accuracy_meters: number | null;
}>({
    type: '',
    client_uuid: '',
    latitude: null,
    longitude: null,
    accuracy_meters: null,
});

const locating = ref(false);
const located = ref<boolean | null>(null);
const error = computed(
    () => (form.errors as Record<string, string | undefined>).punch ?? null,
);

const ICONS: Record<PunchType, typeof LogIn> = {
    clock_in: LogIn,
    clock_out: LogOut,
    break_start: Coffee,
    break_end: Coffee,
};

function position(): Promise<GeolocationPosition | null> {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve(pos),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 6000, maximumAge: 30000 },
        );
    });
}

async function submit(type: PunchType): Promise<void> {
    locating.value = true;
    const pos = await position();
    locating.value = false;
    located.value = pos !== null;

    form.type = type;
    form.client_uuid = crypto.randomUUID();
    form.latitude = pos ? Number(pos.coords.latitude.toFixed(7)) : null;
    form.longitude = pos ? Number(pos.coords.longitude.toFixed(7)) : null;
    form.accuracy_meters = pos ? Math.round(pos.coords.accuracy) : null;

    form.post(punch.url(), { preserveScroll: true });
}

const busy = computed(() => locating.value || form.processing);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="props.state.allowed" class="flex flex-wrap gap-2">
            <Button
                size="lg"
                class="h-12 flex-1 gap-2 rounded-xl text-base font-semibold shadow-elev-1 sm:flex-none sm:px-6"
                :disabled="busy"
                @click="submit(props.state.next_type)"
            >
                <Spinner v-if="busy && form.type === props.state.next_type" />
                <component
                    :is="ICONS[props.state.next_type]"
                    v-else
                    class="icon-sm"
                    aria-hidden="true"
                />
                Registrar {{ props.state.next_label.toLowerCase() }}
            </Button>
            <Button
                v-if="props.state.can_break"
                size="lg"
                variant="outline"
                class="h-12 gap-2 rounded-xl"
                :disabled="busy"
                @click="submit('break_start')"
            >
                <Spinner v-if="busy && form.type === 'break_start'" />
                <Coffee v-else class="icon-sm" aria-hidden="true" />
                Iniciar descanso
            </Button>
        </div>
        <div
            v-else
            class="flex items-center gap-3 rounded-xl border border-dashed bg-surface-2 px-4 py-3 text-sm text-muted-foreground"
        >
            <Fingerprint class="icon-sm shrink-0" aria-hidden="true" />
            Marca desde la app móvil. Tu política no habilita el marcaje web.
        </div>

        <p
            v-if="props.state.allowed"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <MapPin class="size-3.5" aria-hidden="true" />
            <template v-if="locating">Obteniendo ubicación…</template>
            <template v-else-if="located === false"
                >Sin ubicación del navegador; se registra sin
                coordenadas.</template
            >
            <template v-else
                >Se adjunta tu ubicación si el navegador lo permite ·
                {{ props.state.policy }}</template
            >
        </p>

        <Alert v-if="error" variant="destructive">
            <ShieldAlert aria-hidden="true" />
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
    </div>
</template>
