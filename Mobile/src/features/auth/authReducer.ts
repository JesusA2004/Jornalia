import type { Device, Profile } from '@/types/contracts';
import type { SecretProtection } from '@/storage/secure';

export type AuthState =
  | { status: 'loading' }
  | { status: 'signedOut'; notice: string | null }
  | { status: 'devicePending'; device: Device | null; hasSession: boolean }
  | { status: 'deviceRevoked'; message: string | null }
  | {
      status: 'signedIn';
      profile: Profile;
      device: Device | null;
      /** null si este dispositivo no tiene secreto de firma guardado. */
      secretProtection: SecretProtection | null;
      /** El perfil proviene de caché local (sin conexión al abrir). */
      offlineProfile: boolean;
    };

export type AuthAction =
  | { type: 'restoreFailed' }
  | { type: 'signedIn'; profile: Profile; device: Device | null; secretProtection: SecretProtection | null; offlineProfile?: boolean }
  | { type: 'signedOut'; notice?: string | null }
  | { type: 'devicePending'; device: Device | null; hasSession: boolean }
  | { type: 'deviceRevoked'; message?: string | null }
  | { type: 'profileUpdated'; profile: Profile }
  | { type: 'deviceUpdated'; device: Device }
  | { type: 'secretUpdated'; secretProtection: SecretProtection | null };

export const initialAuthState: AuthState = { status: 'loading' };

export function authReducer(state: AuthState, action: AuthAction): AuthState {
  switch (action.type) {
    case 'restoreFailed':
      return { status: 'signedOut', notice: null };
    case 'signedIn':
      if (action.device?.status === 'pending') return { status: 'devicePending', device: action.device, hasSession: true };
      if (action.device?.status === 'revoked') return { status: 'deviceRevoked', message: null };
      return {
        status: 'signedIn',
        profile: action.profile,
        device: action.device,
        secretProtection: action.secretProtection,
        offlineProfile: action.offlineProfile ?? false,
      };
    case 'signedOut':
      return { status: 'signedOut', notice: action.notice ?? null };
    case 'devicePending':
      return { status: 'devicePending', device: action.device, hasSession: action.hasSession };
    case 'deviceRevoked':
      return { status: 'deviceRevoked', message: action.message ?? null };
    case 'profileUpdated':
      return state.status === 'signedIn' ? { ...state, profile: action.profile, offlineProfile: false } : state;
    case 'deviceUpdated':
      if (state.status === 'signedIn') {
        if (action.device.status === 'revoked') return { status: 'deviceRevoked', message: null };
        if (action.device.status === 'pending') return { status: 'devicePending', device: action.device, hasSession: true };
        return { ...state, device: action.device };
      }
      if (state.status === 'devicePending' && action.device.status === 'pending') return { ...state, device: action.device };
      return state;
    case 'secretUpdated':
      return state.status === 'signedIn' ? { ...state, secretProtection: action.secretProtection } : state;
    default:
      return state;
  }
}
