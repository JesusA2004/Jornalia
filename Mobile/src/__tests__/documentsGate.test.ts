import { actionLabel, decideGate, documentStatement, purposeFor, sortDocuments } from '@/features/documents/gate';
import { mergeSigned } from '@/features/documents/signedStore';
import type { PendingDocument, SignedDocument } from '@/types/contracts';

jest.mock('@/storage/db', () => ({ cacheGet: jest.fn(), cacheSet: jest.fn() }));

const doc = (o: Partial<PendingDocument>): PendingDocument => ({
  id: 1,
  code: 'CONVENIO_REGISTRO',
  title: 'Convenio de registro electrónico',
  version: '1.0',
  body_markdown: '# Convenio',
  pdf_url: null,
  published_at: '2026-11-20T15:00:00Z',
  ...o,
});

describe('convenio y aviso: bloqueo del marcaje', () => {
  const aviso = doc({ id: 2, code: 'AVISO_PRIVACIDAD', title: 'Aviso de privacidad', published_at: '2026-11-01T15:00:00Z' });
  const convenio = doc({ id: 1 });

  it('con documentos pendientes bloquea y presenta el convenio primero', () => {
    const d = decideGate({ kind: 'ok', docs: [aviso, convenio] }, null);
    expect(d.blocked).toBe(true);
    expect(d.docs.map((x) => x.code)).toEqual(['CONVENIO_REGISTRO', 'AVISO_PRIVACIDAD']);
    expect(d.fromCache).toBe(false);
  });

  it('sin pendientes no bloquea', () => {
    expect(decideGate({ kind: 'ok', docs: [] }, [convenio]).blocked).toBe(false);
  });

  it('sin conexión respeta lo último sabido (sigue bloqueado si había pendientes)', () => {
    expect(decideGate({ kind: 'network' }, [convenio])).toEqual({ blocked: true, docs: [convenio], fromCache: true });
    expect(decideGate({ kind: 'error' }, [convenio]).blocked).toBe(true);
    expect(decideGate({ kind: 'network' }, []).blocked).toBe(false);
    expect(decideGate({ kind: 'network' }, null).blocked).toBe(false);
  });

  it('servidor sin el endpoint (404) no bloquea', () => {
    expect(decideGate({ kind: 'unsupported' }, [convenio]).blocked).toBe(false);
  });

  it('propósito y verbo por documento; declaración del servidor', () => {
    expect(purposeFor(convenio)).toBe('registration_agreement');
    expect(purposeFor(aviso)).toBe('privacy_notice');
    expect(purposeFor(doc({ code: 'X', purpose: 'privacy_notice' }))).toBe('privacy_notice');
    expect(actionLabel('AVISO_PRIVACIDAD')).toBe('Aceptar');
    expect(actionLabel('CONVENIO_REGISTRO')).toBe('Firmar');
    expect(documentStatement(doc({ signature_statement: 'Acepto el convenio v1.0' }))).toBe('Acepto el convenio v1.0');
    expect(documentStatement(convenio)).toContain('ubicación');
  });

  it('ordena por fecha cuando no son convenio/aviso', () => {
    const a = doc({ id: 5, code: 'OTRO', published_at: '2026-11-10T00:00:00Z' });
    const b = doc({ id: 6, code: 'OTRO', published_at: '2026-11-01T00:00:00Z' });
    expect(sortDocuments([a, b]).map((x) => x.id)).toEqual([6, 5]);
  });

  it('constancias firmadas: servidor + teléfono sin duplicar, más reciente primero', () => {
    const s = (id: number, at: string): SignedDocument => ({ id, code: 'X', title: 't', version: '1', pdf_url: null, signed_at: at });
    const merged = mergeSigned([s(1, '2026-11-20T00:00:00Z')], [s(1, '2026-11-20T00:00:00Z'), s(2, '2026-11-22T00:00:00Z')]);
    expect(merged.map((d) => d.id)).toEqual([2, 1]);
  });
});
