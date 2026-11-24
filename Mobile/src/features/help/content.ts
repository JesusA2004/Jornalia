import type { IconName } from '@/components/ui/Icon';

export interface HelpStep {
  icon: IconName;
  text: string;
}

export interface HelpTopic {
  title: string;
  steps: HelpStep[];
}

export type HelpTopicKey =
  | 'inicio'
  | 'historial'
  | 'perfil'
  | 'solicitudes'
  | 'ubicacion'
  | 'sincronizacion'
  | 'firma'
  | 'nip'
  | 'convenio'
  | 'documentos'
  | 'avisos'
  | 'comprobante';

/** Guía de uso: pasos breves y visuales por pantalla. Único lugar para explicaciones. */
export const HELP: Record<HelpTopicKey, HelpTopic> = {
  inicio: {
    title: 'Marcar asistencia',
    steps: [
      { icon: 'fingerprint', text: 'Toca el botón grande y pon tu huella.' },
      { icon: 'checkCircle', text: 'Palomita verde: quedó registrado con su folio.' },
      { icon: 'pending', text: 'Reloj ámbar: sin internet, se guardó y se envía solo.' },
      { icon: 'meal', text: 'Para descanso o comida usa el botón de abajo.' },
      { icon: 'block', text: 'El sistema nunca marca por ti: si olvidas tu salida, pide una corrección.' },
      { icon: 'bell', text: 'La campana guarda tus avisos y el acuse de cada marcaje.' },
    ],
  },
  ubicacion: {
    title: 'Ubicación de trabajo',
    steps: [
      { icon: 'location', text: 'Según tu puesto: sólo al marcar o durante tu jornada.' },
      { icon: 'play', text: 'El seguimiento inicia con tu entrada.' },
      { icon: 'pause', text: 'Se pausa en tu descanso o comida.' },
      { icon: 'stop', text: 'Se detiene con tu salida y al cerrar sesión.' },
      { icon: 'eye', text: 'Mientras se comparte verás el aviso arriba.' },
    ],
  },
  historial: {
    title: 'Tu historial',
    steps: [
      { icon: 'calendar', text: 'Elige 7, 30 o 90 días.' },
      { icon: 'lock', text: 'Los marcajes originales nunca cambian.' },
      { icon: 'edit', text: '¿Algo mal? Toca «Corregir este día».' },
      { icon: 'receipt', text: 'Toca un marcaje para ver su comprobante.' },
    ],
  },
  solicitudes: {
    title: 'Solicitudes',
    steps: [
      { icon: 'edit', text: 'Corrección: si olvidaste marcar o marcaste mal.' },
      { icon: 'attach', text: 'Adjunta evidencia: foto, galería o documento.' },
      { icon: 'pending', text: 'Tu jefe la autoriza y después tú la firmas.' },
      { icon: 'incidence', text: 'Incidencia: permiso, falta o incapacidad.' },
      { icon: 'vacation', text: 'Vacaciones: revisa tus días y pídelos.' },
    ],
  },
  firma: {
    title: 'Firmar una corrección',
    steps: [
      { icon: 'compare', text: 'Compara el registro original con la propuesta.' },
      { icon: 'signature', text: 'Firma con tu huella o tu NIP.' },
      { icon: 'timer', text: 'Tienes un plazo; si vence, el día queda como omisión.' },
      { icon: 'block', text: '¿No estás de acuerdo? Recházala con un motivo.' },
      { icon: 'lock', text: 'Sin tu firma no se aplica ningún ajuste.' },
    ],
  },
  nip: {
    title: 'NIP de firma',
    steps: [
      { icon: 'lock', text: 'Crea un NIP de 6 números que sólo tú conozcas.' },
      { icon: 'signature', text: 'Lo usas para firmar documentos y correcciones.' },
      { icon: 'store', text: 'También sirve en el kiosco de tu sucursal.' },
      { icon: 'warning', text: '5 intentos fallidos lo bloquean 15 minutos.' },
    ],
  },
  convenio: {
    title: 'Convenio y aviso',
    steps: [
      { icon: 'document', text: 'Lee el documento completo.' },
      { icon: 'check', text: 'Marca «Leí el documento completo».' },
      { icon: 'signature', text: 'Firma con tu huella o tu NIP.' },
      { icon: 'fingerprint', text: 'Después ya puedes marcar.' },
    ],
  },
  documentos: {
    title: 'Tus documentos',
    steps: [
      { icon: 'signature', text: 'Aquí ves lo que firmaste o aceptaste.' },
      { icon: 'seal', text: 'Cada firma tiene fecha, hora y sello.' },
      { icon: 'document', text: 'Toca «Leer» para revisar el texto.' },
    ],
  },
  avisos: {
    title: 'Avisos y acuses',
    steps: [
      { icon: 'megaphone', text: 'Avisos: comunicados y estado de tus solicitudes.' },
      { icon: 'check', text: 'Los importantes piden «Confirmar lectura».' },
      { icon: 'receipt', text: 'Acuses: cada entrada y salida con su hora.' },
      { icon: 'pending', text: '«Pendiente» = aún no llega al servidor.' },
    ],
  },
  comprobante: {
    title: 'Comprobante',
    steps: [
      { icon: 'clock', text: 'Hora oficial: cuando llegó al servidor.' },
      { icon: 'phone', text: 'Hora del teléfono: cuando lo capturaste.' },
      { icon: 'seal', text: 'Folio y sello prueban que no cambió.' },
    ],
  },
  sincronizacion: {
    title: 'Envío de marcajes',
    steps: [
      { icon: 'cloudOff', text: 'Sin internet tus marcajes se guardan en el teléfono.' },
      { icon: 'sync', text: 'Se envían solos al volver la señal.' },
      { icon: 'cloudDone', text: 'Nube con palomita: ya está registrado.' },
      { icon: 'warning', text: '«Requiere revisión»: pide una corrección.' },
    ],
  },
  perfil: {
    title: 'Tu perfil',
    steps: [
      { icon: 'requests', text: 'Solicitudes: correcciones, incidencias y vacaciones.' },
      { icon: 'lock', text: 'NIP de firma: créalo o cámbialo.' },
      { icon: 'seal', text: 'Documentos: lo que firmaste.' },
      { icon: 'logout', text: 'Cerrar sesión detiene la ubicación.' },
    ],
  },
};
