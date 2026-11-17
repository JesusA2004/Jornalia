import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Permiso requerido (`modulo.accion`). Si falta, el elemento es público. */
    permission?: string;
    /** Palabras clave adicionales para el buscador ⌘K. */
    keywords?: string[];
    /** Descripción corta (buscador ⌘K). */
    description?: string;
};

export type NavGroup = {
    title: string;
    items: NavItem[];
};
