<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Check, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import FormField from '@/components/app/FormField.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi cuenta', href: '/settings/profile' },
            { title: 'Perfil', href: '/settings/profile' },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Perfil" />

    <SectionCard
        title="Información personal"
        description="Nombre y correo de acceso."
        :icon="UserRound"
        divided
    >
        <div class="mb-6 flex items-center gap-4">
            <UserAvatar
                :name="user.name"
                :src="user.avatar || null"
                size="lg"
            />
            <div class="min-w-0">
                <p class="truncate font-semibold">{{ user.name }}</p>
                <p class="truncate text-sm text-muted-foreground">
                    {{ user.email }}
                </p>
            </div>
        </div>

        <Form
            v-bind="ProfileController.update.form()"
            :options="{ preserveScroll: true }"
            class="grid gap-5"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <FormField
                label="Nombre completo"
                :error="errors.name"
                required
                v-slot="{ id, describedBy }"
            >
                <Input
                    :id="id"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Nombre y apellidos"
                    :aria-describedby="describedBy"
                    :aria-invalid="errors.name ? true : undefined"
                />
            </FormField>

            <FormField
                label="Correo electrónico"
                :error="errors.email"
                required
                v-slot="{ id, describedBy }"
            >
                <Input
                    :id="id"
                    type="email"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="nombre@empresa.com"
                    :aria-describedby="describedBy"
                    :aria-invalid="errors.email ? true : undefined"
                />
            </FormField>

            <div class="flex items-center gap-3 border-t pt-5">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                >
                    <Spinner v-if="processing" />
                    Guardar cambios
                </Button>
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-active-class="transition-opacity duration-300"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-show="recentlySuccessful"
                        class="flex items-center gap-1.5 text-sm text-success-soft-foreground"
                    >
                        <Check class="icon-sm" aria-hidden="true" />
                        Cambios guardados
                    </p>
                </Transition>
            </div>
        </Form>
    </SectionCard>
</template>
