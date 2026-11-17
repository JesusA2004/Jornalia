import type { Directive } from 'vue';
import type { NotificationSummary, OrganizationSummary } from '@/types/app';
import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth & {
                /** Permisos efectivos del usuario en la organización activa. */
                permissions?: string[];
                /** Organización (empresa) activa. */
                organization?: OrganizationSummary | null;
                /** Organizaciones a las que el usuario tiene acceso. */
                organizations?: OrganizationSummary[];
                [key: string]: unknown;
            };
            sidebarOpen: boolean;
            /** Notificaciones sin leer (campana del encabezado). */
            notifications_unread?: number;
            /** Últimas notificaciones (opcional) para la campana. */
            notifications?: NotificationSummary[];
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
