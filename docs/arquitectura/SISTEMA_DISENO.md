# Sistema de diseño — MR LANA Asistencias (web)

> **Identidad OFICIAL** según `docs/identidad/GUIA_MARCA_DIGITAL.md` (Manual de
> identidad MR LANA 2026). Verde `#A0E04E`, cian `#82DBE8`, negro/blanco,
> degradado `#A0E04E → #82DBE8`, superficie oscura `#12302B`. Tipografías
> secundarias oficiales: **Montserrat** (interfaz) y **Baloo 2** (títulos y
> cifras), vía `bunny()` en `vite.config.ts`. El logotipo
> (`public/brand/logo.svg`) se usa tal cual: sin recolorear, rotar, sombras ni
> contornos.

## 1. Principios

- **Todo control es shadcn-vue + Tailwind 4** (`resources/js/components/ui/*`).
  Prohibido `<select>` nativo, `<input type="date">`, librerías de calendario o
  de gráficas ajenas a shadcn-vue (las gráficas usan el `chart` de shadcn-vue
  sobre `@unovis/vue`).
- Las páginas usan **clases semánticas** (`bg-card`, `text-muted-foreground`,
  `bg-success-soft`, `text-danger-soft-foreground`, `shadow-elev-1`…), nunca
  colores crudos (`blue-600`, `red-50`).
- Cifras, horas e importes con cifras tabulares (`numeric` o `tabular-nums`;
  `table`, `time` y `[data-numeric]` lo aplican por defecto).
- **Poco texto** (AGENTS.md regla 12): títulos cortos y descripciones de una
  línea. Las explicaciones van en la ayuda "?" (`PageHeader :help` →
  `HelpSheet`).
- **Nada se elimina** (regla 11): acciones "Desactivar", nunca "Eliminar".
- Botón primario: verde de marca con texto negro. Texto/íconos verdes sobre
  blanco usan `text-brand-strong` (#4F8A16, AA); cian sobre blanco
  `text-cyan-strong` (#137C8B).
- El degradado (`bg-brand-gradient`) se usa con mesura: banda superior del
  login, `WelcomeBanner`, indicador activo de la barra lateral, ayuda.
- El personaje (`/brand/personaje-*.webp`, copias optimizadas de los PNG
  oficiales) sólo en login, estados vacíos principales (`EmptyState :mascot`)
  y bienvenida (`WelcomeBanner`).
- Móvil: objetivos ≥ 40 px; `DataTable` se convierte en tarjetas (< md);
  la barra lateral es un panel deslizable.
- Densidad cómoda: filas de tabla de ~56 px, tarjetas con `p-5`, espacio
  vertical `gap-6` entre bloques.
- Modo claro y oscuro (clase `.dark` en `<html>`; preferencia en
  `useAppearance`).
- Textos de interfaz en español (es-MX).

## 2. Tokens (`Web/resources/css/app.css`)

Definidos en `:root` (claro) y `.dark` (oscuro) y expuestos a Tailwind en
`@theme inline`.

| Grupo | Tokens (variable CSS → clase) |
|---|---|
| Lienzo y texto | `--background`, `--foreground`, `--muted`, `--muted-foreground`, `--subtle-foreground` |
| Superficies en capas | `--surface-1` (= `--card`), `--surface-2`, `--surface-3`, `--popover` → `bg-surface-2`… |
| Primario (= verde de marca) | `--primary` (#A0E04E), `--primary-foreground` (negro), `--primary-soft`, `--primary-soft-foreground` |
| Marca verde | `--brand`, `--brand-foreground`, `--brand-strong` (#4F8A16), `--brand-ink` (#1F3B0B), `--brand-soft` (#EEF8E0), `--brand-deep` (#12302B) |
| Marca cian | `--cyan` (#82DBE8), `--cyan-strong` (#137C8B), `--cyan-soft`, `--cyan-soft-foreground` |
| Degradado | `--brand-gradient` → utilidades `bg-brand-gradient`, `text-brand-gradient` |
| Panel de marca (login) | `--brand-panel`, `--brand-panel-foreground` |
| Estados | `--success`, `--warning`, `--danger`, `--info` + `-foreground`, `-soft`, `-soft-foreground`; `--neutral-soft(-foreground)`; `--destructive` = `--danger` |
| Bordes | `--border`, `--border-strong`, `--input`, `--ring` |
| Gráficas | `--chart-1` … `--chart-6` (categórico, en orden de uso) |
| Tipografía | `font-sans` (Montserrat), `font-display` (Baloo 2; `h1`, `h2` y `[data-display]` por defecto) |
| Sidebar (verde profundo) | `--sidebar`, `--sidebar-foreground`, `--sidebar-primary(-foreground)`, `--sidebar-accent(-foreground)`, `--sidebar-border`, `--sidebar-ring` |
| Radios | `--radius` (0.625rem) → `rounded-xs/sm/md/lg/xl/2xl` |
| Elevación | `--elev-1/2/3` → `shadow-elev-1`, `shadow-elev-2`, `shadow-elev-3` |
| Movimiento | `--duration-fast/base/slow`, `ease-standard`, `ease-emphasized`, utilidad `transition-standard` |
| Iconos | `--icon-xs/sm/md/lg/xl` → utilidades `icon-xs` … `icon-xl` |
| Navegación | `--progress` (barra de carga de Inertia) |

Notas:

- `accent` es el token de shadcn para **hover/selección** (no es el color de
  marca). El acento de marca es `brand`.
- Pares `*-soft` / `*-soft-foreground` pensados para insignias y avisos con
  contraste ≥ 4.5:1 (AA). Si cambias la paleta, vuelve a verificar contraste.
- Utilidades extra: `numeric`, `transition-standard`, `pattern-grid` (retícula
  sutil para paneles de marca).

## 3. Tipografía

- **Montserrat** 400–700 (interfaz) y **Baloo 2** 500–800 (títulos y cifras
  destacadas), ambas OFL, autoalojadas en el build por el plugin de fuentes.
- La corporativa **Museo Sans Rounded** requiere licencia: si se adquiere,
  colocar los archivos en `Web/public/fonts/` y cambiar `--font-sans` /
  `--font-display` en `app.css` (y el plugin en `vite.config.ts`).
- Horas y cifras de tablas: numerales tabulares (`numeric`).

## 4. Recursos de marca (`Web/public/brand/`)

| Archivo | Uso |
|---|---|
| `logo.svg` | Logotipo oficial (barra lateral, login, encabezados de reportes). No modificar. |
| `icon-512.png`, `favicon-64.png` | Ícono oficial (logotipo sobre degradado): barra lateral colapsada, favicon PNG. |
| `personaje-saludo.png/.webp`, `personaje-presenta.png/.webp` | Personaje; la web usa las copias `.webp` (640 px de alto). |
| `/favicon.ico`, `/apple-touch-icon.png` | Íconos del navegador. |

Rutas centralizadas en `resources/js/lib/brand.ts`.

## 5. Estructura de la interfaz

- Layout de aplicación: `layouts/app/AppSidebarLayout.vue`
  - Sidebar colapsable (`components/AppSidebar.vue`, variante `inset`,
    modo icono) con grupos de `lib/navigation.ts` filtrados por
    `page.props.auth.permissions`.
  - Encabezado (`components/AppSidebarHeader.vue`): migas, buscador ⌘K
    (`components/layout/CommandMenu.vue`), selector de empresa
    (`OrganizationSwitcher`), campana (`NotificationBell`), tema
    (`ThemeToggle`). Menú de usuario en el pie del sidebar.
- Migas: cada página puede declarar
  `defineOptions({ layout: { breadcrumbs: [{ title, href? }] } })`; si no, se
  derivan de la navegación (Grupo › Módulo).
- Autenticación: `layouts/auth/AuthSplitLayout.vue` (panel de marca con
  patrón geométrico CSS, sin imágenes ni métricas).
- Cuenta: `layouts/settings/Layout.vue` (Perfil, Seguridad, Apariencia).

## 6. Componentes de producto

Viven en `resources/js/components/app/` (barrel `@/components/app`). Tipos
compartidos en `resources/js/types/{app,components}.ts`. Helpers en
`resources/js/lib/{format,status,pagination,chart,navigation,brand}.ts` y
composables en `resources/js/composables/{useFilters,useFormatters,usePermissions,useNavigation}.ts`.

Patrón de listado recomendado:

```vue
<script setup lang="ts">
import { DataTable, FilterBar, PageHeader, SearchInput, SelectField, StatusBadge } from '@/components/app';
import { useFilters } from '@/composables/useFilters';
import { toPaginationMeta } from '@/lib/pagination';

const props = defineProps<{ rectifications: LaravelPaginator<Rectification>; filters: { search?: string; status?: string; sort?: string; direction?: 'asc' | 'desc' } }>();
const { filters, activeCount, reset } = useFilters(
    { search: props.filters.search ?? '', status: props.filters.status ?? null },
    { only: ['rectifications', 'filters'] },
);
</script>

<template>
    <PageHeader title="Rectificaciones" description="Solicitudes de corrección de marcajes." />
    <DataTable
        :rows="rectifications.data"
        :columns="columns"
        :pagination="toPaginationMeta(rectifications)"
        :sort="props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction ?? 'asc' } : null"
        :only="['rectifications']"
    >
        <template #toolbar>
            <FilterBar :active-count="activeCount" @reset="reset">
                <template #search><SearchInput v-model="filters.search" /></template>
                <SelectField v-model="filters.status" :options="statusOptions" prefix="Estado:" clearable placeholder="Todos" />
            </FilterBar>
        </template>
        <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
    </DataTable>
</template>
```

Contrato con el backend para listados: parámetros `page`, `per_page`,
`sort`, `direction` (`asc|desc`) y los filtros propios del módulo.
