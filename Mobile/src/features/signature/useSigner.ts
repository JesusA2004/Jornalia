import { useCallback } from 'react';

import type { ConsentPurpose } from '@/crypto/signature';
import { unlockDeviceSecret } from '@/features/attendance/deviceAuth';
import { PunchFlowError } from '@/features/attendance/errors';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import type { SignatureMethod, SignRequest } from '@/types/contracts';

import { usePinFlag } from './pinFlag';
import { buildSignRequest, SignatureInputError } from './sign';

/** Métodos de firma disponibles en este teléfono para la sesión actual. */
export function useSignatureMethods() {
  const { profile, secretProtection } = useSignedIn();
  const local = usePinFlag(profile.user.id);
  return {
    /** Biometría (o bloqueo) del SO que desbloquea el secreto del dispositivo. */
    device: secretProtection,
    /** null = no se sabe; se permite intentar con NIP (el servidor responde si falta). */
    pinSet: profile.signature_pin_set ?? local,
  };
}

/** Arma firmas con los datos de la sesión. Traduce cancelaciones del SO a mensajes de firma. */
export function useSigner() {
  const { profile, secretProtection } = useSignedIn();
  const userId = profile.user.id;
  return useCallback(
    async (
      purpose: ConsentPurpose,
      subjectId: number | string,
      method: SignatureMethod,
      pin?: string | null,
      payloadSha256?: string | null,
    ): Promise<SignRequest> => {
      try {
        return await buildSignRequest(
          { purpose, subjectId, method, pin, payloadSha256 },
          {
            deviceUuid: getOrCreateDeviceUuid,
            unlockSecret: () => {
              if (!secretProtection) throw new SignatureInputError('Este teléfono no puede firmar con huella. Usa tu NIP.');
              return unlockDeviceSecret(userId, secretProtection, 'Firma electrónica');
            },
          },
        );
      } catch (e) {
        if (e instanceof PunchFlowError) {
          throw new SignatureInputError(
            e.code === 'auth_cancelled'
              ? 'Cancelaste. No se firmó.'
              : e.code === 'secret_invalidated'
                ? 'La huella cambió. Usa tu NIP.'
                : 'No se pudo verificar. Usa tu NIP.',
          );
        }
        throw e;
      }
    },
    [userId, secretProtection],
  );
}
