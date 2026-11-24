import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { APP_TITLE } from '@/lib/brand';
import { initializeFlashToast } from '@/lib/flashToast';

const envName = import.meta.env.VITE_APP_NAME;
// Nunca mostrar el nombre por defecto del framework.
const appName = envName && envName !== 'Laravel' ? String(envName) : APP_TITLE;

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        // Sobrescrito por el token --progress en app.css (tema claro/oscuro).
        color: '#AF8B51',
        delay: 150,
    },
});

// Aplica el tema claro/oscuro al cargar.
initializeTheme();

// Escucha avisos flash del servidor (toasts).
initializeFlashToast();
