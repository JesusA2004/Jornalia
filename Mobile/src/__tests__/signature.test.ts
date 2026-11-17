import { base64ToBytes } from '@/crypto/base64';
import { canonicalString, signPunch } from '@/crypto/signature';

/**
 * Vectores fijos calculados de forma independiente con Python:
 *   hmac.new(bytes(range(32)), canonical.encode(), hashlib.sha256).hexdigest()
 */
const SECRET_B64 = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const BASE = {
  client_uuid: '3f2c1a9e-0b7d-4c5e-9a1f-2b3c4d5e6f70',
  device_uuid: '8d7e6f5a-4b3c-4d2e-8f1a-0b9c8d7e6f5a',
};

describe('firma HMAC de marcajes', () => {
  it('construye la cadena canónica del contrato', () => {
    expect(canonicalString({ ...BASE, type: 'clock_in', device_time: '2026-11-17T15:04:05Z', nonce: 'n0nce-abc' })).toBe(
      '3f2c1a9e-0b7d-4c5e-9a1f-2b3c4d5e6f70|clock_in|2026-11-17T15:04:05Z|n0nce-abc|8d7e6f5a-4b3c-4d2e-8f1a-0b9c8d7e6f5a',
    );
  });

  it('firma en línea (con nonce) igual que la referencia', () => {
    expect(signPunch(SECRET_B64, { ...BASE, type: 'clock_in', device_time: '2026-11-17T15:04:05Z', nonce: 'n0nce-abc' })).toBe(
      'd39b34186d79b64b72694e55b821118acdfd6e82fa35e7c4c86a949a0dc84c33',
    );
  });

  it('firma sin conexión (nonce null → cadena vacía) igual que la referencia', () => {
    expect(signPunch(SECRET_B64, { ...BASE, type: 'clock_out', device_time: '2026-11-17T23:00:00Z', nonce: null })).toBe(
      '1494a1762c17ab7aba51875381eb05f3a55fabe54bf43810dccef209eda288ef',
    );
  });

  it('decodifica base64 estándar y url-safe', () => {
    expect(Array.from(base64ToBytes(SECRET_B64))).toEqual(Array.from({ length: 32 }, (_, i) => i));
    expect(Array.from(base64ToBytes('-_8'))).toEqual([251, 255]);
    expect(() => base64ToBytes('a')).toThrow();
  });

  it('rechaza un secreto vacío', () => {
    expect(() => signPunch('', { ...BASE, type: 'clock_in', device_time: 'x', nonce: null })).toThrow();
  });
});
