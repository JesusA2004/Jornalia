<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    Lock,
    Plus,
    RotateCcw,
    Save,
    ShieldCheck,
    ShieldEllipsis,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import RoleController from '@/actions/App/Http/Controllers/Web/Admin/RoleController';
import UserController from '@/actions/App/Http/Controllers/Web/Admin/UserController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectField from '@/components/app/SelectField.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { HelpStep } from '@/types';
import type { PermissionModule, RoleRow, RolesPageProps } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Usuarios y roles', href: '/usuarios' },
            { title: 'Roles', href: '/usuarios/roles' },
        ],
    },
});

const props = defineProps<RolesPageProps>();

const showInactive = ref(false);
const visibleRoles = computed(() =>
    props.roles.filter((r) => showInactive.value || !r.deleted),
);

const selectedId = ref<number | null>(
    props.roles.find((r) => !r.deleted)?.id ?? null,
);
const selected = computed<RoleRow | null>(
    () => props.roles.find((r) => r.id === selectedId.value) ?? null,
);

/* ------------------------------------------------------------- Edición -- */
const name = ref('');
const draft = ref<Set<string>>(new Set());
const saving = ref(false);
const errors = ref<Record<string, string>>({});

function load(role: RoleRow | null): void {
    name.value = role?.name ?? '';
    draft.value = new Set(role?.permissions ?? []);
    errors.value = {};
}

watch(selected, (role) => load(role), { immediate: true });

const editable = computed(
    () => props.can.manage && !!selected.value && !selected.value.deleted,
);

const dirty = computed(() => {
    const role = selected.value;

    if (!role) {
        return false;
    }

    if (
        name.value.trim() !== role.name ||
        draft.value.size !== role.permissions.length
    ) {
        return true;
    }

    return role.permissions.some((p) => !draft.value.has(p));
});

function toggle(permission: string, value: boolean | 'indeterminate'): void {
    const next = new Set(draft.value);

    if (value === true) {
        next.add(permission);
    } else {
        next.delete(permission);
    }

    draft.value = next;
}

function moduleState(module: PermissionModule): boolean | 'indeterminate' {
    const count = module.actions.filter((a) => draft.value.has(a.key)).length;

    if (count === 0) {
        return false;
    }

    return count === module.actions.length ? true : 'indeterminate';
}

function toggleModule(
    module: PermissionModule,
    value: boolean | 'indeterminate',
): void {
    const next = new Set(draft.value);

    for (const action of module.actions) {
        if (value === true) {
            next.add(action.key);
        } else {
            next.delete(action.key);
        }
    }

    draft.value = next;
}

const total = computed(() =>
    props.modules.reduce((n, m) => n + m.actions.length, 0),
);

function save(): void {
    if (!selected.value) {
        return;
    }

    saving.value = true;
    router.put(
        RoleController.update.url(selected.value.id),
        { name: name.value.trim(), permissions: [...draft.value] },
        {
            preserveScroll: true,
            onError: (e) => {
                errors.value = e;
            },
            onSuccess: () => {
                errors.value = {};
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

/* -------------------------------------------------------------- Alta -- */
const createOpen = ref(false);
const createForm = useForm({ name: '', copy_from: null as string | null });

const copyOptions = computed(() =>
    props.roles
        .filter((r) => !r.deleted)
        .map((r) => ({ value: String(r.id), label: r.name })),
);

function openCreate(): void {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
}

function create(): void {
    const source = props.roles.find(
        (r) => String(r.id) === createForm.copy_from,
    );
    const roleName = createForm.name.trim();

    createForm
        .transform(() => ({
            name: roleName,
            permissions: source ? [...source.permissions] : [],
        }))
        .post(RoleController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                createOpen.value = false;
                const created = props.roles.find((r) => r.name === roleName);

                if (created) {
                    selectedId.value = created.id;
                }
            },
        });
}

/* ------------------------------------------------- Desactivar / reactivar -- */
const confirmOpen = ref(false);
const busy = ref(false);

function onError(e: Record<string, string>): void {
    const message = Object.values(e)[0];

    if (message) {
        toast.error(message);
    }
}

function deactivate(): void {
    if (!selected.value) {
        return;
    }

    busy.value = true;
    router.post(
        RoleController.deactivate.url(selected.value.id),
        {},
        {
            preserveScroll: true,
            onError,
            onFinish: () => {
                busy.value = false;
                confirmOpen.value = false;
            },
        },
    );
}

function restore(): void {
    if (selected.value) {
        router.post(
            RoleController.restore.url(selected.value.id),
            {},
            { preserveScroll: true, onError },
        );
    }
}

const serverError = computed(
    () =>
        errors.value.roles ??
        errors.value.role ??
        errors.value.permissions ??
        null,
);

const help: HelpStep[] = [
    {
        title: 'Elige un rol',
        text: 'Selecciónalo en la lista de la izquierda.',
        icon: ShieldEllipsis,
    },
    {
        title: 'Marca permisos',
        text: 'Por módulo y acción. La casilla del módulo marca todo.',
        icon: ShieldCheck,
    },
    {
        title: 'Guarda',
        text: 'Los cambios aplican de inmediato a sus usuarios.',
        icon: Save,
    },
    {
        title: 'Protección',
        text: 'Siempre debe quedar alguien que administre roles.',
        icon: Lock,
    },
];
</script>

<template>
    <PageHeader
        title="Roles y permisos"
        description="Qué puede ver y hacer cada rol."
        :icon="ShieldEllipsis"
        :back-href="UserController.index.url()"
        back-label="Usuarios"
        :help="help"
        help-title="Roles y permisos"
    >
        <template #actions>
            <Button v-if="can.manage" @click="openCreate">
                <Plus />
                Nuevo rol
            </Button>
        </template>
    </PageHeader>

    <EmptyState
        v-if="roles.length === 0"
        :icon="UserCog"
        bordered
        tone="brand"
        class="rounded-2xl py-14"
        title="Sin roles"
        description="Crea el primer rol de la organización."
    />

    <div v-else class="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <!-- Lista de roles -->
        <aside class="space-y-3">
            <div class="flex items-center justify-between px-1">
                <p
                    class="text-xs font-bold tracking-[0.12em] text-muted-foreground uppercase"
                >
                    {{ visibleRoles.length }} roles
                </p>
                <div class="flex items-center gap-2">
                    <Switch id="roles-inactive" v-model="showInactive" />
                    <Label
                        for="roles-inactive"
                        class="text-xs text-muted-foreground"
                        >Inactivos</Label
                    >
                </div>
            </div>
            <nav
                class="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible"
                aria-label="Roles"
            >
                <button
                    v-for="role in visibleRoles"
                    :key="role.id"
                    type="button"
                    :aria-current="role.id === selectedId ? 'true' : undefined"
                    :class="
                        cn(
                            'flex min-w-56 items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-standard lg:min-w-0',
                            role.id === selectedId
                                ? 'border-brand-strong bg-brand-soft shadow-xs'
                                : 'border-border bg-card hover:bg-accent',
                            role.deleted && 'opacity-60',
                        )
                    "
                    @click="selectedId = role.id"
                >
                    <span
                        :class="
                            cn(
                                'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                role.id === selectedId
                                    ? 'bg-brand-gradient text-black'
                                    : 'bg-surface-3 text-muted-foreground',
                            )
                        "
                    >
                        <Lock
                            v-if="role.protected"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <ShieldCheck v-else class="size-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{
                            role.name
                        }}</span>
                        <span
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><Users class="size-3" aria-hidden="true" />{{
                                    role.users_count
                                }}</span
                            >
                            <span>{{ role.permissions.length }} permisos</span>
                            <span
                                v-if="role.deleted"
                                class="text-warning-soft-foreground"
                                >Inactivo</span
                            >
                        </span>
                    </span>
                </button>
            </nav>
        </aside>

        <!-- Matriz -->
        <section
            v-if="selected"
            class="min-w-0 rounded-2xl border bg-card shadow-xs"
        >
            <header
                class="flex flex-col gap-4 border-b p-5 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="min-w-0 flex-1 space-y-1.5">
                    <Label for="role-name" class="text-xs text-muted-foreground"
                        >Nombre del rol</Label
                    >
                    <Input
                        id="role-name"
                        v-model="name"
                        :disabled="!editable || selected.protected"
                        class="max-w-sm text-base font-semibold"
                        :aria-invalid="errors.name ? true : undefined"
                    />
                    <p
                        v-if="errors.name"
                        class="text-xs text-danger-soft-foreground"
                    >
                        {{ errors.name }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <Badge variant="outline"
                            >{{ draft.size }} / {{ total }} permisos</Badge
                        >
                        <Badge v-if="selected.protected" variant="secondary"
                            ><Lock />Del sistema</Badge
                        >
                        <Badge v-if="selected.deleted" variant="secondary"
                            >Inactivo</Badge
                        >
                    </div>
                </div>
                <div v-if="can.manage" class="flex flex-wrap gap-2">
                    <template v-if="!selected.deleted">
                        <Button
                            v-if="!selected.protected"
                            variant="outline"
                            size="sm"
                            @click="confirmOpen = true"
                        >
                            <Archive />
                            Desactivar
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="!dirty"
                            @click="load(selected)"
                        >
                            <RotateCcw />
                            Descartar
                        </Button>
                        <Button
                            size="sm"
                            :disabled="!dirty || saving"
                            @click="save"
                        >
                            <Spinner v-if="saving" />
                            <Save v-else />
                            Guardar
                        </Button>
                    </template>
                    <Button v-else size="sm" @click="restore">
                        <ArchiveRestore />
                        Reactivar
                    </Button>
                </div>
            </header>

            <div class="space-y-4 p-5">
                <Alert v-if="serverError" variant="destructive">
                    <AlertDescription>{{ serverError }}</AlertDescription>
                </Alert>

                <div class="grid gap-3 xl:grid-cols-2">
                    <fieldset
                        v-for="module in modules"
                        :key="module.key"
                        :disabled="!editable"
                        :class="
                            cn(
                                'rounded-xl border p-3.5 transition-standard',
                                moduleState(module) === false
                                    ? 'bg-card'
                                    : 'border-brand/30 bg-brand-soft/40',
                            )
                        "
                    >
                        <legend class="sr-only">{{ module.label }}</legend>
                        <label class="flex cursor-pointer items-center gap-2.5">
                            <Checkbox
                                :model-value="moduleState(module)"
                                :disabled="!editable"
                                @update:model-value="
                                    (v) => toggleModule(module, v)
                                "
                            />
                            <span class="text-sm font-semibold">{{
                                module.label
                            }}</span>
                            <span
                                class="ml-auto font-mono text-[0.6875rem] text-subtle-foreground"
                                >{{ module.key }}</span
                            >
                        </label>
                        <div class="mt-2.5 grid gap-1.5 pl-6 sm:grid-cols-2">
                            <label
                                v-for="action in module.actions"
                                :key="action.key"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-sm hover:bg-accent/60"
                            >
                                <Checkbox
                                    :model-value="draft.has(action.key)"
                                    :disabled="!editable"
                                    @update:model-value="
                                        (v) => toggle(action.key, v)
                                    "
                                />
                                {{ action.label }}
                            </label>
                        </div>
                    </fieldset>
                </div>
            </div>
        </section>
    </div>

    <Dialog v-model:open="createOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Nuevo rol</DialogTitle>
            </DialogHeader>
            <form
                id="create-role-form"
                class="grid gap-4"
                @submit.prevent="create"
            >
                <FormField
                    label="Nombre"
                    required
                    :error="createForm.errors.name"
                    v-slot="{ id, describedBy, invalid }"
                >
                    <Input
                        :id="id"
                        v-model="createForm.name"
                        placeholder="Jefe de turno"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid || undefined"
                    />
                </FormField>
                <FormField
                    label="Copiar permisos de"
                    hint="Opcional"
                    v-slot="{ id }"
                >
                    <SelectField
                        :id="id"
                        v-model="createForm.copy_from"
                        :options="copyOptions"
                        placeholder="Empezar sin permisos"
                        clearable
                    />
                </FormField>
            </form>
            <DialogFooter>
                <Button
                    variant="outline"
                    type="button"
                    @click="createOpen = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    form="create-role-form"
                    :disabled="createForm.processing || !createForm.name.trim()"
                >
                    <Spinner v-if="createForm.processing" />
                    <Plus v-else />
                    Crear
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="`¿Desactivar el rol «${selected?.name ?? ''}»?`"
        description="Sólo puede desactivarse si no tiene usuarios. Puedes reactivarlo después."
        confirm-label="Desactivar"
        tone="danger"
        :icon="Archive"
        :loading="busy"
        :close-on-confirm="false"
        @confirm="deactivate"
    />
</template>
