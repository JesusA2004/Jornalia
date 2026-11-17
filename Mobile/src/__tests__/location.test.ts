import {
  buildPoint,
  filterSamples,
  isSignificant,
  parseSettings,
  trackingKey,
  windowActive,
  type LocationSample,
} from '@/features/location/workLocation';
import type { LocationSettings } from '@/types/contracts';
import { formatMinutes } from '@/utils/format';
import { wallTimeToUtcIso } from '@/utils/time';

const at = (iso: string, lat = 19.4326, lon = -99.1332, acc = 10): LocationSample => ({ latitude: lat, longitude: lon, accuracy_m: acc, captured_at: iso });

const settings = (w: Partial<LocationSettings['window']> = {}): LocationSettings => ({
  window: {
    enabled: true,
    active_now: true,
    starts_at: '2026-11-17T13:00:00Z',
    ends_at: '2026-11-18T00:00:00Z',
    next_start_at: '2026-11-18T13:00:00Z',
    timezone: 'America/Mexico_City',
    ...w,
  },
  interval_s: { foreground: 15, background: 60 },
  distance_m: { foreground: 10, background: 50 },
});

describe('ubicación de trabajo: filtro', () => {
  it('descarta coordenadas imposibles, (0,0) y precisión > 500 m', () => {
    expect(isSignificant(null, at('2026-11-17T15:00:00Z', 91, 0), 'foreground')).toBe(false);
    expect(isSignificant(null, at('2026-11-17T15:00:00Z', 0, 0), 'foreground')).toBe(false);
    expect(isSignificant(null, at('2026-11-17T15:00:00Z', 19, -99, 800), 'foreground')).toBe(false);
    expect(isSignificant(null, at('2026-11-17T15:00:00Z'), 'foreground')).toBe(true);
  });

  it('sin movimiento sólo pasa el latido', () => {
    const prev = { latitude: 19.4326, longitude: -99.1332, captured_at: '2026-11-17T15:00:00Z' };
    expect(isSignificant(prev, at('2026-11-17T15:00:30Z'), 'foreground')).toBe(false);
    expect(isSignificant(prev, at('2026-11-17T15:01:00Z'), 'foreground')).toBe(true);
    expect(isSignificant(prev, at('2026-11-17T15:04:00Z'), 'background')).toBe(false);
    expect(isSignificant(prev, at('2026-11-17T15:05:00Z'), 'background')).toBe(true);
  });

  it('movimiento significativo según cadencia (≈0.0002° ≈ 22 m)', () => {
    const prev = { latitude: 19.4326, longitude: -99.1332, captured_at: '2026-11-17T15:00:00Z' };
    expect(isSignificant(prev, at('2026-11-17T15:00:20Z', 19.4328), 'foreground')).toBe(true);
    expect(isSignificant(prev, at('2026-11-17T15:00:20Z', 19.4328), 'background')).toBe(false);
  });

  it('una muestra repetida o más antigua no se envía dos veces', () => {
    const r = filterSamples(null, [at('2026-11-17T15:00:00Z'), at('2026-11-17T15:00:00Z')], 'foreground');
    expect(r.accepted).toHaveLength(1);
  });

  it('construye el punto del contrato sin inventar valores', () => {
    const p = buildPoint({ ...at('2026-11-17T15:00:00.456Z'), heading: -1, speed_mps: -1, is_mocked: false }, 'background', 'u-1');
    expect(p).toMatchObject({ uuid: 'u-1', heading: null, speed_mps: null, app_state: 'background', is_mocked: false, captured_at: '2026-11-17T15:00:00Z' });
  });
});

describe('ubicación de trabajo: ventana de jornada', () => {
  it('sin ajustes decide el servidor (comparte)', () => {
    expect(windowActive(null, Date.now())).toBe(true);
  });
  it('dentro / fuera / próximo inicio', () => {
    const s = settings();
    expect(windowActive(s, Date.parse('2026-11-17T15:00:00Z'))).toBe(true);
    expect(windowActive(s, Date.parse('2026-11-18T02:00:00Z'))).toBe(false);
    expect(windowActive(s, Date.parse('2026-11-18T13:30:00Z'))).toBe(true);
  });
  it('enabled=false nunca comparte', () => {
    expect(windowActive(settings({ enabled: false }), Date.parse('2026-11-17T15:00:00Z'))).toBe(false);
  });
  it('valida ajustes no confiables', () => {
    expect(parseSettings({})).toBeNull();
    expect(parseSettings(settings())?.interval_s.background).toBe(60);
  });
  it('estado visible honesto', () => {
    const base = { available: true, foreground: 'granted' as const, background: 'granted' as const, gpsOn: true, running: true, notRequired: false, outsideWindow: false };
    expect(trackingKey(base)).toBe('active');
    expect(trackingKey({ ...base, outsideWindow: true })).toBe('paused_window');
    expect(trackingKey({ ...base, foreground: 'denied' })).toBe('no_permission');
    expect(trackingKey({ ...base, background: 'denied' })).toBe('active_foreground_only');
  });
});

describe('utilidades de tiempo', () => {
  it('hora de reloj en CDMX → UTC', () => {
    expect(wallTimeToUtcIso('2026-11-17', 8, 0, 'America/Mexico_City')).toBe('2026-11-17T14:00:00Z');
  });
  it('minutos enteros legibles', () => {
    expect(formatMinutes(45)).toBe('45 min');
    expect(formatMinutes(425)).toBe('7 h 05 min');
  });
});
