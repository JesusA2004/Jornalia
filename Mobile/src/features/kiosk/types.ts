/**
 * Tipos del kiosco de sucursal.
 *
 * El contrato real (B1) vive en `packages/contracts/src/api.ts` (`Kiosk*`); aquí
 * se declaran las formas INTERNAS que usa la pantalla y los adaptadores desde
 * el contrato (ver `api.ts`), para no acoplar la UI a cambios del servidor.
 */
import type {
  ApiError,
  KioskPunchReceipt as ContractKioskReceipt,
  DeviceInfo,
  IsoDateTime,
  KioskStatus,
  LocationEvidence,
  PunchType,
  Uuid,
} from '@/types/contracts';

export type { ContractKioskReceipt, KioskStatus };

export interface KioskInfo {
  id: number | string;
  name: string;
  branch: string | null;
  work_center: string | null;
  timezone: string;
  /** La organización exige verificación facial además del NIP. */
  requires_face: boolean;
  /** Permite guardar marcajes sin conexión (por omisión, sí). */
  allow_offline?: boolean;
}

/** POST /kiosk/activate */
export interface KioskActivateRequest {
  activation_code: string;
  device: DeviceInfo;
}

export interface KioskActivateResponse {
  token: string;
  token_expires_at?: IsoDateTime | null;
  kiosk: KioskInfo;
}

/** GET /kiosk/status */
export interface KioskStatusResponse {
  kiosk: KioskInfo;
  active: boolean;
  server_time: IsoDateTime;
}

/** POST /kiosk/punches (y elemento de POST /kiosk/sync). */
export interface KioskPunchRequest {
  employee_number: string;
  /** NIP de firma del colaborador. Sólo viaja por HTTPS y se guarda cifrado mientras está en cola. */
  pin: string;
  type: PunchType;
  client_uuid: Uuid;
  device_time: IsoDateTime;
  timezone: string;
  location: LocationEvidence | null;
  captured_offline: boolean;
  face_verification_id: Uuid | null;
}

export interface KioskPunchReceipt {
  receipt_code: string;
  punch: { uuid: Uuid; type: PunchType; server_time: IsoDateTime; status: string };
  /** Sólo el primer nombre de quien marcó, para confirmar. Nunca datos de terceros. */
  employee?: { first_name: string } | null;
}

export type KioskSyncItemResult =
  | { client_uuid: Uuid; result: 'created' | 'duplicate'; punch?: KioskPunchReceipt['punch'] }
  | { client_uuid: Uuid; result: 'rejected'; error?: ApiError };

export interface KioskSyncResponse {
  results: KioskSyncItemResult[];
}

/** Primer nombre (nunca datos completos ni de terceros en la pantalla compartida). */
export function firstNameOf(fullName: string | null | undefined): string | null {
  const first = (fullName ?? '').trim().split(/\s+/)[0];
  return first ? first : null;
}

/**
 * KioskStatus del contrato → información interna. El contrato no informa si se
 * exige rostro: `requires_face` se activa cuando el servidor rechaza un
 * marcaje por falta de verificación facial (ver punch.ts).
 */
export function toKioskInfo(s: KioskStatus, previous?: KioskInfo | null): KioskInfo {
  return {
    id: s.id,
    name: s.name,
    branch: s.branch?.name ?? null,
    work_center: null,
    timezone: s.organization?.timezone || previous?.timezone || 'America/Mexico_City',
    requires_face: previous?.requires_face ?? false,
    allow_offline: true,
  };
}

/** KioskPunchReceipt del contrato → recibo interno (folio = id del marcaje abreviado). */
export function toInternalReceipt(r: ContractKioskReceipt): KioskPunchReceipt {
  const first = firstNameOf(r.employee_name);
  return {
    receipt_code: r.id.replace(/-/g, '').slice(0, 10).toUpperCase(),
    punch: { uuid: r.id, type: r.type, server_time: r.server_time, status: r.status },
    employee: first ? { first_name: first } : null,
  };
}
