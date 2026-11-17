import type { IconName } from '@/components/ui/Icon';

export interface HelpStep {
  icon: IconName;
  text: string;
}

export interface HelpTopic {
  title: string;
  steps: HelpStep[];
}

/** Guía de uso: pasos breves y visuales por módulo. Único lugar para explicaciones. */
export const HELP: Record<'inicio' | 'historial' | 'perfil' | 'solicitudes' | 'ubicacion' | 'sincronizacion', HelpTopic> = {
  inicio: {
    title: 'Marcar asistencia',
    steps: [
      { icon: 'fingerprint', text: 'Toca el botón verde grande.' },
      { icon: 'face', text: 'Pon tu huella o mira la cámara.' },
      { icon: 'checkCircle', text: 'Verás una palomita verde: ¡listo!' },
      { icon: 'pending', text: 'Sin internet se guarda y se envía solo después.' },
      { icon: 'coffee', text: 'Para tu descanso usa el botón de abajo.' },
    ],
  },
  ubicacion: {
    title: 'Ubicación de trabajo',
    steps: [
      { icon: 'location', text: 'Sólo se comparte durante tu turno.' },
      { icon: 'bell', text: 'Verás el aviso «Ubicación de trabajo activa».' },
      { icon: 'clock', text: 'Fuera de turno queda en pausa.' },
      { icon: 'logout', text: 'Al cerrar sesión se detiene.' },
    ],
  },
  historial: {
    title: 'Tu historial',
    steps: [
      { icon: 'calendar', text: 'Elige 7, 30 o 90 días.' },
      { icon: 'lock', text: 'Los marcajes originales no cambian.' },
      { icon: 'edit', text: '¿Algo mal? Toca «Corregir este día».' },
      { icon: 'receipt', text: 'Toca un marcaje para ver su comprobante.' },
    ],
  },
  solicitudes: {
    title: 'Solicitudes',
    steps: [
      { icon: 'edit', text: 'Corrección: si olvidaste marcar o marcaste mal.' },
      { icon: 'incidence', text: 'Incidencia: permiso, falta o incapacidad.' },
      { icon: 'vacation', text: 'Vacaciones: revisa tus días y pídelos.' },
      { icon: 'pending', text: 'Tu jefe la revisa; verás el estado aquí.' },
    ],
  },
  sincronizacion: {
    title: 'Envío de marcajes',
    steps: [
      { icon: 'cloudOff', text: 'Sin internet tus marcajes se guardan.' },
      { icon: 'sync', text: 'Se envían solos al volver la señal.' },
      { icon: 'cloudDone', text: 'Nube con palomita: ya llegó.' },
    ],
  },
  perfil: {
    title: 'Tu perfil',
    steps: [
      { icon: 'requests', text: 'Solicitudes: correcciones y vacaciones.' },
      { icon: 'seal', text: 'Documentos: léelos y confirma.' },
      { icon: 'phone', text: 'Dispositivos: tus teléfonos autorizados.' },
      { icon: 'logout', text: 'Cerrar sesión detiene la ubicación.' },
    ],
  },
};
