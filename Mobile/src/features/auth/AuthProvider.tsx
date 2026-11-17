import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { ApiRequestError, NetworkError } from '@/api/errors';
import { upgradeProtectionToBiometric } from '@/features/attendance/deviceAuth';
import { stopWorkLocation } from '@/features/location/tracker';
import { api, setAuthFailureListener, setMemoryToken } from '@/services/container';
import { cacheClear, cacheGet, cacheSet } from '@/storage/db';
import {
  clearSession,
  getSecretProtection,
  getSession,
  saveDeviceSecret,
  saveSession,
  setLastEmail,
  type SecretProtection,
} from '@/storage/secure';
import type { Device, Profile } from '@/types/contracts';

import { authReducer, initialAuthState, type AuthState } from './authReducer';
import { buildDeviceInfo } from './deviceInfo';

const PROFILE_CACHE = 'profile';
const DEVICE_CACHE = 'device';
/** Se rota el token cuando faltan menos de 3 días para que expire. */
const REFRESH_WINDOW_MS = 3 * 24 * 3600_000;

export class SecretStoreError extends Error {
  constructor(cause: unknown) {
    super(cause instanceof Error ? cause.message : 'No se pudo proteger la credencial del dispositivo.');
    this.name = 'SecretStoreError';
  }
}

interface AuthContextValue {
  state: AuthState;
  signIn: (email: string, password: string) => Promise<void>;
  /** Reintenta guardar el secreto recibido si la protección biométrica falló o se canceló. */
  retryStoreSecret: () => Promise<void>;
  hasUnsavedSecret: () => boolean;
  signOut: (notice?: string | null) => Promise<void>;
  refreshProfile: () => Promise<Profile | null>;
  checkDevice: () => Promise<void>;
  /** Re-protege con biometría el secreto guardado con credencial del dispositivo. */
  upgradeSecret: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(authReducer, initialAuthState);
  const pendingSecret = useRef<{ userId: number; secret: string; profile: Profile; device: Device } | null>(null);
  const expiresAt = useRef<string | null>(null);
  const signingOut = useRef(false);

  const localSignOut = useCallback(async (notice: string | null) => {
    setMemoryToken(null);
    await stopWorkLocation().catch(() => undefined);
    await clearSession();
    await cacheClear().catch(() => undefined);
    dispatch({ type: 'signedOut', notice });
  }, []);

  // Reacción global a 401 y códigos de dispositivo devueltos por cualquier endpoint.
  useEffect(() => {
    setAuthFailureListener((e: ApiRequestError) => {
      if (signingOut.current) return;
      if (e.code === 'device_pending') {
        // Se usa el Device guardado del login (trae pending_reason) para elegir el texto.
        void cacheGet<Device>(DEVICE_CACHE)
          .catch(() => null)
          .then((c) => dispatch({ type: 'devicePending', device: c?.value ?? null, hasSession: true }));
      } else if (e.code === 'device_revoked') {
        setMemoryToken(null);
        void clearSession();
        dispatch({ type: 'deviceRevoked', message: e.message });
      } else if (e.code === 'device_unknown') {
        void localSignOut('Este dispositivo no está registrado. Inicia sesión de nuevo para vincularlo.');
      } else if (e.status === 401) {
        // La sesión vale sólo hoy y un inicio de sesión en otro lado cierra la anterior.
        void localSignOut('Inicia sesión para hoy');
      }
    });
    return () => setAuthFailureListener(null);
  }, [localSignOut]);

  const maybeRefreshToken = useCallback(async () => {
    const exp = expiresAt.current ? Date.parse(expiresAt.current) : NaN;
    if (Number.isNaN(exp) || exp - Date.now() > REFRESH_WINDOW_MS) return;
    try {
      const r = await api.auth.refresh();
      const session = await getSession();
      setMemoryToken(r.token);
      expiresAt.current = r.token_expires_at;
      await saveSession({ token: r.token, tokenExpiresAt: r.token_expires_at, userId: session?.userId ?? null });
    } catch {
      // Sin red: se reintenta en el siguiente regreso a primer plano. 401 lo maneja el listener.
    }
  }, []);

  // Restaurar sesión al abrir.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const session = await getSession().catch(() => null);
      if (!session) {
        if (!cancelled) dispatch({ type: 'restoreFailed' });
        return;
      }
      setMemoryToken(session.token);
      expiresAt.current = session.tokenExpiresAt;
      const protection = session.userId !== null ? await getSecretProtection(session.userId).catch(() => null) : null;
      try {
        const profile = await api.profile.get();
        await cacheSet(PROFILE_CACHE, profile).catch(() => undefined);
        const device = (await cacheGet<Device>(DEVICE_CACHE).catch(() => null))?.value ?? null;
        if (!cancelled) dispatch({ type: 'signedIn', profile, device, secretProtection: protection });
        void maybeRefreshToken();
      } catch (e) {
        if (cancelled) return;
        if (e instanceof NetworkError || (e instanceof ApiRequestError && e.isRetriable)) {
          const cached = await cacheGet<Profile>(PROFILE_CACHE).catch(() => null);
          const device = (await cacheGet<Device>(DEVICE_CACHE).catch(() => null))?.value ?? null;
          if (cached) {
            dispatch({ type: 'signedIn', profile: cached.value, device, secretProtection: protection, offlineProfile: true });
            return;
          }
        }
        // 401 / dispositivo: el listener ya cambió el estado; en otro caso, volver a login.
        if (!(e instanceof ApiRequestError && (e.status === 401 || e.code.startsWith('device_')))) {
          dispatch({ type: 'restoreFailed' });
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [maybeRefreshToken]);

  // Rotación de token al volver a primer plano.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void maybeRefreshToken();
    });
    return () => sub.remove();
  }, [maybeRefreshToken]);

  const finishSignIn = useCallback(async (profile: Profile, device: Device, protection: SecretProtection | null) => {
    await cacheSet(PROFILE_CACHE, profile).catch(() => undefined);
    await cacheSet(DEVICE_CACHE, device).catch(() => undefined);
    dispatch({ type: 'signedIn', profile, device, secretProtection: protection });
  }, []);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const device = await buildDeviceInfo();
      let res;
      try {
        res = await api.auth.login({ email: email.trim(), password, device });
      } catch (e) {
        if (e instanceof ApiRequestError && e.code === 'device_pending') {
          dispatch({ type: 'devicePending', device: null, hasSession: false });
          return;
        }
        if (e instanceof ApiRequestError && e.code === 'device_revoked') {
          dispatch({ type: 'deviceRevoked', message: e.message });
          return;
        }
        throw e;
      }
      const userId = res.profile.user.id;
      await setLastEmail(email.trim());
      setMemoryToken(res.token);
      expiresAt.current = res.token_expires_at;
      await saveSession({ token: res.token, tokenExpiresAt: res.token_expires_at, userId });

      let protection = await getSecretProtection(userId).catch(() => null);
      if (res.device_secret) {
        try {
          protection = await saveDeviceSecret(userId, res.device_secret);
        } catch (e) {
          // El secreto sólo se entrega una vez: se conserva en memoria para reintentar.
          pendingSecret.current = { userId, secret: res.device_secret, profile: res.profile, device: res.device };
          throw new SecretStoreError(e);
        }
      }
      await finishSignIn(res.profile, res.device, protection);
    },
    [finishSignIn],
  );

  const retryStoreSecret = useCallback(async () => {
    const p = pendingSecret.current;
    if (!p) return;
    let protection: SecretProtection;
    try {
      protection = await saveDeviceSecret(p.userId, p.secret);
    } catch (e) {
      throw new SecretStoreError(e);
    }
    pendingSecret.current = null;
    await finishSignIn(p.profile, p.device, protection);
  }, [finishSignIn]);

  const signOut = useCallback(
    async (notice: string | null = null) => {
      signingOut.current = true;
      try {
        if (await getSession()) await api.auth.logout();
      } catch {
        // Se cierra la sesión local aunque el servidor no responda.
      }
      pendingSecret.current = null;
      await localSignOut(notice);
      signingOut.current = false;
    },
    [localSignOut],
  );

  const refreshProfile = useCallback(async () => {
    try {
      const profile = await api.profile.get();
      await cacheSet(PROFILE_CACHE, profile).catch(() => undefined);
      dispatch({ type: 'profileUpdated', profile });
      return profile;
    } catch {
      return null;
    }
  }, []);

  const checkDevice = useCallback(async () => {
    const devices = await api.devices.list();
    const current = devices.find((d) => d.is_current);
    if (!current) return;
    await cacheSet(DEVICE_CACHE, current).catch(() => undefined);
    if (current.status === 'approved' && state.status === 'devicePending') {
      const session = await getSession();
      const profile = await api.profile.get();
      await cacheSet(PROFILE_CACHE, profile).catch(() => undefined);
      const protection = session?.userId != null ? await getSecretProtection(session.userId).catch(() => null) : null;
      dispatch({ type: 'signedIn', profile, device: current, secretProtection: protection });
    } else {
      dispatch({ type: 'deviceUpdated', device: current });
    }
  }, [state.status]);

  const upgradeSecret = useCallback(async () => {
    const session = await getSession();
    if (session?.userId == null) return;
    await upgradeProtectionToBiometric(session.userId);
    dispatch({ type: 'secretUpdated', secretProtection: 'biometric' });
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      state,
      signIn,
      retryStoreSecret,
      hasUnsavedSecret: () => pendingSecret.current !== null,
      signOut,
      refreshProfile,
      checkDevice,
      upgradeSecret,
    }),
    [state, signIn, retryStoreSecret, signOut, refreshProfile, checkDevice, upgradeSecret],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider');
  return ctx;
}

type SignedInState = Extract<AuthState, { status: 'signedIn' }>;

/**
 * Sesión del usuario autenticado (sólo en pantallas protegidas). Conserva el
 * último estado válido para que una pantalla que se desmonta al cerrar sesión
 * no falle durante la transición.
 */
export function useSignedIn(): SignedInState {
  const { state } = useAuth();
  const [last, setLast] = useState<SignedInState | null>(state.status === 'signedIn' ? state : null);
  if (state.status === 'signedIn' && state !== last) setLast(state);
  const current = state.status === 'signedIn' ? state : last;
  if (!current) throw new Error('Pantalla protegida usada sin sesión');
  return current;
}
