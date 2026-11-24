<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Check,
    CopyPlus,
    Eye,
    FileSignature,
    FileText,
    FileUp,
    Fingerprint,
    Lock,
    Pencil,
    Plus,
    RotateCcw,
    Send,
    SquarePen,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PolicyDocumentController from '@/actions/App/Http/Controllers/Web/Admin/PolicyDocumentController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import InactiveToggle from './components/InactiveToggle.vue';
import MarkdownPreview from './components/MarkdownPreview.vue';
import SettingsNav from './components/SettingsNav.vue';
import ToggleRow from './components/ToggleRow.vue';
import type { ManageCan, PolicyDocumentRow } from './types';

type RegistrationCode = { code: string; title: string; pdf_required: boolean };

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Configuración', href: '/configuracion' },
            { title: 'Documentos' },
        ],
    },
});

const props = defineProps<{
    documents: PolicyDocumentRow[];
    active_employees: number;
    registration_codes: RegistrationCode[];
    can: ManageCan;
}>();

const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

/** Documentos del registro electrónico que aún no existen (se ofrecen como plantilla). */
const missingRegistration = computed(() =>
    props.registration_codes.filter(
        (r) => !props.documents.some((d) => d.code === r.code),
    ),
);

function pdfRequired(code: string): boolean {
    return props.registration_codes.some(
        (r) => r.code === code && r.pdf_required,
    );
}

function createPreset(preset: RegistrationCode): void {
    editing.value = null;
    form.defaults({ ...blank(), code: preset.code, title: preset.title });
    form.reset();
    form.clearErrors();
    editorTab.value = 'write';
    open.value = true;
}

const pdfInput = ref<HTMLInputElement | null>(null);
const pdfTarget = ref<PolicyDocumentRow | null>(null);
const uploading = ref<number | null>(null);

function pickPdf(doc: PolicyDocumentRow): void {
    pdfTarget.value = doc;
    pdfInput.value?.click();
}

function uploadPdf(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const doc = pdfTarget.value;
    input.value = '';
    if (!file || !doc) {
        return;
    }
    router.post(
        PolicyDocumentController.uploadPdf.url(doc.id),
        { file },
        {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => (uploading.value = doc.id),
            onFinish: () => (uploading.value = null),
        },
    );
}

const showInactive = ref(false);
const inactiveCount = computed(
    () => props.documents.filter((d) => d.deleted).length,
);

const groups = computed(() => {
    const map = new Map<string, PolicyDocumentRow[]>();

    for (const doc of props.documents) {
        if (doc.deleted && !showInactive.value) {
            continue;
        }

        map.set(doc.code, [...(map.get(doc.code) ?? []), doc]);
    }

    return [...map.entries()].map(([code, versions]) => {
        const current =
            versions.find((v) => v.published_at && !v.deleted) ?? null;
        const published = versions
            .filter((v) => v.published_at)
            .sort((a, b) =>
                (b.published_at ?? '').localeCompare(a.published_at ?? ''),
            );

        return {
            code,
            title: (published[0] ?? versions[0]).title,
            latest: published[0] ?? current,
            versions,
        };
    });
});

function ackPercent(doc: PolicyDocumentRow): number {
    return props.active_employees > 0
        ? Math.min(
              100,
              Math.round(
                  (doc.acknowledgements_count / props.active_employees) * 100,
              ),
          )
        : 0;
}

type DocForm = {
    code: string;
    title: string;
    version: string;
    body_markdown: string;
    requires_acknowledgement: boolean;
};

const blank = (): DocForm => ({
    code: '',
    title: '',
    version: '1.0',
    body_markdown: '',
    requires_acknowledgement: true,
});

const open = ref(false);
const editing = ref<PolicyDocumentRow | null>(null);
const form = useForm<DocForm>(blank());
const editorTab = ref('write');

function create(): void {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    editorTab.value = 'write';
    open.value = true;
}

function edit(doc: PolicyDocumentRow): void {
    editing.value = doc;
    form.defaults({
        code: doc.code,
        title: doc.title,
        version: doc.version,
        body_markdown: doc.body_markdown,
        requires_acknowledgement: doc.requires_acknowledgement,
    });
    form.reset();
    form.clearErrors();
    editorTab.value = 'write';
    open.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.put(
            PolicyDocumentController.update.url(editing.value.id),
            options,
        );
    } else {
        form.post(PolicyDocumentController.store.url(), options);
    }
}

const viewing = ref<PolicyDocumentRow | null>(null);
const viewOpen = ref(false);

function view(doc: PolicyDocumentRow): void {
    viewing.value = doc;
    viewOpen.value = true;
}

const publishTarget = ref<PolicyDocumentRow | null>(null);
const publishOpen = ref(false);

function askPublish(doc: PolicyDocumentRow): void {
    publishTarget.value = doc;
    publishOpen.value = true;
}

function publish(): void {
    if (publishTarget.value) {
        router.post(
            PolicyDocumentController.publish.url(publishTarget.value.id),
            {},
            { preserveScroll: true },
        );
    }
}

const versionTarget = ref<PolicyDocumentRow | null>(null);
const versionOpen = ref(false);
const versionForm = useForm({ version: '' });

function suggestVersion(version: string): string {
    const match = version.match(/^(\d+)(?:\.(\d+))?/);

    return match ? `${match[1]}.${Number(match[2] ?? 0) + 1}` : `${version}-2`;
}

function askVersion(doc: PolicyDocumentRow): void {
    versionTarget.value = doc;
    versionForm.version = suggestVersion(doc.version);
    versionForm.clearErrors();
    versionOpen.value = true;
}

function createVersion(): void {
    if (versionTarget.value) {
        versionForm.post(
            PolicyDocumentController.newVersion.url(versionTarget.value.id),
            {
                preserveScroll: true,
                onSuccess: () => (versionOpen.value = false),
            },
        );
    }
}

function archive(doc: PolicyDocumentRow): void {
    router.post(
        PolicyDocumentController.deactivate.url(doc.id),
        {},
        { preserveScroll: true },
    );
}

function restore(doc: PolicyDocumentRow): void {
    router.post(
        PolicyDocumentController.restore.url(doc.id),
        {},
        { preserveScroll: true },
    );
}

const help = [
    {
        title: 'Borrador',
        text: 'Edítalo libremente hasta publicarlo.',
        icon: SquarePen,
    },
    {
        title: 'Publicación',
        text: 'Al publicar se sella con su huella SHA-256 y ya no puede modificarse.',
        icon: Lock,
    },
    {
        title: 'Nueva versión',
        text: 'Para cambiar un documento publicado crea una versión nueva; los colaboradores deberán aceptarla.',
        icon: CopyPlus,
    },
    {
        title: 'Acuses',
        text: 'Cada aceptación queda registrada con fecha, dispositivo y huella del texto.',
        icon: Fingerprint,
    },
    {
        title: 'Aviso de privacidad',
        text: 'AVISO_PRIVACIDAD exige su PDF para publicarse. CONVENIO_REGISTRO puede llevar PDF. Ambos se versionan y la app pide firmarlos.',
        icon: FileText,
    },
];
</script>

<template>
    <PageHeader
        title="Configuración"
        description="Documentos del sistema de registro para acuse."
        :icon="FileSignature"
        :help="help"
        help-title="Documentos para acuse"
    />

    <SettingsNav />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-display text-lg font-bold">Documentos</h2>
        <div class="flex flex-wrap items-center gap-2">
            <InactiveToggle v-model="showInactive" :count="inactiveCount" />
            <template v-if="can.manage">
                <Button
                    v-for="preset in missingRegistration"
                    :key="preset.code"
                    variant="outline"
                    @click="createPreset(preset)"
                >
                    <FileText class="icon-sm" />
                    {{ preset.title }}
                </Button>
            </template>
            <Button v-if="can.manage" @click="create">
                <Plus class="icon-sm" />
                Nuevo documento
            </Button>
        </div>
    </div>

    <input
        ref="pdfInput"
        type="file"
        accept="application/pdf"
        class="hidden"
        aria-hidden="true"
        tabindex="-1"
        @change="uploadPdf"
    />

    <p
        v-if="pageErrors.file"
        role="alert"
        class="mb-4 rounded-xl border border-danger/40 bg-danger-soft px-4 py-3 text-sm text-danger-soft-foreground"
    >
        {{ pageErrors.file }}
    </p>

    <EmptyState
        v-if="groups.length === 0"
        :icon="FileSignature"
        tone="primary"
        bordered
        class="rounded-2xl"
        title="Sin documentos"
        description="Publica el aviso del sistema de registro para que los colaboradores lo acepten."
    />

    <div v-else class="grid gap-4 lg:grid-cols-2">
        <article
            v-for="group in groups"
            :key="group.code"
            class="flex flex-col rounded-2xl border bg-card shadow-elev-1"
        >
            <header class="flex items-start gap-3 border-b p-5">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground"
                >
                    <FileSignature class="icon-sm" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="truncate font-semibold">{{ group.title }}</h3>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ group.code }}
                    </p>
                </div>
                <StatusBadge
                    v-if="group.latest"
                    status="active"
                    :label="`v${group.latest.version} vigente`"
                    tone="success"
                    size="sm"
                />
            </header>

            <div
                v-if="group.latest && group.latest.requires_acknowledgement"
                class="space-y-2 border-b px-5 py-4"
            >
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted-foreground"
                        >Acuses v{{ group.latest.version }}</span
                    >
                    <span class="font-semibold tabular-nums"
                        >{{ group.latest.acknowledgements_count }} /
                        {{ active_employees }}</span
                    >
                </div>
                <Progress :model-value="ackPercent(group.latest)" class="h-2" />
            </div>

            <ul class="divide-y">
                <li
                    v-for="doc in group.versions"
                    :key="doc.id"
                    :class="
                        cn(
                            'flex flex-wrap items-center gap-3 px-5 py-3',
                            doc.deleted && 'opacity-60',
                        )
                    "
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">v{{ doc.version }}</span>
                            <StatusBadge
                                v-if="doc.deleted"
                                status="inactive"
                                label="Archivado"
                                size="sm"
                            />
                            <StatusBadge
                                v-else-if="doc.published_at"
                                status="published"
                                label="Publicado"
                                tone="success"
                                size="sm"
                            />
                            <StatusBadge
                                v-else
                                status="draft"
                                label="Borrador"
                                size="sm"
                            />
                        </div>
                        <p
                            class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                        >
                            <DateTimeDisplay
                                v-if="doc.published_at"
                                :value="doc.published_at"
                                format="date"
                            />
                            <span v-else>{{ doc.created_by ?? '—' }}</span>
                            <span class="font-mono" :title="doc.sha256"
                                >#{{ doc.sha256.slice(0, 10) }}</span
                            >
                            <a
                                v-if="doc.file_url"
                                :href="doc.file_url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                :title="doc.file_sha256 ?? undefined"
                                ><FileText class="icon-xs" /> PDF</a
                            >
                            <span
                                v-else-if="pdfRequired(doc.code)"
                                class="font-medium text-warning-soft-foreground"
                                >PDF pendiente</span
                            >
                        </p>
                    </div>
                    <div class="flex gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Ver"
                            @click="view(doc)"
                        >
                            <Eye class="icon-sm" />
                        </Button>
                        <template v-if="can.manage">
                            <template v-if="doc.deleted">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Recuperar"
                                    @click="restore(doc)"
                                >
                                    <RotateCcw class="icon-sm" />
                                </Button>
                            </template>
                            <template v-else-if="!doc.published_at">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Editar"
                                    @click="edit(doc)"
                                >
                                    <Pencil class="icon-sm" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="
                                        doc.file_url
                                            ? 'Reemplazar PDF'
                                            : 'Subir PDF'
                                    "
                                    :disabled="uploading === doc.id"
                                    @click="pickPdf(doc)"
                                >
                                    <Spinner v-if="uploading === doc.id" />
                                    <FileUp v-else class="icon-sm" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Archivar borrador"
                                    @click="archive(doc)"
                                >
                                    <Archive class="icon-sm" />
                                </Button>
                                <Button size="sm" @click="askPublish(doc)">
                                    <Send class="icon-sm" />
                                    Publicar
                                </Button>
                            </template>
                            <Button
                                v-else
                                variant="outline"
                                size="sm"
                                @click="askVersion(doc)"
                            >
                                <CopyPlus class="icon-sm" />
                                Nueva versión
                            </Button>
                        </template>
                    </div>
                </li>
            </ul>
        </article>
    </div>

    <!-- Editor de borrador -->
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-3xl">
            <SheetHeader class="border-b">
                <SheetTitle>{{
                    editing ? `Borrador v${editing.version}` : 'Nuevo documento'
                }}</SheetTitle>
                <SheetDescription
                    >Se guarda como borrador hasta publicarlo.</SheetDescription
                >
            </SheetHeader>
            <form
                id="doc-form"
                class="grid flex-1 content-start gap-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <div class="grid gap-5 sm:grid-cols-[10rem_minmax(0,1fr)_7rem]">
                    <FormField
                        label="Clave"
                        :error="form.errors.code"
                        required
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="form.code"
                            class="font-mono uppercase"
                            placeholder="AVISO_REGISTRO"
                            :disabled="!!editing"
                        />
                    </FormField>
                    <FormField
                        label="Título"
                        :error="form.errors.title"
                        required
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="form.title"
                            placeholder="Aviso del sistema de registro de asistencia"
                        />
                    </FormField>
                    <FormField
                        label="Versión"
                        :error="form.errors.version"
                        required
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="form.version"
                            class="font-mono"
                        />
                    </FormField>
                </div>

                <Tabs v-model="editorTab">
                    <TabsList>
                        <TabsTrigger value="write"
                            ><SquarePen class="icon-sm" /> Escribir</TabsTrigger
                        >
                        <TabsTrigger value="preview"
                            ><Eye class="icon-sm" /> Vista previa</TabsTrigger
                        >
                    </TabsList>
                    <TabsContent value="write">
                        <FormField
                            :error="form.errors.body_markdown"
                            v-slot="{ id }"
                        >
                            <Textarea
                                :id="id"
                                v-model="form.body_markdown"
                                rows="18"
                                class="min-h-[22rem] font-mono text-sm leading-relaxed"
                                placeholder="# Título&#10;&#10;Texto del documento en Markdown…"
                            />
                        </FormField>
                    </TabsContent>
                    <TabsContent value="preview">
                        <div
                            class="min-h-[22rem] rounded-xl border bg-surface-2/40 p-5"
                        >
                            <MarkdownPreview :source="form.body_markdown" />
                        </div>
                    </TabsContent>
                </Tabs>

                <ToggleRow
                    v-model="form.requires_acknowledgement"
                    label="Requiere acuse"
                    description="Los colaboradores deberán aceptarlo en la app."
                />
            </form>
            <SheetFooter class="border-t">
                <Button
                    type="submit"
                    form="doc-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Check v-else class="icon-sm" />
                    Guardar borrador
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <!-- Lectura -->
    <Sheet v-model:open="viewOpen">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-3xl">
            <SheetHeader class="border-b">
                <SheetTitle
                    >{{ viewing?.title }} · v{{ viewing?.version }}</SheetTitle
                >
                <SheetDescription class="font-mono text-xs break-all"
                    >SHA-256 {{ viewing?.sha256 }}</SheetDescription
                >
            </SheetHeader>
            <div class="flex-1 overflow-y-auto p-6">
                <MarkdownPreview
                    v-if="viewing"
                    :source="viewing.body_markdown"
                />
            </div>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="publishOpen"
        :icon="Lock"
        :title="`¿Publicar v${publishTarget?.version ?? ''}?`"
        description="Una vez publicado no podrá modificarse. Para cambios crearás una nueva versión."
        confirm-label="Publicar"
        @confirm="publish"
    />

    <Dialog v-model:open="versionOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Nueva versión</DialogTitle>
                <DialogDescription
                    >Se copia el texto publicado como
                    borrador.</DialogDescription
                >
            </DialogHeader>
            <form id="version-form" @submit.prevent="createVersion">
                <FormField
                    label="Versión"
                    :error="versionForm.errors.version"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="versionForm.version"
                        class="font-mono"
                    />
                </FormField>
            </form>
            <DialogFooter>
                <Button
                    type="submit"
                    form="version-form"
                    :disabled="versionForm.processing || !versionForm.version"
                >
                    <Spinner v-if="versionForm.processing" />
                    <CopyPlus v-else class="icon-sm" />
                    Crear borrador
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
