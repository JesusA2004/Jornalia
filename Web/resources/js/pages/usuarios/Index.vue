<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    ClipboardCheck,
    Globe2,
    KeyRound,
    Landmark,
    MailCheck,
    MoreHorizontal,
    ShieldCheck,
    ShieldEllipsis,
    UserCog,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import RoleController from '@/actions/App/Http/Controllers/Web/Admin/RoleController';
import UserController from '@/actions/App/Http/Controllers/Web/Admin/UserController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep } from '@/types';
import AuthorityDialog from './components/AuthorityDialog.vue';
import EditAccessSheet from './components/EditAccessSheet.vue';
import InviteUserSheet from './components/InviteUserSheet.vue';
import TemporaryPasswordDialog from './components/TemporaryPasswordDialog.vue';
import { AUTHORITY_ROLE } from './types';
import type { OrgUserRow, UsersPageProps } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administración' },
            { title: 'Usuarios y roles', href: '/usuarios' },
        ],
    },
});

const props = defineProps<UsersPageProps>();

const { filters, activeCount, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        status: props.filters.status ?? null,
        role: props.filters.role ?? null,
    },
    { only: ['users', 'filters'] },
);

const meta = computed(() => toPaginationMeta(props.users));

const statusOptions = [
    { value: 'active', label: 'Activos' },
    { value: 'inactive', label: 'Inactivos' },
];

const columns: DataTableColumn<OrgUserRow>[] = [
    { key: 'name', label: 'Usuario' },
    { key: 'roles', label: 'Roles', hideBelow: 'md' },
    { key: 'scope', label: 'Alcance', hideBelow: 'lg' },
    { key: 'last_login_at', label: 'Último acceso', hideBelow: 'xl' },
    { key: 'status', label: 'Estado', class: 'w-28' },
    { key: 'actions', label: '', class: 'w-12', align: 'right' },
];

const inviteOpen = ref(false);
const authorityOpen = ref(false);
const editOpen = ref(false);
const editing = ref<OrgUserRow | null>(null);

function edit(user: OrgUserRow): void {
    editing.value = user;
    editOpen.value = true;
}

const confirmOpen = ref(false);
const target = ref<OrgUserRow | null>(null);
const busy = ref(false);

function onError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function askDeactivate(user: OrgUserRow): void {
    target.value = user;
    confirmOpen.value = true;
}

function deactivate(): void {
    if (!target.value) {
        return;
    }

    busy.value = true;
    router.post(
        UserController.deactivate.url(target.value.id),
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

function restore(user: OrgUserRow): void {
    router.post(
        UserController.restore.url(user.id),
        {},
        { preserveScroll: true, onError },
    );
}

function sendReset(user: OrgUserRow): void {
    router.post(
        UserController.reset.url(user.id),
        {},
        { preserveScroll: true, onError },
    );
}

function isAuthority(user: OrgUserRow): boolean {
    return user.roles.includes(AUTHORITY_ROLE);
}

const help: HelpStep[] = [
    {
        title: 'Invita',
        text: 'Por enlace al correo o con contraseña temporal.',
        icon: UserPlus,
    },
    {
        title: 'Asigna roles',
        text: 'Los roles definen qué puede ver y hacer.',
        icon: ShieldCheck,
    },
    {
        title: 'Limita el alcance',
        text: 'Todas las sucursales o sólo algunas.',
        icon: Globe2,
    },
    {
        title: 'Autoridad laboral',
        text: 'Sólo lectura, con vigencia; puedes revocarlo cuando quieras.',
        icon: Landmark,
    },
    {
        title: 'Separación de funciones',
        text: 'Quien administra usuarios no autoriza ni rectifica asistencia.',
        icon: ShieldEllipsis,
    },
    {
        title: 'Doble factor',
        text: 'Obligatorio para roles administrativos.',
        icon: KeyRound,
    },
    {
        title: 'Revisión trimestral',
        text: 'Confirma o desactiva cada usuario activo.',
        icon: ClipboardCheck,
    },
];

const revokeOpen = ref(false);

function askRevoke(user: OrgUserRow): void {
    target.value = user;
    revokeOpen.value = true;
}

function revokeAuthority(): void {
    if (!target.value) {
        return;
    }

    busy.value = true;
    router.post(
        `/usuarios/${target.value.id}/autoridad/revocar`,
        { reason: 'Revocación manual' },
        {
            preserveScroll: true,
            onError,
            onFinish: () => {
                busy.value = false;
                revokeOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <PageHeader
        title="Usuarios"
        description="Accesos a la plataforma."
        :icon="UserCog"
        :help="help"
        help-title="Usuarios y accesos"
    >
        <template #actions>
            <Button variant="outline" as-child>
                <Link href="/usuarios/revision">
                    <ClipboardCheck />
                    <span class="hidden sm:inline">Revisión</span>
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="RoleController.index.url()">
                    <ShieldEllipsis />
                    Roles
                </Link>
            </Button>
            <template v-if="can.manage">
                <Button variant="outline" @click="authorityOpen = true">
                    <Landmark />
                    <span class="hidden sm:inline"
                        >Acceso autoridad laboral</span
                    >
                    <span class="sm:hidden">Autoridad</span>
                </Button>
                <Button @click="inviteOpen = true">
                    <UserPlus />
                    Invitar
                </Button>
            </template>
        </template>
    </PageHeader>

    <button
        v-if="can.manage"
        type="button"
        class="group mb-6 flex w-full items-center gap-4 rounded-2xl border border-brand/25 bg-brand-soft/60 p-4 text-left transition-standard hover:border-brand/50 hover:shadow-elev-1"
        @click="authorityOpen = true"
    >
        <span
            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"
        >
            <Landmark class="size-5" aria-hidden="true" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-foreground"
                >Crear acceso para autoridad laboral</span
            >
            <span class="block text-sm text-muted-foreground"
                >Sólo lectura, con vigencia obligatoria.</span
            >
        </span>
        <span
            class="hidden text-sm font-semibold text-brand-strong group-hover:underline sm:inline"
            >Crear</span
        >
    </button>

    <Link
        v-if="open_review"
        href="/usuarios/revision"
        class="mb-6 flex items-center gap-3 rounded-2xl border border-warning/30 bg-warning-soft p-4 text-sm"
    >
        <ClipboardCheck class="size-5 shrink-0" aria-hidden="true" />
        <span class="flex-1"
            >Revisión trimestral {{ open_review.period }} pendiente · vence
            {{ formatDate(open_review.due_on, 'date') }}</span
        >
        <span class="font-semibold">Revisar</span>
    </Link>

    <EmptyState
        v-if="users.total === 0 && activeCount === 0"
        :icon="Users"
        tone="brand"
        bordered
        class="rounded-2xl py-14"
        title="Aún no hay usuarios"
        description="Invita a tu equipo para empezar."
    >
        <template v-if="can.manage" #actions>
            <Button @click="inviteOpen = true">
                <UserPlus />
                Invitar usuario
            </Button>
        </template>
    </EmptyState>

    <DataTable
        v-else
        :rows="users.data"
        :columns="columns"
        :pagination="meta"
        :only="['users', 'filters']"
        caption="Usuarios de la organización"
        empty-title="Sin coincidencias"
        empty-description="Ajusta los filtros."
    >
        <template #toolbar>
            <FilterBar
                :active-count="activeCount"
                class="w-full"
                @reset="reset"
            >
                <template #search>
                    <SearchInput
                        v-model="filters.search"
                        placeholder="Nombre o correo…"
                        class="w-full sm:w-64"
                    />
                </template>
                <SelectField
                    v-model="filters.status"
                    :options="statusOptions"
                    placeholder="Estado"
                    prefix="Estado:"
                    size="sm"
                    clearable
                    class="w-40"
                />
                <SelectField
                    v-model="filters.role"
                    :options="roles"
                    placeholder="Rol"
                    prefix="Rol:"
                    size="sm"
                    clearable
                    class="w-52"
                />
            </FilterBar>
        </template>

        <template #cell-name="{ row }">
            <div
                class="flex min-w-0 items-center gap-3"
                :class="row.status === 'inactive' ? 'opacity-60' : ''"
            >
                <UserAvatar :name="row.name" size="sm" />
                <div class="min-w-0">
                    <div
                        class="flex min-w-0 items-center gap-1.5 font-semibold"
                    >
                        <span class="truncate">{{ row.name }}</span>
                        <Badge
                            v-if="row.is_self"
                            variant="secondary"
                            class="px-1.5 text-[0.625rem]"
                            >Tú</Badge
                        >
                    </div>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ row.email }}
                    </p>
                    <p
                        v-if="row.employee"
                        class="truncate text-xs text-brand-strong"
                    >
                        {{ row.employee.employee_number }} · Colaborador
                    </p>
                </div>
            </div>
        </template>

        <template #cell-roles="{ row }">
            <div class="flex flex-wrap gap-1">
                <Badge
                    v-for="role in row.roles"
                    :key="role"
                    :variant="role === AUTHORITY_ROLE ? 'default' : 'outline'"
                    class="font-medium"
                >
                    <Landmark v-if="role === AUTHORITY_ROLE" />
                    {{ role }}
                </Badge>
                <span
                    v-if="row.roles.length === 0"
                    class="text-xs text-subtle-foreground"
                    >Sin rol</span
                >
            </div>
            <p
                v-if="isAuthority(row) && row.authority_expires_on"
                class="mt-1 text-xs text-muted-foreground"
            >
                <template v-if="row.authority_revoked_at">Revocado</template>
                <template v-else
                    >Vigente hasta
                    {{ formatDate(row.authority_expires_on, 'date') }}</template
                >
            </p>
            <Badge
                v-if="row.two_factor_required && !row.two_factor"
                variant="destructive"
                class="mt-1"
                >Sin doble factor</Badge
            >
        </template>

        <template #cell-scope="{ row }">
            <span
                v-if="row.access_all_branches"
                class="inline-flex items-center gap-1.5 text-sm"
            >
                <Globe2 class="size-4 text-brand-strong" aria-hidden="true" />
                Todas
            </span>
            <span
                v-else-if="row.branches.length > 0"
                class="text-sm"
                :title="row.branches.join(', ')"
            >
                {{ row.branches.slice(0, 2).join(', ') }}
                <span
                    v-if="row.branches.length > 2"
                    class="text-muted-foreground"
                    >+{{ row.branches.length - 2 }}</span
                >
            </span>
            <span v-else class="text-xs text-subtle-foreground"
                >Propio y reportes</span
            >
        </template>

        <template #cell-last_login_at="{ row }">
            <DateTimeDisplay
                :value="row.last_login_at"
                format="relative"
                fallback="Nunca"
                class="text-sm"
            />
        </template>

        <template #cell-status="{ row }">
            <StatusBadge :status="row.status" size="sm" />
        </template>

        <template #cell-actions="{ row }">
            <DropdownMenu v-if="can.manage">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Acciones"
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                    <DropdownMenuItem @select="edit(row)">
                        <ShieldCheck />
                        Editar accesos
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="sendReset(row)">
                        <MailCheck />
                        Enviar restablecimiento
                    </DropdownMenuItem>
                    <template v-if="!row.is_self">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-if="isAuthority(row) && row.status === 'active'"
                            @select="askRevoke(row)"
                        >
                            <Landmark />
                            Revocar autoridad
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="row.status === 'active'"
                            @select="askDeactivate(row)"
                        >
                            <Archive />
                            Desactivar acceso
                        </DropdownMenuItem>
                        <DropdownMenuItem v-else @select="restore(row)">
                            <ArchiveRestore />
                            Reactivar acceso
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </DataTable>

    <InviteUserSheet
        v-if="can.manage"
        v-model:open="inviteOpen"
        :roles="roles"
        :branches="branches"
        :employees="employees_without_account"
    />
    <EditAccessSheet
        v-if="can.manage"
        v-model:open="editOpen"
        :user="editing"
        :roles="roles"
        :branches="branches"
    />
    <AuthorityDialog v-if="can.manage" v-model:open="authorityOpen" />
    <TemporaryPasswordDialog :credentials="flash_password" />

    <ConfirmDialog
        v-model:open="revokeOpen"
        :title="`¿Revocar el acceso de ${target?.name ?? ''}?`"
        description="Se cierra de inmediato. Para volver a entrar necesitará una nueva vigencia."
        confirm-label="Revocar"
        tone="danger"
        :icon="Landmark"
        :loading="busy"
        :close-on-confirm="false"
        @confirm="revokeAuthority"
    />

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="`¿Desactivar el acceso de ${target?.name ?? ''}?`"
        description="No podrá entrar a esta organización. Su historial se conserva y puedes reactivarlo."
        confirm-label="Desactivar"
        tone="danger"
        :icon="KeyRound"
        :loading="busy"
        :close-on-confirm="false"
        @confirm="deactivate"
    />
</template>
