import type {
  ApprovalStatus,
  AuthMethod,
  DeviceStatus,
  LocationMode,
  PunchAnomaly,
  PunchType,
  Rectification,
  WorkdayStatus,
} from '@/types/contracts';

const LOCALE = 'es-MX';

/** Minutos enteros → "7 h 05 min" / "45 min". */
export function formatMinutes(total: number): string {
  const m = Math.max(0, Math.round(total));
  const h = Math.floor(m / 60);
  const r = m % 60;
  if (h === 0) return `${r} min`;
  return `${h} h ${String(r).padStart(2, '0')} min`;
}

/** Minutos → "7:05" (formato compacto para métricas). */
export function formatHoursCompact(total: number): string {
  const m = Math.max(0, Math.round(total));
  return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;
}

function safeTz(tz: string | undefined): string | undefined {
  if (!tz) return undefined;
  try {
    new Intl.DateTimeFormat(LOCALE, { timeZone: tz });
    return tz;
  } catch {
    return undefined;
  }
}

/** Hora local de la zona indicada: "08:03". */
export function formatTime(iso: string | Date, tz?: string, seconds = false): string {
  const d = typeof iso === 'string' ? new Date(iso) : iso;
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(LOCALE, {
    hour: '2-digit',
    minute: '2-digit',
    second: seconds ? '2-digit' : undefined,
    hour12: false,
    timeZone: safeTz(tz),
  }).format(d);
}

/** Fecha y hora: "17 nov 2026, 08:03". */
export function formatDateTime(iso: string, tz?: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(LOCALE, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: safeTz(tz),
  }).format(d);
}

/** Fecha civil (YYYY-MM-DD) → Date a mediodía UTC, para formatear sin corrimientos. */
function civil(date: string): Date {
  return new Date(`${date}T12:00:00Z`);
}

/** "lunes, 17 de noviembre" */
export function formatCivilDateLong(date: string): string {
  const s = new Intl.DateTimeFormat(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(civil(date));
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** "17 nov 2026" */
export function formatCivilDate(date: string): string {
  return new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(civil(date));
}

/** "lun" */
export function formatWeekdayShort(date: string): string {
  return new Intl.DateTimeFormat(LOCALE, { weekday: 'short', timeZone: 'UTC' }).format(civil(date)).replace('.', '');
}

/** Fecha de hoy en una zona: "martes, 17 de noviembre de 2026". */
export function formatTodayLong(now: Date, tz?: string): string {
  const s = new Intl.DateTimeFormat(LOCALE, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: safeTz(tz),
  }).format(now);
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** Date local → YYYY-MM-DD (según los componentes locales del Date). */
export function toCivilDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Fecha civil (YYYY-MM-DD) del instante dado en la zona indicada. */
export function civilDateInZone(d: Date, tz?: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: safeTz(tz) }).format(d);
  return parts.slice(0, 10);
}

export function addDays(date: string, days: number): string {
  const d = civil(date);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Saludo según la hora local. */
export function greeting(now: Date, tz?: string): string {
  const h = Number(new Intl.DateTimeFormat('en-US', { hour: 'numeric', hour12: false, timeZone: safeTz(tz) }).format(now)) % 24;
  if (h < 12) return 'Buenos días';
  if (h < 19) return 'Buenas tardes';
  return 'Buenas noches';
}

/* ─────────────────────────── Etiquetas ─────────────────────────── */

export const PUNCH_TYPE_LABEL: Record<PunchType, string> = {
  clock_in: 'Entrada',
  clock_out: 'Salida',
  break_start: 'Inicio de descanso o comida',
  break_end: 'Fin de descanso o comida',
};

/** Etiqueta corta (botones y filas). */
export const PUNCH_SHORT_LABEL: Record<PunchType, string> = {
  clock_in: 'Entrada',
  clock_out: 'Salida',
  break_start: 'Descanso',
  break_end: 'Regreso',
};

/** Confirmación tras marcar. */
export const PUNCH_DONE_LABEL: Record<PunchType, string> = {
  clock_in: '¡Entrada registrada!',
  clock_out: '¡Salida registrada!',
  break_start: '¡Descanso registrado!',
  break_end: '¡Regreso registrado!',
};

export const PUNCH_ACTION_LABEL: Record<PunchType, string> = {
  clock_in: 'Registrar entrada',
  clock_out: 'Registrar salida',
  break_start: 'Salir a descanso o comida',
  break_end: 'Regresar de descanso',
};

export const WORKDAY_STATUS_LABEL: Record<WorkdayStatus, string> = {
  not_started: 'Sin iniciar',
  working: 'En jornada',
  on_break: 'En descanso',
  finished: 'Jornada concluida',
  rest_day: 'Día de descanso',
  holiday: 'Día festivo',
  on_leave: 'Ausencia justificada',
  absent: 'Falta',
  incomplete: 'Omisión de salida',
};

export const APPROVAL_LABEL: Record<ApprovalStatus, string> = {
  pending: 'En revisión',
  approved: 'Aprobada',
  rejected: 'Rechazada',
  cancelled: 'Cancelada',
};

export function approvalTone(s: ApprovalStatus): 'warning' | 'success' | 'danger' | 'neutral' {
  return s === 'pending' ? 'warning' : s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : 'neutral';
}

export const ANOMALY_LABEL: Record<PunchAnomaly, string> = {
  clock_skew: 'Hora del teléfono desfasada',
  late_sync: 'Sincronización tardía',
  mocked_location: 'Ubicación simulada',
  outside_geofence: 'Fuera de zona autorizada',
  low_accuracy: 'Ubicación imprecisa',
  no_location: 'Sin ubicación',
  out_of_sequence: 'Fuera de secuencia',
  outside_schedule: 'Fuera de horario',
  rest_day: 'Día de descanso',
  holiday: 'Día festivo',
  offline_not_allowed: 'Sin conexión no permitido',
  missing_clock_out: 'Omisión de salida',
  missing_clock_in: 'Omisión de entrada',
  auto_clock_out: 'Generado por sistema (histórico)',
};

export function anomalyLabel(a: string): string {
  return (ANOMALY_LABEL as Record<string, string>)[a] ?? a;
}

export const RECTIFICATION_KIND_LABEL: Record<Rectification['kind'], string> = {
  missing_punch: 'Marcaje omitido',
  wrong_type: 'Tipo de marcaje incorrecto',
  time_clarification: 'Aclaración de hora',
  other: 'Otro',
};

export const AUTH_METHOD_LABEL: Record<AuthMethod, string> = {
  device_biometric: 'Biometría del dispositivo',
  device_credential: 'Bloqueo del dispositivo',
  face_verification: 'Verificación facial',
  web_session: 'Sesión web',
  system: 'Generado por sistema (histórico)',
};

export const LOCATION_MODE_LABEL: Record<LocationMode, string> = {
  free: 'Sin ubicación',
  record: 'Se registra si está disponible',
  required: 'Ubicación obligatoria',
  geofence: 'Dentro de zona autorizada',
  device_only: 'Sólo dispositivo autorizado',
};

export const DEVICE_STATUS_LABEL: Record<DeviceStatus, string> = {
  pending: 'Pendiente de aprobación',
  approved: 'Autorizado',
  revoked: 'Revocado',
};

export function workdayTone(s: WorkdayStatus): 'success' | 'warning' | 'info' | 'neutral' | 'danger' {
  if (s === 'working') return 'success';
  if (s === 'on_break') return 'warning';
  if (s === 'finished') return 'info';
  if (s === 'absent') return 'danger';
  if (s === 'incomplete') return 'warning';
  return 'neutral';
}
