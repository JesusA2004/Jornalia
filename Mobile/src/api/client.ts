import type { ApiEnvelope } from '@/types/contracts';

import { ApiRequestError, NetworkError, toApiError } from './errors';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
  query?: Record<string, string | number | undefined | null>;
  body?: unknown;
  /** Cuerpo multipart (evidencias). Si se indica, `body` se ignora. */
  form?: FormData;
  /** Cabeceras adicionales (p. ej. X-Device-Integrity). */
  headers?: Record<string, string>;
  /** false para endpoints públicos (login, health). */
  auth?: boolean;
  timeoutMs?: number;
  signal?: AbortSignal;
}

export interface ApiClientConfig {
  baseUrl: string;
  getToken: () => string | null | Promise<string | null>;
  /**
   * Se invoca ante 401 o ante códigos de dispositivo (device_pending/revoked/unknown)
   * en endpoints autenticados. El error se propaga igualmente al llamador.
   */
  onAuthFailure?: (error: ApiRequestError) => void;
  fetchImpl?: typeof fetch;
  defaultTimeoutMs?: number;
}

export interface ApiResult<T, M = undefined> {
  status: number;
  data: T;
  meta: M | undefined;
}

const DEVICE_CODES = new Set(['device_pending', 'device_revoked', 'device_unknown']);

export function buildUrl(baseUrl: string, path: string, query?: RequestOptions['query']): string {
  const base = baseUrl.replace(/\/+$/, '');
  const p = path.startsWith('/') ? path : `/${path}`;
  const params = Object.entries(query ?? {})
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
  return params.length ? `${base}${p}?${params.join('&')}` : `${base}${p}`;
}

export class ApiClient {
  private readonly config: Required<Pick<ApiClientConfig, 'defaultTimeoutMs'>> & ApiClientConfig;

  constructor(config: ApiClientConfig) {
    this.config = { defaultTimeoutMs: 20000, ...config };
  }

  get baseUrl(): string {
    return this.config.baseUrl;
  }

  /** Petición que devuelve `{ data, meta }` del sobre `ApiEnvelope`. */
  async request<T, M = undefined>(method: HttpMethod, path: string, options: RequestOptions = {}): Promise<ApiResult<T, M>> {
    const fetchImpl = this.config.fetchImpl ?? fetch;
    const url = buildUrl(this.config.baseUrl, path, options.query);
    const headers: Record<string, string> = { ...options.headers, Accept: 'application/json' };

    if (options.auth !== false) {
      const token = await this.config.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
    }

    let body: string | FormData | undefined;
    if (options.form) {
      body = options.form;
    } else if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }

    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, options.timeoutMs ?? this.config.defaultTimeoutMs);
    const onExternalAbort = () => controller.abort();
    options.signal?.addEventListener('abort', onExternalAbort);

    let response: Response;
    try {
      response = await fetchImpl(url, { method, headers, body, signal: controller.signal });
    } catch (e) {
      if (timedOut) throw new NetworkError('Tiempo de espera agotado', true);
      if (options.signal?.aborted) throw e;
      throw new NetworkError(e instanceof Error ? e.message : 'Error de red');
    } finally {
      clearTimeout(timer);
      options.signal?.removeEventListener('abort', onExternalAbort);
    }

    const parsed = await readJson(response);

    if (!response.ok) {
      const error = new ApiRequestError(response.status, toApiError(response.status, parsed));
      if (options.auth !== false && (response.status === 401 || DEVICE_CODES.has(error.code))) {
        this.config.onAuthFailure?.(error);
      }
      throw error;
    }

    if (response.status === 204 || parsed === undefined) {
      return { status: response.status, data: undefined as T, meta: undefined };
    }

    const envelope = parsed as ApiEnvelope<T, M>;
    if (!envelope || typeof envelope !== 'object' || !('data' in envelope)) {
      throw new ApiRequestError(response.status, {
        code: 'server_error',
        message: 'Respuesta del servidor con formato inesperado.',
      });
    }
    return { status: response.status, data: envelope.data, meta: envelope.meta };
  }

  async get<T, M = undefined>(path: string, options?: RequestOptions) {
    return this.request<T, M>('GET', path, options);
  }

  async post<T, M = undefined>(path: string, body?: unknown, options?: RequestOptions) {
    return this.request<T, M>('POST', path, { ...options, body });
  }
}

async function readJson(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined;
  let text: string;
  try {
    text = await response.text();
  } catch {
    return undefined;
  }
  if (!text) return undefined;
  try {
    return JSON.parse(text);
  } catch {
    // HTML de error del servidor u otro contenido no JSON.
    return null;
  }
}
