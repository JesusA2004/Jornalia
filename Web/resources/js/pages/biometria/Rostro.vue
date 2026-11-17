<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Camera,
    CameraOff,
    FileSignature,
    IdCard,
    RotateCcw,
    ScanFace,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import FaceEnrollmentController from '@/actions/App/Http/Controllers/Web/Biometrics/FaceEnrollmentController';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { HelpStep } from '@/types';
import type { FaceEnrollmentPageProps, FaceEnrollmentRow } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Rostro' },
        ],
    },
});

const props = defineProps<FaceEnrollmentPageProps>();

const help: HelpStep[] = [
    {
        title: 'Identidad en persona',
        text: 'Valida una identificación oficial frente al colaborador antes de capturar.',
        icon: IdCard,
    },
    {
        title: 'Consentimiento por escrito',
        text: 'Dato biométrico sensible: firma expresa del colaborador (LFPDPPP). Si no consiente, usa la huella del teléfono.',
        icon: FileSignature,
    },
    {
        title: 'Captura',
        text: 'Rostro de frente, buena luz, sin lentes oscuros ni gorra. Una sola persona.',
        icon: ScanFace,
    },
    {
        title: 'Privacidad',
        text: 'No se guardan fotos: sólo una plantilla numérica cifrada.',
        icon: ShieldCheck,
    },
    {
        title: 'Revocar o cancelar',
        text: 'Revocar deja de usarla. Cancelar (derecho ARCO) vacía la plantilla de forma definitiva.',
        icon: ShieldOff,
    },
];

const active = computed(() => props.enrollments.find((e) => e.active) ?? null);

/* ---------------------------------------------------------- Cámara -- */
const FRAME_COUNT = Math.min(5, props.limits.max_frames);
const video = ref<HTMLVideoElement | null>(null);
const stream = ref<MediaStream | null>(null);
const cameraError = ref<string | null>(null);
const capturing = ref(false);
const shots = ref<{ file: File; url: string }[]>([]);

async function startCamera(): Promise<void> {
    cameraError.value = null;

    if (!navigator.mediaDevices?.getUserMedia) {
        cameraError.value =
            'Este navegador no permite usar la cámara aquí (requiere conexión segura HTTPS).';

        return;
    }

    try {
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
            audio: false,
        });
        if (video.value) {
            video.value.srcObject = stream.value;
            await video.value.play();
        }
    } catch {
        cameraError.value =
            'No fue posible abrir la cámara. Revisa los permisos del navegador.';
    }
}

function stopCamera(): void {
    stream.value?.getTracks().forEach((t) => t.stop());
    stream.value = null;
}

function clearShots(): void {
    shots.value.forEach((s) => URL.revokeObjectURL(s.url));
    shots.value = [];
}

function grab(): Promise<File> {
    const el = video.value as HTMLVideoElement;
    const canvas = document.createElement('canvas');
    canvas.width = el.videoWidth;
    canvas.height = el.videoHeight;
    canvas.getContext('2d')?.drawImage(el, 0, 0);

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) =>
                blob
                    ? resolve(
                          new File([blob], `cuadro-${Date.now()}.jpg`, {
                              type: 'image/jpeg',
                          }),
                      )
                    : reject(new Error('blob')),
            'image/jpeg',
            0.9,
        );
    });
}

async function capture(): Promise<void> {
    if (!video.value || !stream.value) {
        return;
    }
    capturing.value = true;
    clearShots();
    try {
        for (let i = 0; i < FRAME_COUNT; i++) {
            const file = await grab();
            shots.value.push({ file, url: URL.createObjectURL(file) });
            await new Promise((r) => setTimeout(r, 350));
        }
    } finally {
        capturing.value = false;
    }
}

onBeforeUnmount(() => {
    stopCamera();
    clearShots();
});

/* ------------------------------------------------------- Registrar -- */
const form = useForm<{
    frames: File[];
    identity_verified: boolean;
    consent: boolean;
}>({
    frames: [],
    identity_verified: false,
    consent: false,
});

const canSubmit = computed(
    () =>
        props.service.available &&
        shots.value.length >= props.limits.min_frames &&
        form.identity_verified &&
        form.consent &&
        !form.processing,
);

function submit(): void {
    form.frames = shots.value.map((s) => s.file);
    form.submit(FaceEnrollmentController.store(props.employee.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            clearShots();
            stopCamera();
        },
        onFinish: () => {
            form.frames = [];
        },
    });
}

/* --------------------------------------------- Revocar / cancelar -- */
const target = ref<FaceEnrollmentRow | null>(null);
const revokeOpen = ref(false);
const eraseOpen = ref(false);
const revokeForm = useForm({ reason: '' });
const eraseForm = useForm({ reason: '', confirm: false });

function askRevoke(row: FaceEnrollmentRow): void {
    target.value = row;
    revokeForm.reset();
    revokeForm.clearErrors();
    revokeOpen.value = true;
}

function askErase(row: FaceEnrollmentRow): void {
    target.value = row;
    eraseForm.reset();
    eraseForm.clearErrors();
    eraseOpen.value = true;
}

function submitRevoke(): void {
    if (!target.value) {
        return;
    }
    revokeForm.submit(
        FaceEnrollmentController.revoke({
            employee: props.employee.id,
            enrollment: target.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => (revokeOpen.value = false),
        },
    );
}

function submitErase(): void {
    if (!target.value) {
        return;
    }
    eraseForm.submit(
        FaceEnrollmentController.erase({
            employee: props.employee.id,
            enrollment: target.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => (eraseOpen.value = false),
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Registro facial"
            :description="`${employee.full_name} · ${employee.employee_number}`"
            :icon="ScanFace"
            :help="help"
            :back-href="EmployeeController.show(employee.id)"
            back-label="Expediente"
        >
            <template #meta>
                <StatusBadge v-if="active" status="active" label="Registrado" />
                <StatusBadge v-else status="inactive" label="Sin registro" />
            </template>
        </PageHeader>

        <Alert v-if="!service.available" variant="destructive">
            <ShieldAlert />
            <AlertTitle>Motor facial no disponible</AlertTitle>
            <AlertDescription>Contacta a soporte técnico.</AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <SectionCard title="Captura" :icon="Camera">
                <div class="flex flex-col gap-4">
                    <div
                        class="relative aspect-video overflow-hidden rounded-xl bg-surface-2"
                    >
                        <video
                            ref="video"
                            class="size-full -scale-x-100 object-cover"
                            playsinline
                            muted
                            :class="{ invisible: !stream }"
                        />
                        <div
                            v-if="!stream"
                            class="absolute inset-0 flex items-center justify-center"
                        >
                            <Button
                                :disabled="!service.available"
                                @click="startCamera"
                            >
                                <Camera /> Abrir cámara
                            </Button>
                        </div>
                    </div>

                    <p v-if="cameraError" class="text-sm text-destructive">
                        {{ cameraError }}
                    </p>

                    <div v-if="stream" class="flex flex-wrap gap-2">
                        <Button :disabled="capturing" @click="capture">
                            <Spinner v-if="capturing" />
                            <ScanFace v-else />
                            {{ shots.length ? 'Repetir captura' : 'Capturar' }}
                        </Button>
                        <Button variant="outline" @click="stopCamera"
                            ><CameraOff /> Cerrar cámara</Button
                        >
                    </div>

                    <div v-if="shots.length" class="grid grid-cols-5 gap-2">
                        <img
                            v-for="(s, i) in shots"
                            :key="i"
                            :src="s.url"
                            alt=""
                            class="aspect-square w-full -scale-x-100 rounded-lg object-cover"
                        />
                    </div>
                    <p
                        v-if="form.errors.frames"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.frames }}
                    </p>
                </div>
            </SectionCard>

            <SectionCard title="Confirmación" :icon="FileSignature">
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <FormField :error="form.errors.identity_verified">
                        <Label class="flex items-start gap-3 font-normal">
                            <Checkbox v-model="form.identity_verified" />
                            Validé su identificación oficial en persona.
                        </Label>
                    </FormField>
                    <FormField :error="form.errors.consent">
                        <Label class="flex items-start gap-3 font-normal">
                            <Checkbox v-model="form.consent" />
                            <span>
                                Firmó el consentimiento expreso
                                <template v-if="consentDocument">
                                    «{{ consentDocument.title }}» v{{
                                        consentDocument.version
                                    }}
                                    <span
                                        class="font-mono text-xs text-muted-foreground"
                                        >{{
                                            consentDocument.sha256.slice(0, 12)
                                        }}</span
                                    >
                                </template>
                            </span>
                        </Label>
                    </FormField>
                    <Button type="submit" :disabled="!canSubmit">
                        <Spinner v-if="form.processing" />
                        <ShieldCheck v-else />
                        {{
                            active ? 'Reemplazar registro' : 'Registrar rostro'
                        }}
                    </Button>
                </form>
            </SectionCard>
        </div>

        <SectionCard title="Historial" padding="none">
            <EmptyState
                v-if="!enrollments.length"
                title="Sin registros"
                :icon="ScanFace"
                size="sm"
                class="m-5"
            />
            <ul v-else class="divide-y">
                <li
                    v-for="row in enrollments"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 px-5 py-4"
                >
                    <StatusBadge
                        :status="row.active ? 'active' : 'revoked'"
                        :label="
                            row.template_erased_at ? 'Cancelado' : undefined
                        "
                    />
                    <div class="min-w-0 flex-1 text-sm">
                        <p>
                            <DateTimeDisplay :value="row.consent_at" />
                            <span class="text-muted-foreground">
                                · {{ row.identity_verified_by }}</span
                            >
                        </p>
                        <p
                            v-if="row.revocation_reason"
                            class="truncate text-muted-foreground"
                        >
                            {{ row.revocation_reason }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="row.active"
                            size="sm"
                            variant="outline"
                            @click="askRevoke(row)"
                        >
                            <RotateCcw /> Revocar
                        </Button>
                        <Button
                            v-if="!row.template_erased_at"
                            size="sm"
                            variant="ghost"
                            class="text-destructive"
                            @click="askErase(row)"
                        >
                            <Trash2 /> Cancelar (ARCO)
                        </Button>
                    </div>
                </li>
            </ul>
        </SectionCard>

        <ConfirmDialog
            v-model:open="revokeOpen"
            title="Revocar registro facial"
            confirm-label="Revocar"
            tone="danger"
            :loading="revokeForm.processing"
            :close-on-confirm="false"
            @confirm="submitRevoke"
        >
            <FormField
                label="Motivo"
                :error="revokeForm.errors.reason"
                required
                v-slot="{ id }"
            >
                <Textarea
                    :id="id"
                    v-model="revokeForm.reason"
                    rows="3"
                    maxlength="250"
                />
            </FormField>
        </ConfirmDialog>

        <ConfirmDialog
            v-model:open="eraseOpen"
            title="Cancelar plantilla (derecho ARCO)"
            description="La plantilla se vacía de forma definitiva. El registro y la bitácora se conservan."
            confirm-label="Cancelar plantilla"
            tone="danger"
            :loading="eraseForm.processing"
            :close-on-confirm="false"
            @confirm="submitErase"
        >
            <div class="flex flex-col gap-4">
                <FormField
                    label="Folio o motivo de la solicitud"
                    :error="eraseForm.errors.reason"
                    required
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="eraseForm.reason"
                        maxlength="250"
                    />
                </FormField>
                <FormField :error="eraseForm.errors.confirm">
                    <Label class="flex items-start gap-3 font-normal">
                        <Checkbox v-model="eraseForm.confirm" />
                        Entiendo que no se puede deshacer.
                    </Label>
                </FormField>
            </div>
        </ConfirmDialog>
    </div>
</template>
