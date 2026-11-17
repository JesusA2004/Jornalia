import { ApiClient, buildUrl } from '@/api/client';
import { ApiRequestError, describeError, NetworkError } from '@/api/errors';

function response(status: number, body: unknown, raw = false): Response {
  const text = raw ? String(body) : body === undefined ? '' : JSON.stringify(body);
  return { status, ok: status >= 200 && status < 300, text: async () => text } as unknown as Response;
}

function client(fetchImpl: jest.Mock, onAuthFailure = jest.fn()) {
  return {
    api: new ApiClient({ baseUrl: 'http://x/api/v1/', getToken: () => 'tok', fetchImpl, onAuthFailure, defaultTimeoutMs: 50 }),
    onAuthFailure,
  };
}

describe('cliente API', () => {
  it('construye URL sin parámetros vacíos', () => {
    expect(buildUrl('http://x/api/v1/', '/a', { page: 2, from: undefined, q: 'á b' })).toBe('http://x/api/v1/a?page=2&q=%C3%A1%20b');
  });

  it('desenvuelve { data, meta } y envía Bearer', async () => {
    const f = jest.fn().mockResolvedValue(response(200, { data: [1], meta: { total: 1 } }));
    const { api } = client(f);
    const r = await api.get<number[], { total: number }>('/x');
    expect(r.data).toEqual([1]);
    expect(r.meta).toEqual({ total: 1 });
    expect(f.mock.calls[0][1].headers.Authorization).toBe('Bearer tok');
  });

  it('204 sin cuerpo', async () => {
    const { api } = client(jest.fn().mockResolvedValue(response(204, undefined)));
    await expect(api.post('/x')).resolves.toMatchObject({ status: 204, data: undefined });
  });

  it('401 notifica fallo de autenticación', async () => {
    const { api, onAuthFailure } = client(jest.fn().mockResolvedValue(response(401, { message: 'No', code: 'unauthenticated' })));
    await expect(api.get('/x')).rejects.toBeInstanceOf(ApiRequestError);
    expect(onAuthFailure).toHaveBeenCalledTimes(1);
  });

  it('device_pending y device_revoked se reconocen', async () => {
    const { api, onAuthFailure } = client(jest.fn().mockResolvedValue(response(403, { message: 'Pendiente', code: 'device_pending' })));
    const err = await api.get('/x').catch((e) => e);
    expect(err.code).toBe('device_pending');
    expect(onAuthFailure).toHaveBeenCalledWith(err);
  });

  it('login (auth:false) no dispara el manejador global', async () => {
    const { api, onAuthFailure } = client(jest.fn().mockResolvedValue(response(403, { message: 'Revocado', code: 'device_revoked' })));
    await expect(api.post('/auth/login', {}, { auth: false })).rejects.toMatchObject({ code: 'device_revoked' });
    expect(onAuthFailure).not.toHaveBeenCalled();
  });

  it('409 device_in_use (teléfono de otro colaborador) se reconoce en login', async () => {
    const { api, onAuthFailure } = client(jest.fn().mockResolvedValue(response(409, { message: 'En uso', code: 'device_in_use' })));
    await expect(api.post('/auth/login', {}, { auth: false })).rejects.toMatchObject({ code: 'device_in_use', status: 409 });
    expect(onAuthFailure).not.toHaveBeenCalled();
  });

  it('422 conserva errores por campo', async () => {
    const { api } = client(
      jest.fn().mockResolvedValue(response(422, { message: 'Datos inválidos', code: 'validation_failed', errors: { email: ['Requerido'] } })),
    );
    const err = (await api.post('/x').catch((e) => e)) as ApiRequestError;
    expect(err.fieldErrors.email).toEqual(['Requerido']);
  });

  it('500 con HTML → server_error con mensaje en español', async () => {
    const { api } = client(jest.fn().mockResolvedValue(response(500, '<html>', true)));
    const err = (await api.get('/x').catch((e) => e)) as ApiRequestError;
    expect(err.code).toBe('server_error');
    expect(err.isRetriable).toBe(true);
    expect(err.message).toMatch(/servidor/);
  });

  it('código desconocido se normaliza por estado HTTP', async () => {
    const { api } = client(jest.fn().mockResolvedValue(response(429, { message: '', code: 'otro' })));
    await expect(api.get('/x')).rejects.toMatchObject({ code: 'rate_limited' });
  });

  it('fallo de red → NetworkError', async () => {
    const { api } = client(jest.fn().mockRejectedValue(new TypeError('Network request failed')));
    const err = await api.get('/x').catch((e) => e);
    expect(err).toBeInstanceOf(NetworkError);
    expect(describeError(err)).toMatch(/conexión/);
  });

  it('tiempo agotado → NetworkError(timedOut)', async () => {
    const f = jest.fn(
      (_url: string, init: { signal: AbortSignal }) =>
        new Promise((_res, rej) => init.signal.addEventListener('abort', () => rej(new Error('aborted')))),
    );
    const { api } = client(f as unknown as jest.Mock);
    const err = await api.get('/x').catch((e) => e);
    expect(err).toBeInstanceOf(NetworkError);
    expect(err.timedOut).toBe(true);
  });

  it('2xx sin sobre { data } es error de formato', async () => {
    const { api } = client(jest.fn().mockResolvedValue(response(200, { ok: true })));
    await expect(api.get('/x')).rejects.toMatchObject({ code: 'server_error' });
  });
});
