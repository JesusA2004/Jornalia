import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';

/** GET /face-verification/status (si falla, se considera no disponible). */
export function useFaceStatus(enabled = true) {
  return useQuery(() => api.faceVerification.status(), [], { enabled });
}
