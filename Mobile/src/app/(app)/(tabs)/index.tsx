import * as Crypto from 'expo-crypto';
import { router } from 'expo-router';
import * as SecureStore from 'expo-secure-store';
import { useEffect, useReducer } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { PUNCH_ICON } from '@/components/attendance';
import { LocationIndicator, PunchFeedback } from '@/components/feedback';
import { HelpButton } from '@/components/help';
import { BrandGradient, Logo, Mascot } from '@/components/ui/brand';
import { Icon } from '@/components/ui/Icon';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card, LoadingState, Pill } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { assertFaceAllowed, assertMethodAllowed, getDeviceAuthCapability, unlockDeviceSecret } from '@/features/attendance/deviceAuth';
import { faceStore, useFaceOutcome } from '@/features/face/faceStore';
import { useFaceStatus } from '@/features/face/useFaceStatus';
import { PunchFlowError } from '@/features/attendance/errors';
import { captureLocation } from '@/features/attendance/location';
import { performPunch } from '@/features/attendance/punchFlow';
import { initialPunchUiState, punchUiReducer, STEP_LABELS } from '@/features/attendance/punchReducer';
import { useToday } from '@/features/attendance/TodayProvider';
import { useWorkLocation } from '@/features/location/LocationProvider';
import { useSync } from '@/features/offline/SyncProvider';
import { useNow } from '@/hooks/useNow';
import { api, punchQueue } from '@/services/container';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { PunchType, Workday } from '@/types/contracts';
import {
  formatHoursCompact,
  formatTime,
  formatTodayLong,
  greeting,
  PUNCH_ACTION_LABEL,
  PUNCH_DONE_LABEL,
  PUNCH_SHORT_LABEL,
  WORKDAY_STATUS_LABEL,
  workdayTone,
} from '@/utils/format';
import { deviceTimezone } from '@/utils/time';

const STATUS_AFTER: Record<PunchType, Workday['status']> = {
  clock_in: 'working',
  clock_out: 'finished',
  break_start: 'on_break',
  break_end: 'working',
};

function availableTypes(w: Workday): PunchType[] {
  const byStatus: Partial<Record<Workday['status'], PunchType[]>> = {
    not_started: ['clock_in'],
    working: ['clock_out', 'break_start'],
    on_break: ['break_end'],
  };
  const list = [...(byStatus[w.status] ?? [])];
  if (w.next_expected && !list.includes(w.next_expected)) list.unshift(w.next_expected);
  if (w.next_expected) list.sort((a, b) => (a === w.next_expected ? -1 : b === w.next_expected ? 1 : 0));
  return list;
}

export default function HomeScreen() {
  const session = useSignedIn();
  const today = useToday();
  const sync = useSync();
  const loc = useWorkLocation();
  const { colors } = useTheme();
  const now = useNow(1000);
  const [ui, dispatch] = useReducer(punchUiReducer, initialPunchUiState);

  const { profile, secretProtection } = session;
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const serverNow = new Date(now.getTime() + today.serverOffsetMs);
  const firstName = profile.employee?.first_name ?? profile.user.name.split(' ')[0];
  const data = today.data;
  const policy = data?.policy ?? null;
  // Si hay marcajes guardados sin enviar, el siguiente paso se calcula desde el último de ellos.
  const lastPending = sync.items.filter((i) => i.status === 'pending').at(-1)?.request.type ?? null;
  const types = data ? availableTypes(lastPending ? { ...data.workday, status: STATUS_AFTER[lastPending], next_expected: null } : data.workday) : [];
  const primary = types[0] ?? null;
  const working = ui.phase === 'working';
  const last = data?.workday.punches.length ? data.workday.punches[data.workday.punches.length - 1] : null;
  // Jornada cerrada por la salida automática (marcaje del sistema).
  const autoClosed = !!data && data.workday.punches.some((p) => p.source === 'system' && p.type === 'clock_out') && data.workday.next_expected !== 'clock_in';

  const punch = async (type: PunchType, faceVerificationId: string | null = null) => {
    if (!policy || working) return;
    dispatch({ type: 'start', punchType: type });
    try {
      const capability = await getDeviceAuthCapability();
      const deviceUuid = await getOrCreateDeviceUuid();
      const userId = profile.user.id;
      const outcome = await performPunch(
        { type, deviceUuid, timezone: deviceTimezone(tz), policy, isOnline: sync.isOnline, faceVerificationId },
        {
          resolveMethod: () =>
            faceVerificationId ? assertFaceAllowed(secretProtection, policy) : assertMethodAllowed(secretProtection, policy, capability),
          captureLocation: () => captureLocation(policy.location_mode),
          unlockSecret: () => unlockDeviceSecret(userId, secretProtection!, PUNCH_ACTION_LABEL[type]),
          challenge: () => api.attendance.challenge(),
          submit: (body) => api.attendance.punch(body),
          queue: punchQueue,
          uuid: () => Crypto.randomUUID(),
          onStep: (step) => dispatch({ type: 'step', step }),
        },
      );
      dispatch({ type: 'success', outcome });
      if (outcome.kind === 'accepted') void today.refresh();
      void sync.reload();
    } catch (e) {
      const cancelled = e instanceof PunchFlowError && e.code === 'auth_cancelled';
      dispatch({ type: 'failure', message: e instanceof PunchFlowError || e instanceof ApiRequestError ? e.message : describeError(e), cancelled });
    }
  };

  // Verificación facial (motor del servidor): sólo si está disponible, enrolado y permitido.
  const face = useFaceStatus(sync.isOnline);
  const faceReady =
    !!policy && face.data?.available === true && face.data.enrolled && policy.allowed_auth_methods.includes('face_verification');
  const faceRequired = faceReady && !!policy?.face_verification_enabled;

  const startPunch = (type: PunchType, viaFace = faceRequired) => {
    if (viaFace) router.push({ pathname: '/rostro', params: { type } });
    else void punch(type);
  };

  // Resultado de la pantalla de rostro.
  const faceOutcome = useFaceOutcome();
  useEffect(() => {
    if (!faceOutcome) return;
    faceStore.set(null);
    void Promise.resolve().then(() =>
      faceOutcome.kind === 'match' ? punch(faceOutcome.type, faceOutcome.verificationId) : punch(faceOutcome.type),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [faceOutcome]);

  const outcome = ui.phase === 'done' ? ui.outcome : null;

  // Primer uso: pantalla de permiso de ubicación (una sola vez).
  const locReading = loc.reading;
  useEffect(() => {
    if (!locReading || locReading.foreground === 'granted' || !locReading.canAskForeground) return;
    void (async () => {
      if (await SecureStore.getItemAsync('location.prompted')) return;
      await SecureStore.setItemAsync('location.prompted', '1');
      router.push('/permiso-ubicacion');
    })();
  }, [locReading]);

  return (
    <Screen refreshing={today.refreshing} onRefresh={() => void today.refresh()}>
      <BrandGradient style={styles.header}>
        <View style={styles.headerRow}>
          <Logo width={96} />
          <HelpButton topic="inicio" light />
        </View>
        <AppText variant="display" tabular style={styles.clock} accessibilityRole="timer" accessibilityLabel={`Hora ${formatTime(serverNow, tz)}`}>
          {formatTime(serverNow, tz)}
        </AppText>
        <AppText variant="bodyStrong" style={styles.onBrand} numberOfLines={2}>
          {greeting(serverNow, tz)}, {firstName}
        </AppText>
        <AppText variant="caption" style={styles.onBrandMuted} numberOfLines={1}>
          {formatTodayLong(serverNow, tz)}
        </AppText>
      </BrandGradient>

      <LocationIndicator />

      {!profile.employee ? (
        <Card style={styles.center}>
          <Mascot pose="presenta" height={120} />
          <AppText variant="heading" style={{ textAlign: 'center' }}>
            Tu cuenta no está vinculada a un colaborador
          </AppText>
        </Card>
      ) : today.loading && !data ? (
        <LoadingState label="Cargando…" />
      ) : !data ? (
        <Banner tone="danger" title="Sin conexión con el servidor" action={{ label: 'Reintentar', onPress: () => void today.refresh() }} />
      ) : (
        <>
          <View style={styles.statusRow}>
            <View style={{ flexDirection: 'row', gap: spacing.sm, flexWrap: 'wrap', flex: 1 }}>
              <Pill label={WORKDAY_STATUS_LABEL[data.workday.status]} tone={workdayTone(data.workday.status)} />
              {data.workday.auto_clock_out_at && !autoClosed ? (
                <Pill label={`Salida automática ${formatTime(data.workday.auto_clock_out_at, tz)}`} tone="info" icon="event" />
              ) : null}
            </View>
            <AppText variant="metric" tabular>
              {formatHoursCompact(data.workday.worked_minutes)} h
            </AppText>
          </View>

          {ui.phase === 'error' ? (
            <Banner
              tone={ui.cancelled ? 'warning' : 'danger'}
              title={ui.cancelled ? 'Cancelado. No se marcó.' : 'No se marcó'}
              message={ui.cancelled ? undefined : ui.message}
              action={{ label: 'Intentar de nuevo', onPress: () => startPunch(ui.type) }}
            />
          ) : null}

          {secretProtection === null ? (
            <Banner tone="danger" icon="lock" title="Este teléfono no puede marcar" message="Pide a tu administrador que lo vuelva a vincular." />
          ) : null}

          {autoClosed ? (
            <Card style={styles.center}>
              <Icon name="event" size={48} color={colors.info} />
              <AppText variant="heading">Jornada cerrada</AppText>
              <AppText variant="caption" color="textMuted">
                Salida automática {data.workday.auto_clock_out_at ? formatTime(data.workday.auto_clock_out_at, tz) : ''}
              </AppText>
            </Card>
          ) : primary ? (
            <Pressable
              onPress={() => startPunch(primary)}
              disabled={working || !policy}
              accessibilityRole="button"
              accessibilityLabel={PUNCH_ACTION_LABEL[primary]}
              accessibilityState={{ busy: working, disabled: working || !policy }}
              style={({ pressed }) => ({ transform: [{ scale: pressed ? 0.98 : 1 }] })}>
              <BrandGradient style={styles.punch}>
                {working ? (
                  <>
                    <ActivityIndicator color="#000000" size="large" />
                    <AppText variant="heading" style={styles.onBrandCenter}>
                      {ui.phase === 'working' ? STEP_LABELS[ui.step] : ''}
                    </AppText>
                  </>
                ) : (
                  <>
                    <View style={styles.punchIcon}>
                      <Icon name={faceRequired ? 'face' : 'fingerprint'} size={56} color="#000000" />
                    </View>
                    <AppText variant="title" style={[styles.onBrandCenter, { fontSize: 30, lineHeight: 38 }]}>
                      {PUNCH_SHORT_LABEL[primary]}
                    </AppText>
                    <AppText variant="captionStrong" style={styles.onBrandMuted}>
                      {faceRequired ? 'Toca y mira a la cámara' : 'Toca y pon tu huella'}
                    </AppText>
                  </>
                )}
              </BrandGradient>
            </Pressable>
          ) : (
            <Card style={styles.center}>
              <Icon name="checkCircle" size={48} color={colors.success} />
              <AppText variant="heading">{data.workday.status === 'finished' ? 'Jornada terminada' : 'Nada por marcar'}</AppText>
            </Card>
          )}

          {!autoClosed && primary && faceReady && !faceRequired ? (
            <Button title="Marcar con rostro" icon="face" variant="secondary" size="lg" disabled={working} onPress={() => startPunch(primary, true)} />
          ) : null}

          {!autoClosed && types.slice(1).map((t) => (
            <Button key={t} title={PUNCH_ACTION_LABEL[t]} icon={PUNCH_ICON[t]} variant="secondary" size="lg" disabled={working} onPress={() => startPunch(t)} />
          ))}

          {last ? (
            <Pressable
              onPress={() => router.push({ pathname: '/comprobante/[uuid]', params: { uuid: last.uuid } })}
              accessibilityRole="button"
              style={({ pressed }) => [styles.row, { backgroundColor: colors.surface, borderColor: colors.border, opacity: pressed ? 0.7 : 1 }]}>
              <Icon name={PUNCH_ICON[last.type]} size={24} color={colors.text} />
              <View style={{ flex: 1 }}>
                <AppText variant="caption" color="textMuted" numberOfLines={1}>
                  Último marcaje
                </AppText>
                <AppText variant="bodyStrong" numberOfLines={2}>
                  {last.source === 'system' ? 'Salida automática' : PUNCH_SHORT_LABEL[last.type]}
                </AppText>
              </View>
              <AppText variant="heading" tabular>
                {formatTime(last.server_time, tz)}
              </AppText>
              <Icon name="chevronRight" size={18} color={colors.textSubtle} />
            </Pressable>
          ) : null}
        </>
      )}

      {sync.summary.pending > 0 || sync.summary.rejected > 0 ? (
        <Pressable
          onPress={() => router.push('/sincronizacion')}
          accessibilityRole="button"
          style={({ pressed }) => [styles.row, { backgroundColor: colors.warningBg, borderColor: colors.warningBg, opacity: pressed ? 0.7 : 1 }]}>
          <Icon name={sync.summary.rejected > 0 ? 'warning' : 'sync'} size={24} color={colors.warning} />
          <AppText variant="bodyStrong" style={{ flex: 1, color: colors.warning }} numberOfLines={1}>
            {sync.summary.rejected > 0 ? `${sync.summary.rejected} rechazado(s)` : `${sync.summary.pending} por enviar`}
          </AppText>
          <Icon name="chevronRight" size={18} color={colors.warning} />
        </Pressable>
      ) : !sync.isOnline ? (
        <Pill label="Sin internet" tone="warning" icon="offline" />
      ) : null}

      <PunchFeedback
        visible={!!outcome}
        kind={outcome?.kind === 'accepted' ? 'success' : 'queued'}
        title={outcome?.kind === 'accepted' ? PUNCH_DONE_LABEL[outcome.receipt.punch.type] : outcome ? 'Guardado sin internet' : ''}
        subtitle={outcome?.kind === 'accepted' ? formatTime(outcome.receipt.punch.server_time, tz) : outcome ? 'Se enviará al conectar' : undefined}
        onClose={() => dispatch({ type: 'reset' })}
        extraAction={
          outcome?.kind === 'accepted'
            ? {
                label: 'Ver comprobante',
                onPress: () => {
                  const uuid = outcome.receipt.punch.uuid;
                  dispatch({ type: 'reset' });
                  router.push({ pathname: '/comprobante/[uuid]', params: { uuid } });
                },
              }
            : undefined
        }
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { borderRadius: radius.xl, paddingHorizontal: spacing.xl, paddingTop: spacing.md, paddingBottom: spacing.lg },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  clock: { color: '#000000', marginTop: spacing.xs },
  onBrand: { color: '#000000' },
  onBrandCenter: { color: '#000000', textAlign: 'center' },
  onBrandMuted: { color: 'rgba(0,0,0,0.7)' },
  statusRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  punch: { borderRadius: radius.xl, minHeight: 220, alignItems: 'center', justifyContent: 'center', gap: spacing.sm, padding: spacing.xl },
  punchIcon: { width: 96, height: 96, borderRadius: 48, backgroundColor: 'rgba(255,255,255,0.5)', alignItems: 'center', justifyContent: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 60, paddingHorizontal: spacing.lg, borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth },
  center: { alignItems: 'center', gap: spacing.sm },
});
