import type {
  AcknowledgeRequest,
  AppNotification,
  AttendancePolicy,
  CalendarResponse,
  ChallengeResponse,
  Device,
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
  Rectification,
  RectificationRequest,
  RefreshResponse,
  SyncRequest,
  SyncResponse,
  TodayResponse,
  VacationBalance,
  VacationRequest,
  VacationRequestBody,
} from '@/types/contracts';

import type { ApiClient } from './client';

/** Archivo de evidencia capturado con la cámara. */
export interface EvidenceFile {
  uri: string;
  name: string;
  type: string;
}

function toForm(fields: Record<string, string | number | null>, evidence: EvidenceFile): FormData {
  const form = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    if (value !== null) form.append(key, String(value));
  }
  // React Native acepta { uri, name, type } como archivo en FormData.
  form.append('evidence', evidence as unknown as Blob);
  return form;
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
    },
    attendance: {
      today: () => api.get<TodayResponse>('/attendance/today').then((r) => r.data),
      policy: () => api.get<AttendancePolicy>('/attendance/policy').then((r) => r.data),
      challenge: () => api.post<ChallengeResponse>('/attendance/challenge').then((r) => r.data),
      punch: (body: PunchRequest) => api.post<PunchReceipt>('/attendance/punches', body).then((r) => r),
      receipt: (uuid: string) => api.get<PunchReceipt>(`/attendance/punches/${encodeURIComponent(uuid)}`).then((r) => r.data),
      history: (params: { from?: string; to?: string; page?: number }) =>
        api.get<HistoryDay[], PaginationMeta>('/attendance/history', { query: params }),
    },
    sync: {
      punches: (body: SyncRequest) => api.post<SyncResponse>('/sync/punches', body, { timeoutMs: 45000 }).then((r) => r.data),
    },
    rectifications: {
      list: (page = 1) => api.get<Rectification[], PaginationMeta>('/rectifications', { query: { page } }),
      create: (body: RectificationRequest, evidence?: EvidenceFile | null) =>
        (evidence
          ? api.request<Rectification>('POST', '/rectifications', { form: toForm({ ...body }, evidence) })
          : api.post<Rectification>('/rectifications', body)
        ).then((r) => r.data),
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
