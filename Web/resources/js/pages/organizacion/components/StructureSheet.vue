<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MapPin, Save } from '@lucide/vue';
import { computed, watch } from 'vue';
import OrganizationStructureController from '@/actions/App/Http/Controllers/Web/Admin/OrganizationStructureController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import FormField from '@/components/app/FormField.vue';
import SelectField from '@/components/app/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NumberField,
    NumberFieldContent,
    NumberFieldDecrement,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import OptionalTimeField from '@/pages/horarios/components/OptionalTimeField.vue';
import type { FieldDef, FormValue, StructureEntity } from '../types';
import { osmUrl } from '../utils';

const props = defineProps<{
    entity: StructureEntity;
    /** Registro a editar (null = alta). */
    recordId: number | null;
    title: string;
    description?: string;
    fields: FieldDef[];
    initial: Record<string, FormValue>;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<Record<string, FormValue>>({ ...props.initial });

watch(open, (value) => {
    if (value) {
        form.defaults({ ...props.initial });
        form.reset();
        form.clearErrors();
    }
});

const hasCoordinates = computed(() =>
    props.fields.some((f) => f.key === 'latitude'),
);

const mapUrl = computed(() =>
    osmUrl(form.latitude as string | null, form.longitude as string | null),
);

function text(key: string): string {
    const value = form[key];

    return value === null || value === undefined ? '' : String(value);
}

function setText(field: FieldDef, value: string | number): void {
    const raw = String(value);
    form[field.key] =
        raw === '' ? null : field.uppercase ? raw.toUpperCase() : raw;
}

function selectValue(key: string): string | null {
    const value = form[key];

    return value === null || value === undefined || value === ''
        ? null
        : String(value);
}

function setSelect(key: string, value: string | string[] | null): void {
    form[key] = Array.isArray(value) ? (value[0] ?? null) : value;
}

function numberValue(key: string): number | undefined {
    const value = form[key];

    return value === null || value === '' ? undefined : Number(value);
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    form.transform((data) => ({ ...data }));

    if (props.recordId !== null) {
        form.put(
            OrganizationStructureController.update.url({
                entity: props.entity,
                id: props.recordId,
            }),
            options,
        );
    } else {
        form.post(
            OrganizationStructureController.store.url(props.entity),
            options,
        );
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-lg">
            <SheetHeader class="border-b">
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription v-if="description">{{
                    description
                }}</SheetDescription>
            </SheetHeader>

            <form
                id="structure-form"
                class="grid flex-1 grid-cols-2 content-start gap-x-4 gap-y-5 overflow-y-auto p-5"
                @submit.prevent="submit"
            >
                <FormField
                    v-for="field in fields"
                    :key="field.key"
                    :label="field.label"
                    :required="field.required"
                    :description="field.hint"
                    :error="form.errors[field.key]"
                    :class="
                        field.full ||
                        field.type === 'switch' ||
                        field.type === 'textarea'
                            ? 'col-span-2'
                            : 'col-span-2 sm:col-span-1'
                    "
                    :orientation="
                        field.type === 'switch' ? 'horizontal' : 'vertical'
                    "
                    v-slot="{ id, describedBy, invalid }"
                >
                    <Switch
                        v-if="field.type === 'switch'"
                        :id="id"
                        :model-value="Boolean(form[field.key])"
                        @update:model-value="
                            (v: boolean) => (form[field.key] = v)
                        "
                    />
                    <SelectField
                        v-else-if="field.type === 'select'"
                        :id="id"
                        :options="field.options ?? []"
                        :placeholder="field.placeholder ?? 'Selecciona…'"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        clearable
                        :model-value="selectValue(field.key)"
                        @update:model-value="(v) => setSelect(field.key, v)"
                    />
                    <ComboboxField
                        v-else-if="field.type === 'combobox'"
                        :id="id"
                        :options="field.options ?? []"
                        :placeholder="field.placeholder ?? 'Selecciona…'"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        clearable
                        :model-value="selectValue(field.key)"
                        @update:model-value="(v) => setSelect(field.key, v)"
                    />
                    <NumberField
                        v-else-if="field.type === 'number'"
                        :id="id"
                        :min="field.min"
                        :max="field.max"
                        :step="field.step ?? 1"
                        :model-value="numberValue(field.key)"
                        @update:model-value="
                            (v: number | undefined) =>
                                (form[field.key] =
                                    v === undefined || Number.isNaN(v)
                                        ? null
                                        : v)
                        "
                    >
                        <NumberFieldContent>
                            <NumberFieldDecrement />
                            <NumberFieldInput :aria-describedby="describedBy" />
                            <NumberFieldIncrement />
                        </NumberFieldContent>
                    </NumberField>
                    <OptionalTimeField
                        v-else-if="field.type === 'time'"
                        :id="id"
                        :invalid="invalid"
                        :aria-label="field.label"
                        :model-value="
                            typeof form[field.key] === 'string'
                                ? (form[field.key] as string)
                                : null
                        "
                        @update:model-value="
                            (v: string | null) => (form[field.key] = v)
                        "
                    />
                    <Textarea
                        v-else-if="field.type === 'textarea'"
                        :id="id"
                        rows="2"
                        :placeholder="field.placeholder"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid || undefined"
                        :model-value="text(field.key)"
                        @update:model-value="(v) => setText(field, v)"
                    />
                    <Input
                        v-else
                        :id="id"
                        :placeholder="field.placeholder"
                        :inputmode="
                            field.key === 'latitude' ||
                            field.key === 'longitude'
                                ? 'decimal'
                                : undefined
                        "
                        :class="field.uppercase ? 'uppercase' : undefined"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid || undefined"
                        :model-value="text(field.key)"
                        @update:model-value="(v) => setText(field, v)"
                    />
                </FormField>

                <div v-if="hasCoordinates" class="col-span-2 -mt-2">
                    <a
                        v-if="mapUrl"
                        :href="mapUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-strong hover:underline"
                    >
                        <MapPin class="size-3.5" aria-hidden="true" />
                        Abrir en mapa
                    </a>
                    <p v-else class="text-xs text-muted-foreground">
                        Coordenadas decimales, p. ej. 19.4326 y -99.1332.
                    </p>
                </div>
            </form>

            <SheetFooter class="flex-row justify-end gap-2 border-t">
                <Button variant="outline" type="button" @click="open = false">
                    Cancelar
                </Button>
                <Button
                    type="submit"
                    form="structure-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else />
                    Guardar
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
