<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    FileText,
    ImageUp,
    Landmark,
    MapPin,
    Palette,
    ShieldCheck,
    Store,
    Undo2,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CompanySettingsController from '@/actions/App/Http/Controllers/Web/Admin/CompanySettingsController';
import PolicyDocumentController from '@/actions/App/Http/Controllers/Web/Admin/PolicyDocumentController';
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
    CompanyLegalEntity,
    CompanyOrganization,
    CompanyPrivacyNotice,
    CompanySummary,
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
    legal_entity: CompanyLegalEntity;
    privacy_notice: CompanyPrivacyNotice;
    palette: string[];
    summary: CompanySummary;
    timezones: Option[];
    can: { manage: boolean; documents: boolean };
}>();

const form = useForm({
    legal_name: props.legal_entity.legal_name ?? '',
    rfc: props.legal_entity.rfc ?? '',
    name: props.organization.name,
    timezone: props.organization.timezone as string | null,
    fiscal_address: props.legal_entity.fiscal_address ?? '',
    privacy_contact_email: props.organization.privacy_contact_email ?? '',
    brand_primary_color: (
        props.organization.brand_primary_color ?? '#225C54'
    ).toUpperCase(),
});

function save(): void {
    form.transform((data) => ({
        ...data,
        privacy_contact_email: data.privacy_contact_email || null,
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

const restoring = ref(false);
const confirmRestore = ref(false);

function restoreOfficial(): void {
    router.post(
        CompanySettingsController.removeLogo.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (restoring.value = true),
            onFinish: () => (restoring.value = false),
        },
    );
}

const logoSrc = computed(
    () =>
        preview.value ??
        props.organization.logo_url ??
        props.organization.official_logo_url,
);

const documentsUrl = PolicyDocumentController.index.url();

const help = [
    {
        title: 'Datos fiscales',
        text: 'Razón social, RFC y domicilio fiscal de la Constancia de Situación Fiscal. Se usan en reportes y documentos.',
        icon: Landmark,
    },
    {
        title: 'Contacto de privacidad',
        text: 'Correo real del responsable de datos personales. Queda pendiente hasta que RH y Jurídico lo definan.',
        icon: ShieldCheck,
    },
    {
        title: 'Aviso de privacidad',
        text: 'PDF obligatorio para colaboradores. Se carga y versiona en Documentos; la app lo pide aceptar.',
        icon: FileText,
    },
    {
        title: 'Identidad',
        text: 'Color institucional #225C54 con acentos dorados #AF8B51 y logotipo oficial sin modificaciones.',
        icon: Palette,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Datos de la empresa, identidad visual y reglas del registro."
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
                <div
                    class="grid gap-5 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
                >
                    <FormField
                        label="Razón social"
                        :error="form.errors.legal_name"
                        required
                        v-slot="{ id, describedBy }"
                    >
                        <Input
                            :id="id"
                            v-model="form.legal_name"
                            :disabled="!can.manage"
                            :aria-describedby="describedBy"
                            :aria-invalid="
                                form.errors.legal_name ? true : undefined
                            "
                        />
                    </FormField>
                    <FormField
                        label="RFC"
                        :error="form.errors.rfc"
                        required
                        v-slot="{ id, describedBy }"
                    >
                        <Input
                            :id="id"
                            v-model="form.rfc"
                            class="font-mono uppercase"
                            maxlength="13"
                            :disabled="!can.manage"
                            :aria-describedby="describedBy"
                            :aria-invalid="form.errors.rfc ? true : undefined"
                        />
                    </FormField>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
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
                </div>

                <FormField
                    label="Domicilio fiscal"
                    :error="form.errors.fiscal_address"
                    required
                    v-slot="{ id, describedBy }"
                >
                    <Input
                        :id="id"
                        v-model="form.fiscal_address"
                        :disabled="!can.manage"
                        :aria-describedby="describedBy"
                        :aria-invalid="
                            form.errors.fiscal_address ? true : undefined
                        "
                    />
                </FormField>

                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Contacto de privacidad"
                        :error="form.errors.privacy_contact_email"
                        required
                        v-slot="{ id, describedBy }"
                    >
                        <Input
                            :id="id"
                            v-model="form.privacy_contact_email"
                            type="email"
                            placeholder="Correo del responsable de datos personales · por definir"
                            :class="
                                !form.privacy_contact_email &&
                                'border-warning/60 bg-warning-soft/40'
                            "
                            :disabled="!can.manage"
                            :aria-describedby="describedBy"
                        />
                    </FormField>

                    <FormField
                        label="Aviso de privacidad para colaboradores"
                        required
                    >
                        <Link
                            :href="documentsUrl"
                            :class="[
                                'flex h-9 items-center gap-2 rounded-md border px-3 text-sm',
                                privacy_notice?.has_pdf
                                    ? 'bg-card hover:bg-accent'
                                    : 'border-warning/60 bg-warning-soft/40 text-warning-soft-foreground',
                            ]"
                        >
                            <FileText class="icon-sm" aria-hidden="true" />
                            <template v-if="privacy_notice?.has_pdf">
                                PDF vigente · v{{ privacy_notice.version }}
                            </template>
                            <template v-else>Subir PDF vigente</template>
                        </Link>
                    </FormField>
                </div>

                <FormField
                    label="Color institucional"
                    :error="form.errors.brand_primary_color"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="color in palette"
                                :key="color"
                                type="button"
                                :disabled="!can.manage"
                                class="flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-card transition-standard hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                :class="
                                    form.brand_primary_color === color &&
                                    'ring-2 ring-foreground'
                                "
                                :style="{ backgroundColor: color }"
                                :aria-label="`Usar ${color}`"
                                :aria-pressed="
                                    form.brand_primary_color === color
                                "
                                @click="form.brand_primary_color = color"
                            >
                                <Check
                                    v-if="form.brand_primary_color === color"
                                    class="icon-xs text-white mix-blend-difference"
                                />
                            </button>
                        </div>
                        <span
                            class="rounded-md border px-2 py-1 font-mono text-xs"
                            >{{ form.brand_primary_color }}</span
                        >
                    </div>
                </FormField>

                <div v-if="can.manage" class="flex justify-end border-t pt-5">
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.isDirty"
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
                    class="flex h-36 items-center justify-center overflow-hidden rounded-xl bg-brand-deep"
                >
                    <img
                        :src="logoSrc"
                        alt="Logotipo de la empresa"
                        class="max-h-24 max-w-[80%] object-contain"
                    />
                </div>
                <p class="text-center text-xs text-muted-foreground">
                    {{
                        organization.logo_url
                            ? 'Archivo cargado'
                            : 'Logotipo oficial'
                    }}
                    · se usa en la app, reportes e inspección.
                </p>

                <template v-if="can.manage">
                    <FileDropzone
                        v-model="files"
                        accept="image/png,image/jpeg,image/webp"
                        :multiple="false"
                        :max-files="1"
                        :max-size-mb="2"
                        title="Reemplazar"
                        description="Archivo oficial · PNG, JPG o WebP · máx. 2 MB"
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
                            :disabled="restoring"
                            @click="confirmRestore = true"
                        >
                            <Undo2 class="icon-sm" />
                            Oficial
                        </Button>
                    </div>
                </template>
            </div>
        </SectionCard>
    </div>

    <ConfirmDialog
        v-model:open="confirmRestore"
        title="¿Usar el logotipo oficial?"
        description="El archivo cargado se conserva en el historial."
        confirm-label="Usar oficial"
        @confirm="restoreOfficial"
    />
</template>
