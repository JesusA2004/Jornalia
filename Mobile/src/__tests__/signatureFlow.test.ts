import { consentCanonical, signConsent } from '@/crypto/signature';
import {
  canSignNow,
  comparisonOf,
  dueInfo,
  dueLabel,
  pendingSignatures,
  signatureStatement,
  stageOf,
  validateDeclineReason,
} from '@/features/requests/rectification';
import { isPinComplete, sanitizePinInput, validateNewPin } from '@/features/signature/pin';
import { buildSignRequest, canSignWithDevice, SignatureInputError } from '@/features/signature/sign';
import type { Rectification } from '@/types/contracts';

/** Vector calculado de forma independiente (Python hmac/sha256, igual que PHP hash_hmac). */
const SECRET_B64 = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const DEVICE = '8d7e6f5a-4b3c-4d2e-8f1a-0b9c8d7e6f5a';
const PAYLOAD = 'a'.repeat(64);
const EXPECTED = 'c6cb2a6172ddc46d1bf61f9bb3fae00bbad6b602c6228bf27553198545169b53';

const rect = (o: Partial<Rectification> = {}): Rectification => ({
  id: 42,
  date: '2026-11-20',
  punch_uuid: null,
  kind: 'missing_punch',
  proposed_type: 'clock_out',
  proposed_time: '2026-11-21T00:05:00Z',
  reason: 'Olvidé marcar mi salida por batería.',
  status: 'pending',
  reviewer: null,
  review_comment: null,
  reviewed_at: null,
  created_at: '2026-11-21T15:00:00Z',
  ...o,
});

describe('firma electrónica: NIP', () => {
  it('exige 6 dígitos sin repetidos ni secuencias al crear', () => {
    expect(validateNewPin('12a456')).toBe('digits');
    expect(validateNewPin('1234')).toBe('length');
    expect(validateNewPin('111111')).toBe('repeated');
    expect(validateNewPin('123456')).toBe('sequence');
    expect(validateNewPin('987654')).toBe('sequence');
    expect(validateNewPin('583920', '583921')).toBe('mismatch');
    expect(validateNewPin('583920', '583920')).toBeNull();
  });

  it('al firmar acepta 4 a 6 dígitos (regla del servidor) y limpia lo tecleado', () => {
    expect(sanitizePinInput('12-34 567')).toBe('123456');
    expect(isPinComplete('123')).toBe(false);
    expect(isPinComplete('1234')).toBe(true);
    expect(isPinComplete('123456')).toBe(true);
  });
});

describe('firma electrónica: cuerpo de POST …/sign', () => {
  const unlockSecret = jest.fn(async () => SECRET_B64);
  const deps = { deviceUuid: async () => DEVICE, unlockSecret, now: () => new Date('2026-11-21T16:00:00.123Z') };

  it('canónica igual a ElectronicSignatureService::biometricCanonical', () => {
    expect(consentCanonical('rectification', 42, PAYLOAD, DEVICE)).toBe(`sign|rectification|42|${PAYLOAD}|${DEVICE}`);
    expect(signConsent(SECRET_B64, 'rectification', 42, PAYLOAD, DEVICE)).toBe(EXPECTED);
  });

  it('con NIP envía el NIP y no desbloquea el secreto', async () => {
    unlockSecret.mockClear();
    const body = await buildSignRequest({ purpose: 'rectification', subjectId: 42, method: 'pin', pin: '583920' }, deps);
    expect(body).toEqual({ method: 'pin', pin: '583920', device_uuid: DEVICE, device_time: '2026-11-21T16:00:00Z' });
    expect(unlockSecret).not.toHaveBeenCalled();
  });

  it('sin NIP completo no arma la petición', async () => {
    await expect(buildSignRequest({ purpose: 'rectification', subjectId: 42, method: 'pin', pin: '12' }, deps)).rejects.toBeInstanceOf(
      SignatureInputError,
    );
  });

  it('con biometría firma HMAC sobre el hash del servidor (nunca un booleano)', async () => {
    const body = await buildSignRequest({ purpose: 'rectification', subjectId: 42, method: 'biometric', payloadSha256: PAYLOAD }, deps);
    expect(body.method).toBe('biometric');
    expect(body.pin).toBeUndefined();
    expect(body.device_signature).toBe(EXPECTED);
  });

  it('sin hash del servidor la biometría no está disponible', async () => {
    expect(canSignWithDevice(null)).toBe(false);
    expect(canSignWithDevice('xyz')).toBe(false);
    expect(canSignWithDevice(PAYLOAD)).toBe(true);
    await expect(
      buildSignRequest({ purpose: 'privacy_notice', subjectId: 1, method: 'biometric', payloadSha256: null }, deps),
    ).rejects.toBeInstanceOf(SignatureInputError);
  });

  it('si el usuario cancela la biometría no se firma', async () => {
    const cancel = { ...deps, unlockSecret: async () => Promise.reject(new SignatureInputError('Cancelaste. No se firmó.')) };
    await expect(
      buildSignRequest({ purpose: 'rectification', subjectId: 42, method: 'biometric', payloadSha256: PAYLOAD }, cancel),
    ).rejects.toThrow('Cancelaste');
  });
});

describe('rectificaciones: flujo de 5 pasos', () => {
  const NOW = Date.parse('2026-11-21T16:00:00Z');

  it('deriva la etapa de servidores anteriores', () => {
    expect(stageOf(rect())).toBe('por_autorizar');
    expect(stageOf(rect({ status: 'approved' }))).toBe('aplicada');
    expect(stageOf(rect({ status: 'rejected' }))).toBe('rechazada');
    expect(stageOf(rect({ stage: 'por_firmar' }))).toBe('por_firmar');
  });

  it('muestra registro original frente a propuesta', () => {
    const r = rect({
      original: { summary: 'Sin registro de salida', type: null, time: null },
      proposal: { summary: 'Salida 18:05', type: 'clock_out', time: '2026-11-21T00:05:00Z' },
    });
    const c = comparisonOf(r);
    expect(c.original[0]?.summary).toBe('Sin registro de salida');
    expect(c.proposed[0]?.type).toBe('clock_out');
    // Servidor anterior: propuesta con los campos clásicos.
    expect(comparisonOf(rect()).proposed[0]?.time).toBe('2026-11-21T00:05:00Z');
  });

  it('plazo de firma: urgente, vencido y bloqueo de firma', () => {
    const due = rect({ stage: 'por_firmar', signature_due_at: '2026-11-22T10:00:00Z' });
    const d = dueInfo(due, NOW)!;
    expect(d.urgent).toBe(true);
    expect(dueLabel(d)).toBe('Vence en 18 h');
    expect(canSignNow(due, NOW)).toBe(true);
    const late = rect({ stage: 'por_firmar', signature_due_at: '2026-11-21T10:00:00Z' });
    expect(dueInfo(late, NOW)?.overdue).toBe(true);
    expect(canSignNow(late, NOW)).toBe(false);
    expect(canSignNow(rect({ stage: 'por_firmar', can_sign: false }), NOW)).toBe(false);
    expect(canSignNow(rect({ stage: 'por_validar' }), NOW)).toBe(false);
    expect(dueLabel(dueInfo(rect({ stage: 'por_firmar', signature_due_at: '2026-11-24T18:00:00Z' }), NOW)!)).toBe('Vence en 3 d 2 h');
  });

  it('ordena las pendientes de firma por vencimiento', () => {
    const list = [
      rect({ id: 1, stage: 'por_firmar', signature_due_at: '2026-11-24T10:00:00Z' }),
      rect({ id: 2, stage: 'aplicada' }),
      rect({ id: 3, stage: 'por_firmar', signature_due_at: '2026-11-22T10:00:00Z' }),
    ];
    expect(pendingSignatures(list).map((r) => r.id)).toEqual([3, 1]);
  });

  it('firma el texto exacto del servidor y exige motivo para rechazar', () => {
    expect(signatureStatement(rect({ signature_statement: 'Texto del servidor' }), () => '')).toBe('Texto del servidor');
    expect(signatureStatement(rect(), () => 'Salida 18:05')).toContain('El marcaje original se conserva');
    expect(validateDeclineReason('no')).not.toBeNull();
    expect(validateDeclineReason('La hora no es correcta')).toBeNull();
  });
});
