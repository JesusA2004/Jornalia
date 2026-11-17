<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    ImageUp,
    Landmark,
    MapPin,
    Palette,
    ShieldCheck,
    Store,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CompanySettingsController from '@/actions/App/Http/Controllers/Web/Admin/CompanySettingsController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import FileDropzone from '@/components/app/FileDropzone.vue';
import FormField from '@/components/app/FormField.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import SettingsNav from './components/SettingsNav.vue';
import type {
    CompanyOrganization,
    CompanySummary,
    ManageCan,
    Option,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
        ],
    },
});

const props = defineProps<{
    organization: CompanyOrganization;
    summary: CompanySummary;
    timezones: Option[];
    can: ManageCan;
}>();

const form = useForm({
    name: props.organization.name,
    timezone: props.organization.timezone as string | null,
    privacy_contact_email: props.organization.privacy_contact_email ?? '',
    brand_primary_color: props.organization.brand_primary_color ?? '',
});

const PRESETS = [
    '#C8853F',
    '#1F6FEB',
    '#0F766E',
    '#7C3AED',
    '#DC2626',
    '#111827',
];

const colorValid = computed(
    () =>
        !form.brand_primary_color ||
        /^#[0-9A-Fa-f]{6}$/.test(form.brand_primary_color),
);

function save(): void {
    form.transform((data) => ({
        ...data,
        privacy_contact_email: data.privacy_contact_email || null,
        brand_primary_color: data.brand_primary_color || null,
    })).put(CompanySettingsController.update.url(), { preserveScroll: true });
}

const files = ref<File[]>([]);
const preview = ref<string | null>(null);
const logoForm = useForm<{ logo: File | null }>({ logo: null });

watch(files, (list) => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    const file = list[0] ?? null;
    preview.value = file ? URL.createObjectURL(file) : null;
    logoForm.logo = file;
});

function uploadLogo(): void {
    logoForm.post(CompanySettingsController.uploadLogo.url(), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            files.value = [];
        },
    });
}

const removing = ref(false);
const confirmRemove = ref(false);

function removeLogo(): void {
    router.post(
        CompanySettingsController.removeLogo.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (removing.value = true),
            onFinish: () => (removing.value = false),
        },
    );
}

const help = [
    {
        title: 'Nombre y zona horaria',
        text: 'La zona se usa por defecto cuando un centro de trabajo no define la suya.',
        icon: Building2,
    },
    {
        title: 'Contacto de privacidad',
        text: 'Aparece en avisos de privacidad y acuses de los colaboradores.',
        icon: ShieldCheck,
    },
    {
        title: 'Logotipo',
        text: 'PNG, JPG o WebP de hasta 2 MB. Se muestra en el panel y documentos.',
        icon: ImageUp,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Datos de la empresa e identidad visual."
        :icon="Building2"
        :help="help"
        help-title="Datos de la empresa"
    />

    <SettingsNav />

    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <KpiTile
            label="Razones sociales"
            :value="summary.legal_entities"
            :icon="Landmark"
            tone="brand"
            href="/organizacion"
        />
        <KpiTile
            label="Centros de trabajo"
            :value="summary.work_centers"
            :icon="MapPin"
            tone="info"
            href="/organizacion?tab=work-centers"
        />
        <KpiTile
            label="Sucursales"
            :value="summary.branches"
            :icon="Store"
            tone="primary"
            href="/organizacion?tab=branches"
        />
        <KpiTile
            label="Colaboradores activos"
            :value="summary.employees"
            :icon="Users"
            tone="success"
            href="/colaboradores"
        />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <SectionCard title="Datos generales" :icon="Building2" divided>
            <form class="grid gap-5" @submit.prevent="save">
                <FormField
                    label="Nombre comercial"
                    :error="form.errors.name"
                    required
                    v-slot="{ id, describedBy }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        :disabled="!can.manage"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.name ? true : undefined"
                    />
                </FormField>

                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Zona horaria"
                        :error="form.errors.timezone"
                        required
                        v-slot="{ id }"
                    >
                        <ComboboxField
                            :id="id"
                            v-model="form.timezone"
                            :options="timezones"
                            :disabled="!can.manage"
                            :invalid="!!form.errors.timezone"
                            search-placeholder="Buscar zona…"
                        />
                    </FormField>

                    <FormField
                        label="Contacto de privacidad"
                        :error="form.errors.privacy_contact_email"
                        v-slot="{ id, describedBy }"
                    >
                        <Input
                            :id="id"
                            v-model="form.privacy_contact_email"
                            type="email"
                            placeholder="privacidad@empresa.mx"
                            :disabled="!can.manage"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </div>

                <FormField
                    label="Color de marca"
                    :error="
                        form.errors.brand_primary_color ||
                        (!colorValid ? 'Usa un color como #1F6FEB.' : null)
                    "
                    v-slot="{ id }"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex gap-2">
                            <button
                                v-for="color in PRESETS"
                                :key="color"
                                type="button"
                                :disabled="!can.manage"
                                class="flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-card transition-standard hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                :class="
                                    form.brand_primary_color?.toLowerCase() ===
                                        color.toLowerCase() &&
                                    'ring-2 ring-foreground'
                                "
                                :style="{ backgroundColor: color }"
                                :aria-label="`Usar ${color}`"
                                @click="form.brand_primary_color = color"
                            >
                                <Check
                                    v-if="
                                        form.brand_primary_color?.toLowerCase() ===
                                        color.toLowerCase()
                                    "
                                    class="icon-xs text-white"
                                />
                            </button>
                        </div>
                        <div class="relative w-36">
                            <Palette
                                class="pointer-events-none absolute top-1/2 left-2.5 icon-sm -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                :id="id"
                                v-model="form.brand_primary_color"
                                placeholder="#C8853F"
                                class="pl-8 font-mono uppercase"
                                :disabled="!can.manage"
                            />
                        </div>
                    </div>
                </FormField>

                <div v-if="can.manage" class="flex justify-end border-t pt-5">
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || !form.isDirty || !colorValid
                        "
                    >
                        <Spinner v-if="form.processing" />
                        <Check v-else class="icon-sm" />
                        Guardar cambios
                    </Button>
                </div>
            </form>
        </SectionCard>

        <SectionCard title="Logotipo" :icon="ImageUp" divided>
            <div class="grid gap-4">
                <div
                    class="flex h-36 items-center justify-center overflow-hidden rounded-xl border bg-[conic-gradient(at_50%_50%,var(--surface-2)_25%,var(--card)_0_50%,var(--surface-2)_0_75%,var(--card)_0)] bg-[length:16px_16px]"
                >
                    <img
                        v-if="preview || organization.logo_url"
                        :src="preview ?? organization.logo_url ?? undefined"
                        alt="Logotipo de la empresa"
                        class="max-h-28 max-w-[85%] object-contain drop-shadow-sm"
                    />
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 text-muted-foreground"
                    >
                        <Building2 class="icon-lg" />
                        <span class="text-xs">Sin logotipo</span>
                    </div>
                </div>

                <template v-if="can.manage">
                    <FileDropzone
                        v-model="files"
                        accept="image/png,image/jpeg,image/webp"
                        :multiple="false"
                        :max-files="1"
                        :max-size-mb="2"
                        title="Arrastra tu logotipo"
                        description="PNG, JPG o WebP · máx. 2 MB"
                        :invalid="!!logoForm.errors.logo"
                    />
                    <p
                        v-if="logoForm.errors.logo"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ logoForm.errors.logo }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            class="flex-1"
                            :disabled="!logoForm.logo || logoForm.processing"
                            @click="uploadLogo"
                        >
                            <Spinner v-if="logoForm.processing" />
                            <ImageUp v-else class="icon-sm" />
                            Subir
                        </Button>
                        <Button
                            v-if="organization.logo_url"
                            variant="outline"
                            :disabled="removing"
                            @click="confirmRemove = true"
                        >
                            <Trash2 class="icon-sm" />
                            Quitar
                        </Button>
                    </div>
                </template>
            </div>
        </SectionCard>
    </div>

    <ConfirmDialog
        v-model:open="confirmRemove"
        title="¿Quitar el logotipo?"
        description="El archivo se conserva en el historial; puedes subir otro cuando quieras."
        confirm-label="Quitar"
        @confirm="removeLogo"
    />
</template>
