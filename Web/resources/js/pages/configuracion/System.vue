<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Archive,
    BellRing,
    Cable,
    FileClock,
    MapPinned,
    ServerCog,
    Smartphone,
} from '@lucide/vue';
import PlatformSettingsController from '@/actions/App/Http/Controllers/Web/Admin/PlatformSettingsController';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate } from '@/lib/format';
import SettingsNav from './components/SettingsNav.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Sistema' },
        ],
    },
});

defineProps<{
    retention: {
        attendance_years: number;
        attendance_basis: string;
        location: {
            retention_days: number;
            contingency_minutes: number;
            retention_confirmed: boolean;
        };
    };
    rectifications: {
        business_days: number;
        confirmed_by_hr: boolean;
        closed_through: string | null;
    };
    notifications: {
        push_configured: boolean;
        devices_with_token: number;
        invalid_tokens: number;
        reminders: {
            late: { enabled: boolean; grace_minutes: number };
            clock_out: { enabled: boolean; minutes_after: number };
        };
        punch_receipt_mandatory: boolean;
    };
    integrations: { people_configured: boolean; fcm_configured: boolean };
    can: { manage: boolean; integrations: boolean };
}>();

const locationUrl = PlatformSettingsController.location.url();

const help = [
    {
        title: 'Conservación',
        text: 'Registros de asistencia: durante la relación laboral y al menos un año después (art. 804 LFT) o el plazo mayor que fije Jurídico. Nada se borra.',
        icon: Archive,
    },
    {
        title: 'Acuse de marcaje',
        text: 'Cada entrada y salida deja un acuse en la bandeja de la app. Es obligatorio y no se desactiva.',
        icon: BellRing,
    },
    {
        title: 'Push',
        text: 'Sin credenciales de Firebase los avisos llegan sólo a la bandeja; no se simulan envíos.',
        icon: Smartphone,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Conservación, notificaciones e integraciones."
        :icon="ServerCog"
        :help="help"
        help-title="Sistema"
    />

    <SettingsNav />

    <div class="grid gap-6 lg:grid-cols-2">
        <SectionCard title="Conservación" :icon="Archive" divided>
            <dl class="divide-y text-sm">
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Registros de asistencia
                    </dt>
                    <dd class="font-medium">
                        {{ retention.attendance_years }} años
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Recorridos de ubicación
                    </dt>
                    <dd class="flex items-center gap-2 font-medium">
                        {{ retention.location.retention_days }} días
                        <StatusBadge
                            :status="
                                retention.location.retention_confirmed
                                    ? 'active'
                                    : 'pending'
                            "
                            :label="
                                retention.location.retention_confirmed
                                    ? 'Validado'
                                    : 'Pendiente Jurídico'
                            "
                            :tone="
                                retention.location.retention_confirmed
                                    ? 'success'
                                    : 'warning'
                            "
                            size="sm"
                        />
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Plazo de firma de rectificaciones
                    </dt>
                    <dd class="flex items-center gap-2 font-medium">
                        {{ rectifications.business_days }} días hábiles
                        <StatusBadge
                            v-if="!rectifications.confirmed_by_hr"
                            status="pending"
                            label="Por confirmar"
                            tone="warning"
                            size="sm"
                        />
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">Nómina cerrada hasta</dt>
                    <dd class="font-medium">
                        {{
                            rectifications.closed_through
                                ? formatDate(rectifications.closed_through)
                                : '—'
                        }}
                    </dd>
                </div>
            </dl>
            <p
                class="mt-3 flex items-start gap-2 text-xs text-muted-foreground"
            >
                <FileClock class="mt-0.5 icon-xs shrink-0" aria-hidden="true" />
                {{ retention.attendance_basis }}
            </p>
            <Link
                :href="locationUrl"
                class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
            >
                <MapPinned class="icon-xs" aria-hidden="true" /> Reglas de
                ubicación
            </Link>
        </SectionCard>

        <SectionCard title="Notificaciones" :icon="BellRing" divided>
            <dl class="divide-y text-sm">
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">Push Android e iOS</dt>
                    <dd>
                        <StatusBadge
                            :status="
                                notifications.push_configured
                                    ? 'active'
                                    : 'inactive'
                            "
                            :label="
                                notifications.push_configured
                                    ? 'Configurado'
                                    : 'Sin configurar · sólo bandeja'
                            "
                            :tone="
                                notifications.push_configured
                                    ? 'success'
                                    : 'warning'
                            "
                            size="sm"
                        />
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Acuse de entrada y salida
                    </dt>
                    <dd>
                        <StatusBadge
                            status="active"
                            label="Obligatorio"
                            tone="success"
                            size="sm"
                        />
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Recordatorio de entrada
                    </dt>
                    <dd class="font-medium">
                        {{
                            notifications.reminders.late.enabled
                                ? `${notifications.reminders.late.grace_minutes} min de gracia`
                                : 'Apagado'
                        }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Recordatorio de salida
                    </dt>
                    <dd class="font-medium">
                        {{
                            notifications.reminders.clock_out.enabled
                                ? `${notifications.reminders.clock_out.minutes_after} min después`
                                : 'Apagado'
                        }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">Teléfonos con push</dt>
                    <dd class="font-medium tabular-nums">
                        {{ notifications.devices_with_token }}
                        <span
                            v-if="notifications.invalid_tokens > 0"
                            class="text-xs text-muted-foreground"
                        >
                            · {{ notifications.invalid_tokens }} token(s)
                            inválido(s)
                        </span>
                    </dd>
                </div>
            </dl>
            <Link
                href="/comunicados"
                class="mt-3 inline-flex text-xs font-medium text-primary hover:underline"
                >Comunicados</Link
            >
        </SectionCard>

        <SectionCard title="Integraciones" :icon="Cable" divided>
            <dl class="divide-y text-sm">
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">Mr. Lana People</dt>
                    <dd>
                        <StatusBadge
                            :status="
                                integrations.people_configured
                                    ? 'active'
                                    : 'inactive'
                            "
                            :label="
                                integrations.people_configured
                                    ? 'Conectado'
                                    : 'Sin configurar'
                            "
                            :tone="
                                integrations.people_configured
                                    ? 'success'
                                    : 'warning'
                            "
                            size="sm"
                        />
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-muted-foreground">
                        Firebase Cloud Messaging
                    </dt>
                    <dd>
                        <StatusBadge
                            :status="
                                integrations.fcm_configured
                                    ? 'active'
                                    : 'inactive'
                            "
                            :label="
                                integrations.fcm_configured
                                    ? 'Conectado'
                                    : 'Sin configurar'
                            "
                            :tone="
                                integrations.fcm_configured
                                    ? 'success'
                                    : 'warning'
                            "
                            size="sm"
                        />
                    </dd>
                </div>
            </dl>
            <Link
                v-if="can.integrations"
                href="/integraciones"
                class="mt-3 inline-flex text-xs font-medium text-primary hover:underline"
                >Ver integraciones</Link
            >
        </SectionCard>
    </div>
</template>
