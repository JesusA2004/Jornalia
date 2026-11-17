const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
const LOOKUP: Record<string, number> = {};
for (let i = 0; i < ALPHABET.length; i++) LOOKUP[ALPHABET[i]!] = i;
// Variante URL-safe.
LOOKUP['-'] = 62;
LOOKUP['_'] = 63;

/** Decodifica base64 (estándar o URL-safe, con o sin relleno) a bytes. */
export function base64ToBytes(input: string): Uint8Array {
  const clean = input.replace(/[\s=]/g, '');
  if (clean.length % 4 === 1) throw new Error('Base64 inválido');
  const out = new Uint8Array(Math.floor((clean.length * 3) / 4));
  let buffer = 0;
  let bits = 0;
  let o = 0;
  for (const ch of clean) {
    const v = LOOKUP[ch];
    if (v === undefined) throw new Error('Base64 inválido');
    buffer = (buffer << 6) | v;
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      out[o++] = (buffer >> bits) & 0xff;
    }
  }
  return out.subarray(0, o);
}
