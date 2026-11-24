import type {
  AcknowledgeRequest,
  AppNotification,
  AttendancePolicy,
  CalendarResponse,
  ChallengeResponse,
  Device,
  DocumentSignResult,
  FaceChallenge,
  FaceVerificationResult,
  FaceVerificationStatus,
  HistoryDay,
  Incidence,
  IncidenceRequest,
  IncidenceType,
  LocationBatchRequest,
  LocationBatchResponse,
  LocationState,
  LoginRequest,
  LoginResponse,
  PaginationMeta,
  Profile,
  PunchReceipt,
  PunchRequest,
  PushTokenRequest,
  PendingDocument,
  Rectification,
  RectificationRequest,
  RefreshResponse,
  SignedDocument,
  SignaturePinRequest,
  SignRequest,
  SyncRequest,
  SyncResponse,
  TodayResponse,
  VacationBalance,
  VacationRequest,
  VacationRequestBody,
} from '@/types/contracts';

import type { ApiClient } from './client';

/** Archivo de evidencia (cámara, galería o documento). */
export interface EvidenceFile {
  uri: string;
  name: string;
  type: string;
  /** Tamaño en bytes si se conoce. */
  size?: number | null;
}

export function toForm(fields: Record<string, string | number | null>, evidence: EvidenceFile | EvidenceFile[], field = 'evidence'): FormData {
  const form = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    if (value !== null) form.append(key, String(value));
  }
  // React Native acepta { uri, name, type } como archivo en FormData.
  const files = Array.isArray(evidence) ? evidence : [evidence];
  for (const f of files) form.append(field, { uri: f.uri, name: f.name, type: f.type } as unknown as Blob);
  return form;
}

/** Señales de integridad del teléfono en la cabecera `X-Device-Integrity` (contrato). */
function integrityHeaders(signals: readonly string[]): { headers?: Record<string, string> } {
  return signals.length ? { headers: { 'X-Device-Integrity': signals.join(',') } } : {};
}

/** Funciones tipadas para cada endpoint del contrato v1 (ver packages/contracts). */
export function createEndpoints(api: ApiClient) {
  return {
    auth: {
      login: (body: LoginRequest) => api.post<LoginResponse>('/auth/login', body, { auth: false }).then((r) => r.data),
      refresh: () => api.post<RefreshResponse>('/auth/refresh').then((r) => r.data),
      logout: () => api.post<void>('/auth/logout').then(() => undefined),
    },
    profile: {
      get: () => api.get<Profile>('/profile').then((r) => r.data),
      acknowledge: (id: number, body: AcknowledgeRequest) =>
        api.post<void>(`/acknowledgements/${id}`, body).then(() => undefined),
      /** Alta o cambio del NIP de firma electrónica. */
      setSignaturePin: (body: SignaturePinRequest) => api.post<unknown>('/profile/signature-pin', body).then(() => undefined),
    },
    documents: {
      /** Convenio de registro y aviso de privacidad pendientes (bloqueantes). */
      pending: () => api.get<PendingDocument[]>('/documents/pending').then((r) => r.data),
      sign: (id: number, body: SignRequest) => api.post<DocumentSignResult>(`/documents/${id}/sign`, body).then((r) => r.data ?? null),
      /** Documentos ya firmados/aceptados (consulta). */
      signed: () => api.get<SignedDocument[]>('/documents').then((r) => r.data),
    },
    announcements: {
      /** Confirmación de lectura de un comunicado importante. */
      read: (id: number | string, deviceUuid: string) =>
        api.post<unknown>(`/announcements/${encodeURIComponent(String(id))}/read`, { device_uuid: deviceUuid }).then(() => undefined),
    },
    attendance: {
      today: () => api.get<TodayResponse>('/attendance/today').then((r) => r.data),
      policy: () => api.get<AttendancePolicy>('/attendance/policy').then((r) => r.data),
      challenge: () => api.post<ChallengeResponse>('/attendance/challenge').then((r) => r.data),
      punch: (body: PunchRequest, integrity: readonly string[] = []) =>
        api.post<PunchReceipt>('/attendance/punches', body, integrityHeaders(integrity)).then((r) => r),
      receipt: (uuid: string) => api.get<PunchReceipt>(`/attendance/punches/${encodeURIComponent(uuid)}`).then((r) => r.data),
      history: (params: { from?: string; to?: string; page?: number }) =>
        api.get<HistoryDay[], PaginationMeta>('/attendance/history', { query: params }),
    },
    sync: {
      punches: (body: SyncRequest, integrity: readonly string[] = []) =>
        api.post<SyncResponse>('/sync/punches', body, { timeoutMs: 45000, ...integrityHeaders(integrity) }).then((r) => r.data),
    },
    rectifications: {
      list: (page = 1, stage?: string) => api.get<Rectification[], PaginationMeta>('/rectifications', { query: { page, stage } }),
      show: (id: number) => api.get<Rectification>(`/rectifications/${id}`).then((r) => r.data),
      /** La evidencia es obligatoria (multipart `evidence[]`). */
      create: (body: RectificationRequest, evidence: EvidenceFile[]) =>
        api
          .request<Rectification>('POST', '/rectifications', { form: toForm({ ...body }, evidence, 'evidence[]'), timeoutMs: 60000 })
          .then((r) => r.data),
      sign: (id: number, body: SignRequest) => api.post<Rectification>(`/rectifications/${id}/sign`, body).then((r) => r.data),
      decline: (id: number, reason: string) => api.post<Rectification>(`/rectifications/${id}/decline`, { reason }).then((r) => r.data),
    },
    incidences: {
      types: () => api.get<IncidenceType[]>('/incidence-types').then((r) => r.data),
      list: (page = 1) => api.get<Incidence[], PaginationMeta>('/incidences', { query: { page } }),
      create: (body: IncidenceRequest, evidence?: EvidenceFile | null) =>
        (evidence
          ? api.request<Incidence>('POST', '/incidences', { form: toForm({ ...body }, evidence) })
          : api.post<Incidence>('/incidences', body)
        ).then((r) => r.data),
    },
    vacations: {
      balance: () => api.get<VacationBalance>('/vacations/balance').then((r) => r.data),
      list: (page = 1) => api.get<VacationRequest[], PaginationMeta>('/vacations', { query: { page } }),
      create: (body: VacationRequestBody) => api.post<VacationRequest>('/vacations', body).then((r) => r.data),
      cancel: (id: number) => api.post<VacationRequest>(`/vacations/${id}/cancel`).then((r) => r.data),
    },
    calendar: {
      month: (month: string) => api.get<CalendarResponse>('/calendar', { query: { month } }).then((r) => r.data),
    },
    notifications: {
      list: (page = 1) => api.get<AppNotification[], PaginationMeta>('/notifications', { query: { page } }),
      read: (id: string) => api.post<void>(`/notifications/${encodeURIComponent(id)}/read`).then(() => undefined),
      readAll: () => api.post<void>('/notifications/read-all').then(() => undefined),
    },
    devices: {
      list: () => api.get<Device[]>('/devices').then((r) => r.data),
      pushToken: (body: PushTokenRequest) => api.post<void>('/devices/push-token', body).then(() => undefined),
    },
    location: {
      points: (body: LocationBatchRequest) => api.post<LocationBatchResponse>('/location/points', body).then((r) => r.data),
      state: () => api.get<LocationState>('/location/state').then((r) => r.data),
    },
    faceVerification: {
      status: () => api.get<FaceVerificationStatus>('/face-verification/status').then((r) => r.data),
      challenge: () => api.post<FaceChallenge>('/face-verification/challenge').then((r) => r.data),
      verify: (form: FormData) =>
        api.request<FaceVerificationResult>('POST', '/face-verification/verify', { form, timeoutMs: 60000 }).then((r) => r.data),
    },
  };
}

export type Endpoints = ReturnType<typeof createEndpoints>;
