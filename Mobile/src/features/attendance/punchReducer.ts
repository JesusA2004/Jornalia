import type { PunchType } from '@/types/contracts';

import type { PunchOutcome, PunchStep } from './punchFlow';

export type PunchUiState =
  | { phase: 'idle' }
  | { phase: 'working'; type: PunchType; step: PunchStep }
  | { phase: 'done'; type: PunchType; outcome: PunchOutcome }
  | { phase: 'error'; type: PunchType; message: string; cancelled: boolean };

export type PunchUiAction =
  | { type: 'start'; punchType: PunchType }
  | { type: 'step'; step: PunchStep }
  | { type: 'success'; outcome: PunchOutcome }
  | { type: 'failure'; message: string; cancelled?: boolean }
  | { type: 'reset' };

export const initialPunchUiState: PunchUiState = { phase: 'idle' };

export function punchUiReducer(state: PunchUiState, action: PunchUiAction): PunchUiState {
  switch (action.type) {
    case 'start':
      // No se permite un segundo marcaje mientras uno está en curso.
      if (state.phase === 'working') return state;
      return { phase: 'working', type: action.punchType, step: 'checking' };
    case 'step':
      return state.phase === 'working' ? { ...state, step: action.step } : state;
    case 'success':
      return state.phase === 'working' ? { phase: 'done', type: state.type, outcome: action.outcome } : state;
    case 'failure':
      return state.phase === 'working'
        ? { phase: 'error', type: state.type, message: action.message, cancelled: action.cancelled ?? false }
        : state;
    case 'reset':
      return state.phase === 'working' ? state : initialPunchUiState;
    default:
      return state;
  }
}

export const STEP_LABELS: Record<PunchStep, string> = {
  checking: 'Validando política de marcaje…',
  locating: 'Obteniendo ubicación…',
  verifying: 'Confirma tu identidad…',
  challenge: 'Solicitando verificación al servidor…',
  sending: 'Enviando marcaje…',
  queueing: 'Guardando para sincronizar…',
};
