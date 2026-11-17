import { ref } from 'vue';
import type { Ref } from 'vue';

const open = ref(false);

/** Estado global del buscador ⌘K (compartido entre encabezado y diálogo). */
export function useCommandMenu(): {
    open: Ref<boolean>;
    show: () => void;
    toggle: () => void;
} {
    return {
        open,
        show: () => {
            open.value = true;
        },
        toggle: () => {
            open.value = !open.value;
        },
    };
}
