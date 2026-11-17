import { authReducer, initialAuthState } from '@/features/auth/authReducer';
import { initialPunchUiState, punchUiReducer } from '@/features/attendance/punchReducer';
import type { Device, Profile } from '@/types/contracts';

const profile = {
  user: { id: 1, name: 'Ana', email: 'a@x' },
  organization: { id: 1, name: 'Org', timezone: 'America/Mexico_City' },
  employee: null,
  pending_acknowledgements: [],
} as Profile;

const device = (status: Device['status']): Device => ({
  id: 1,
  device_uuid: 'u',
  name: 'Tel',
  platform: 'android',
  model: null,
  status,
  approved_at: null,
  revoked_at: null,
  last_seen_at: null,
  is_current: true,
  pending_reason: status === 'pending' ? 'device_change' : null,
});

describe('authReducer', () => {
  it('login con dispositivo aprobado → signedIn', () => {
    const s = authReducer(initialAuthState, { type: 'signedIn', profile, device: device('approved'), secretProtection: 'biometric' });
    expect(s).toMatchObject({ status: 'signedIn', secretProtection: 'biometric' });
  });

  it('login con dispositivo pendiente → devicePending', () => {
    const s = authReducer(initialAuthState, { type: 'signedIn', profile, device: device('pending'), secretProtection: null });
    expect(s).toEqual({ status: 'devicePending', device: device('pending'), hasSession: true });
  });

  it('revocación durante la sesión → deviceRevoked', () => {
    const s1 = authReducer(initialAuthState, { type: 'signedIn', profile, device: device('approved'), secretProtection: null });
    expect(authReducer(s1, { type: 'deviceUpdated', device: device('revoked') }).status).toBe('deviceRevoked');
  });

  it('signedOut conserva aviso', () => {
    expect(authReducer(initialAuthState, { type: 'signedOut', notice: 'Expiró' })).toEqual({ status: 'signedOut', notice: 'Expiró' });
  });

  it('profileUpdated sólo aplica con sesión', () => {
    expect(authReducer(initialAuthState, { type: 'profileUpdated', profile })).toBe(initialAuthState);
  });
});

describe('punchUiReducer', () => {
  it('flujo completo working → done', () => {
    let s = punchUiReducer(initialPunchUiState, { type: 'start', punchType: 'clock_in' });
    s = punchUiReducer(s, { type: 'step', step: 'verifying' });
    expect(s).toEqual({ phase: 'working', type: 'clock_in', step: 'verifying' });
    s = punchUiReducer(s, { type: 'failure', message: 'x', cancelled: true });
    expect(s).toMatchObject({ phase: 'error', cancelled: true });
  });

  it('no permite doble marcaje en curso ni reset durante el envío', () => {
    const w = punchUiReducer(initialPunchUiState, { type: 'start', punchType: 'clock_in' });
    expect(punchUiReducer(w, { type: 'start', punchType: 'clock_out' })).toBe(w);
    expect(punchUiReducer(w, { type: 'reset' })).toBe(w);
  });

  it('success fuera de working se ignora', () => {
    expect(punchUiReducer(initialPunchUiState, { type: 'failure', message: 'x' })).toBe(initialPunchUiState);
  });
});
