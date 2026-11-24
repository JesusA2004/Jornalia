import { ApiClient, type ApiClientConfig } from '@/api/client';
import type { ApiErrorCode, FaceChallenge, FaceVerificationResult, KioskActivation, KioskSyncResponse as ContractSyncResponse } from '@/types/contracts';

import {
  type ContractKioskReceipt,
  type KioskActivateRequest,
  type KioskActivateResponse,
  type KioskPunchReceipt,
  type KioskPunchRequest,
  type KioskStatus,
  type KioskStatusResponse,
  type KioskSyncResponse,
  toInternalReceipt,
  toKioskInfo,
} from './types';

/**
 * Endpoints del kiosco. Usan un cliente propio autenticado con el token del
 * KIOSCO (nunca con la sesión de un colaborador).
 */
export function createKioskApi(api: ApiClient) {
  return {
    activate: (body: KioskActivateRequest): Promise<KioskActivateResponse> =>
      api
        .post<KioskActivation>('/kiosk/activate', body, { auth: false })
        .then((r) => ({ token: r.data.token, kiosk: toKioskInfo(r.data.kiosk) })),
    /** `active` = estado «active» del kiosco en el servidor. */
    status: (): Promise<KioskStatusResponse> =>
      api
        .get<KioskStatus>('/kiosk/status')
        .then((r) => ({ kiosk: toKioskInfo(r.data), active: r.data.status === 'active', server_time: r.data.server_time })),
    punch: (body: KioskPunchRequest): Promise<{ status: number; data: KioskPunchReceipt }> =>
      api.post<ContractKioskReceipt>('/kiosk/punches', body).then((r) => ({ status: r.status, data: toInternalReceipt(r.data) })),
    sync: (punches: KioskPunchRequest[]): Promise<KioskSyncResponse> =>
      api.post<ContractSyncResponse>('/kiosk/sync', { punches }, { timeoutMs: 45000 }).then((r) => ({
        results: r.data.results.map((x) =>
          x.result === 'rejected'
            ? {
                client_uuid: x.client_uuid,
                result: 'rejected' as const,
                error: x.error ? { message: x.error.message, code: x.error.code as ApiErrorCode, errors: x.error.errors } : undefined,
              }
            : { client_uuid: x.client_uuid, result: x.result, punch: x.punch ? toInternalReceipt(x.punch).punch : undefined },
        ),
      })),
    /** El colaborador se identifica con número + NIP también para el reto facial. */
    faceChallenge: (employeeNumber: string, pin: string) =>
      api.post<FaceChallenge>('/kiosk/face/challenge', { employee_number: employeeNumber, pin }).then((r) => r.data),
    faceVerify: (form: FormData) =>
      api.request<FaceVerificationResult>('POST', '/kiosk/face/verify', { form, timeoutMs: 60000 }).then((r) => r.data),
  };
}

export type KioskApi = ReturnType<typeof createKioskApi>;

/** Cliente del kiosco con su propio proveedor de token. */
export function createKioskClient(config: Pick<ApiClientConfig, 'baseUrl' | 'getToken' | 'onAuthFailure' | 'fetchImpl'>): KioskApi {
  return createKioskApi(new ApiClient(config));
}
