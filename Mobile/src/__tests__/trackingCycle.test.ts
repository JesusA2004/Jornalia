import {
  type LocationSample,
  type SqlExecutor,
  STATUS_AFTER_PUNCH,
  storeSamples,
  trackingKey,
  trackingPhase,
  type TrackingInput,
} from '@/features/location/workLocation';
import type { LocationSettings, PunchType, WorkdayStatus } from '@/types/contracts';

/** Base SQLite mínima: sólo cuenta los puntos encolados. */
function fakeDb() {
  const queued: string[] = [];
  const db: SqlExecutor = {
    runAsync: async (sql, params) => {
      if (sql.startsWith('INSERT OR IGNORE INTO location_queue')) {
        queued.push(String(params[1]));
        return { changes: 1 };
      }
      return { changes: sql.startsWith('INSERT') ? 1 : 0 };
    },
    getFirstAsync: async <T,>(sql: string) =>
      (sql.includes('COUNT') ? { n: queued.length } : { last_json: null, last_sent_at: null, last_error: null, last_error_at: null }) as T,
    getAllAsync: async <T,>() => [] as T[],
  };
  return { db, queued };
}

const settings = (enabled = true): LocationSettings => ({
  window: {
    enabled,
    active_now: false,
    starts_at: '2026-11-17T13:00:00Z',
    ends_at: '2026-11-17T15:00:00Z',
    next_start_at: '2026-11-18T13:00:00Z',
    timezone: 'America/Mexico_City',
  },
  interval_s: { foreground: 15, background: 60 },
  distance_m: { foreground: 10, background: 50 },
});

describe('seguimiento de ubicación: ciclo de la jornada', () => {
  /** Aplica una secuencia de marcajes y devuelve la fase tras cada uno. */
  const cycle = (mode: 'on_punch' | 'during_shift', punches: PunchType[], paused: ('break' | 'meal')[] = ['break', 'meal']) => {
    let status: WorkdayStatus = 'not_started';
    const phases = [trackingPhase(mode, status, paused)];
    for (const p of punches) {
      status = STATUS_AFTER_PUNCH[p];
      phases.push(trackingPhase(mode, status, paused));
    }
    return phases;
  };

  it('«during_shift»: inicia con la entrada, se pausa en descanso/comida, se reanuda y se detiene con la salida', () => {
    expect(cycle('during_shift', ['clock_in', 'break_start', 'break_end', 'clock_out'])).toEqual(['off', 'active', 'paused', 'active', 'off']);
  });

  it('«on_punch»: nunca hay seguimiento (sólo ubicación al marcar)', () => {
    expect(cycle('on_punch', ['clock_in', 'break_start', 'break_end', 'clock_out'])).toEqual(['off', 'off', 'off', 'off', 'off']);
    expect(trackingPhase(null, 'working')).toBe('off');
  });

  it('respeta los estados de pausa configurados y la comida', () => {
    expect(trackingPhase('during_shift', 'on_break', [])).toBe('active');
    expect(trackingPhase('during_shift', 'on_meal', ['meal'])).toBe('paused');
    expect(trackingPhase('during_shift', 'incomplete')).toBe('off');
    expect(trackingPhase('during_shift', 'rest_day')).toBe('off');
  });

  it('salida omitida: corta tras la contingencia sin generar marcajes', () => {
    const opts = { shiftEndsAt: '2026-11-17T23:00:00Z', contingencyMinutes: 60 };
    expect(trackingPhase('during_shift', 'working', ['break'], { ...opts, now: Date.parse('2026-11-17T23:30:00Z') })).toBe('active');
    expect(trackingPhase('during_shift', 'working', ['break'], { ...opts, now: Date.parse('2026-11-18T00:01:00Z') })).toBe('off');
  });

  it('indicador visible por estado', () => {
    const base: TrackingInput = {
      available: true,
      foreground: 'granted',
      background: 'granted',
      gpsOn: true,
      running: true,
      notRequired: false,
      outsideWindow: false,
    };
    expect(trackingKey({ ...base, mode: 'during_shift', phase: 'active' })).toBe('active');
    expect(trackingKey({ ...base, mode: 'during_shift', phase: 'paused' })).toBe('paused_break');
    expect(trackingKey({ ...base, mode: 'during_shift', phase: 'off' })).toBe('off_shift');
    expect(trackingKey({ ...base, mode: 'on_punch', phase: 'off' })).toBe('on_punch_only');
    expect(trackingKey({ ...base, mode: 'during_shift', phase: 'active', background: 'denied' })).toBe('active_foreground_only');
    expect(trackingKey({ ...base, mode: 'during_shift', phase: 'active', foreground: 'denied' })).toBe('no_permission');
  });
});

describe('seguimiento de ubicación: puntos guardados', () => {
  const sample = (iso: string, mocked: boolean | null = null): LocationSample => ({
    latitude: 19.4326,
    longitude: -99.1332,
    accuracy_m: 12,
    captured_at: iso,
    is_mocked: mocked,
  });
  const NOW = Date.parse('2026-11-17T20:00:00Z');

  it('gobernado por la jornada, guarda aunque la ventana anterior del servidor ya cerró', async () => {
    const { db, queued } = fakeDb();
    let n = 0;
    const uuid = () => `p-${++n}`;
    expect(await storeSamples(db, 1, [sample('2026-11-17T19:59:00Z')], 'background', uuid, NOW, settings(), false)).toBe(0);
    expect(await storeSamples(db, 1, [sample('2026-11-17T19:59:00Z')], 'background', uuid, NOW, settings(), true)).toBe(1);
    expect(queued).toEqual(['p-1']);
  });

  it('si el servidor dice que no se requiere, no guarda nada', async () => {
    const { db } = fakeDb();
    expect(await storeSamples(db, 1, [sample('2026-11-17T19:59:00Z')], 'background', () => 'x', NOW, settings(false), true)).toBe(0);
  });

  it('conserva la marca de ubicación simulada que reporta el SO', async () => {
    const points: string[] = [];
    const { db } = fakeDb();
    const spy: SqlExecutor = {
      ...db,
      runAsync: async (sql, params) => {
        if (sql.startsWith('INSERT OR IGNORE INTO location_queue')) points.push(String(params[2]));
        return db.runAsync(sql, params);
      },
    };
    await storeSamples(spy, 1, [sample('2026-11-17T19:59:00Z', true)], 'foreground', () => 'm', NOW, null, true);
    expect(JSON.parse(points[0]!).is_mocked).toBe(true);
  });
});
