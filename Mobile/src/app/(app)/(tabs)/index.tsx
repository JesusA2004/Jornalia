import * as Crypto from 'expo-crypto';
import { router, useFocusEffect } from 'expo-router';
import * as SecureStore from 'expo-secure-store';
import { useCallback, useEffect, useReducer } from 'react';
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
import { PunchFlowError } from '@/features/attendance/errors';
import { deviceIntegrity, withLocationSignal } from '@/features/attendance/integrity';
import { captureLocation, locationWanted } from '@/features/attendance/location';
import { performPunch } from '@/features/attendance/punchFlow';
import { initialPunchUiState, punchUiReducer, STEP_LABELS } from '@/features/attendance/punchReducer';
import { useToday } from '@/features/attendance/TodayProvider';
import { faceStore, useFaceOutcome } from '@/features/face/faceStore';
import { useFaceStatus } from '@/features/face/useFaceStatus';
import { useWorkLocation } from '@/features/location/LocationProvider';
import { STATUS_AFTER_PUNCH } from '@/features/location/workLocation';
import { receiptFromPunch, saveLocalReceipt } from '@/features/notifications/receipts';
import { useSync } from '@/features/offline/SyncProvider';
import { dueInfo, dueLabel } from '@/features/requests/rectification';
import { fetchPendingSignatures } from '@/features/requests/rectificationsApi';
import { useNow } from '@/hooks/useNow';
import { useQuery } from '@/hooks/useQuery';
import { api, punchQueue } from '@/services/container';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { AttendancePolicy, PunchType, Workday } from '@/types/contracts';
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

const ON_BRAND = '#FFFFFF';
const ON_BRAND_MUTED = 'rgba(255,255,255,0.8)';

/** Tipos de marcaje posibles según el estado (el servidor valida la secuencia). */
function availableTypes(w: Pick<Workday, 'status' | 'next_expected'>): PunchType[] {
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

/**
 * Inicio: estado de la jornada y una acción principal de marcaje grande. No
 * existe salida automática: si falta un marcaje queda como omisión y se
 * corrige con una rectificación firmada por el colaborador.
 */
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
  const policy = (data?.policy ?? null) as AttendancePolicy | null;
  // Con marcajes guardados sin enviar, el siguiente paso se calcula desde el último de ellos.
  const lastPending = sync.items.filter((i) => i.status === 'pending').at(-1)?.request.type ?? null;
  const status = lastPending ? STATUS_AFTER_PUNCH[lastPending] : (data?.workday.status ?? null);
  const types = data ? availableTypes(lastPending ? { status: status!, next_expected: null } : data.workday) : [];
  const primary = types[0] ?? null;
  const working = ui.phase === 'working';
  const last = data?.workday.punches.length ? data.workday.punches[data.workday.punches.length - 1] : null;

  // Correcciones que esperan tu firma.
  const toSign = useQuery(fetchPendingSignatures, [], { cacheKey: 'rectifications.por_firmar' });
  const refreshToSign = toSign.refresh;
  useFocusEffect(
    useCallback(() => {
      void refreshToSign();
    }, [refreshToSign]),
  );
  const signList = toSign.data ?? [];
  const firstDue = signList[0] ? dueInfo(signList[0], now.getTime()) : null;

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
          submit: async (body) => api.attendance.punch(body, withLocationSignal(await deviceIntegrity(), body.location)),
          queue: punchQueue,
          uuid: () => Crypto.randomUUID(),
          onStep: (step) => dispatch({ type: 'step', step }),
        },
      );
      dispatch({ type: 'success', outcome });
      if (outcome.kind === 'accepted') {
        // Acuse en la bandeja de la app aunque el SO no permita notificaciones.
        void saveLocalReceipt(receiptFromPunch(outcome.receipt));
        void today.refresh();
      }
      void sync.reload();
    } catch (e) {
      const cancelled = e instanceof PunchFlowError && e.code === 'auth_cancelled';
      dispatch({ type: 'failure', message: e instanceof PunchFlowError || e instanceof ApiRequestError ? e.message : describeError(e), cancelled });
    }
  };

  // Verificación facial (motor del servidor): sólo si está disponible, enrolado y permitido.
  const face = useFaceStatus(sync.isOnline);
  const faceReady = !!policy && face.data?.available === true && face.data.enrolled && policy.allowed_auth_methods.includes('face_verification');
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

  // Primer uso: pantalla de permiso de ubicación (una sola vez), sólo si el puesto la usa.
  const locReading = loc.reading;
  const wantsLocation = !!policy && (locationWanted(policy.location_mode) || policy.location_tracking === 'during_shift');
  useEffect(() => {
    if (!wantsLocation || !locReading || locReading.foreground === 'granted' || !locReading.canAskForeground) return;
    void (async () => {
      if (await SecureStore.getItemAsync('location.prompted')) return;
      await SecureStore.setItemAsync('location.prompted', '1');
      router.push('/permiso-ubicacion');
    })();
  }, [locReading, wantsLocation]);

  return (
    <Screen refreshing={today.refreshing} onRefresh={() => void today.refresh()} sharingBar={false}>
      <BrandGradient style={styles.header}>
        <View style={styles.headerRow}>
          <Logo width={92} />
          <View style={{ flexDirection: 'row', gap: spacing.sm }}>
            <Pressable
              onPress={() => router.push('/notificaciones')}
              accessibilityRole="button"
              accessibilityLabel="Avisos y acuses"
              hitSlop={8}
              style={({ pressed }) => [styles.roundBtn, { opacity: pressed ? 0.7 : 1 }]}>
              <Icon name="bell" size={22} color={ON_BRAND} />
            </Pressable>
            <HelpButton topic="inicio" light />
          </View>
        </View>
        <AppText variant="display" tabular style={styles.clock} accessibilityRole="timer" accessibilityLabel={`Hora ${formatTime(serverNow, tz)}`}>
          {formatTime(serverNow, tz)}
        </AppText>
        <AppText variant="bodyStrong" style={{ color: ON_BRAND }} numberOfLines={2}>
          {greeting(serverNow, tz)}, {firstName}
        </AppText>
        <AppText variant="caption" style={{ color: ON_BRAND_MUTED }} numberOfLines={1}>
          {formatTodayLong(serverNow, tz)}
        </AppText>
        <View style={[styles.goldLine, { backgroundColor: colors.gold }]} />
      </BrandGradient>

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
            <Pill label={WORKDAY_STATUS_LABEL[status ?? data.workday.status]} tone={workdayTone(status ?? data.workday.status)} />
            <View style={{ alignItems: 'flex-end' }}>
              <AppText variant="metric" tabular>
                {formatHoursCompact(data.workday.worked_minutes)} h
              </AppText>
              <AppText variant="caption" color="textMuted">
                trabajadas hoy
              </AppText>
            </View>
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
            <Banner tone="danger" icon="lock" title="Este teléfono no puede marcar" message="Pide a RH que lo vuelva a vincular." />
          ) : null}

          {primary ? (
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
                    <ActivityIndicator color={ON_BRAND} size="large" />
                    <AppText variant="heading" style={styles.onBrandCenter}>
                      {ui.phase === 'working' ? STEP_LABELS[ui.step] : ''}
                    </AppText>
                  </>
                ) : (
                  <>
                    <View style={[styles.punchIcon, { borderColor: colors.gold }]}>
                      <Icon name={faceRequired ? 'face' : 'fingerprint'} size={56} color={ON_BRAND} />
                    </View>
                    <AppText variant="title" style={[styles.onBrandCenter, { fontSize: 30, lineHeight: 38 }]}>
                      {PUNCH_SHORT_LABEL[primary]}
                    </AppText>
                    <AppText variant="captionStrong" style={{ color: ON_BRAND_MUTED, textAlign: 'center' }}>
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

          {primary && faceReady && !faceRequired ? (
            <Button title="Marcar con rostro" icon="face" variant="secondary" size="lg" disabled={working} onPress={() => startPunch(primary, true)} />
          ) : null}

          {types.slice(1).map((t) => (
            <Button
              key={t}
              title={PUNCH_ACTION_LABEL[t]}
              icon={t === 'break_start' ? 'meal' : PUNCH_ICON[t]}
              variant="secondary"
              size="lg"
              disabled={working}
              onPress={() => startPunch(t)}
            />
          ))}
        </>
      )}

      <LocationIndicator />

      {signList.length ? (
        <Pressable
          onPress={() => router.push(signList.length === 1 ? { pathname: '/rectificaciones/[id]', params: { id: String(signList[0]!.id) } } : '/firmas')}
          accessibilityRole="button"
          style={({ pressed }) => [styles.row, { backgroundColor: colors.goldSoft, borderColor: colors.gold, opacity: pressed ? 0.75 : 1 }]}>
          <Icon name="signature" size={24} color={colors.goldStrong} />
          <View style={{ flex: 1 }}>
            <AppText variant="bodyStrong" style={{ color: colors.goldStrong }} numberOfLines={1}>
              {signList.length === 1 ? '1 corrección por firmar' : `${signList.length} correcciones por firmar`}
            </AppText>
            {firstDue ? (
              <AppText variant="caption" color={firstDue.urgent || firstDue.overdue ? 'danger' : 'textMuted'} numberOfLines={1}>
                {dueLabel(firstDue)}
              </AppText>
            ) : null}
          </View>
          <Icon name="chevronRight" size={18} color={colors.goldStrong} />
        </Pressable>
      ) : null}

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
            <AppText variant="bodyStrong" numberOfLines={1}>
              {PUNCH_SHORT_LABEL[last.type]}
            </AppText>
          </View>
          <AppText variant="heading" tabular>
            {formatTime(last.server_time, tz)}
          </AppText>
          <Icon name="chevronRight" size={18} color={colors.textSubtle} />
        </Pressable>
      ) : null}

      {sync.summary.pending > 0 || sync.summary.rejected > 0 ? (
        <Pressable
          onPress={() => router.push('/sincronizacion')}
          accessibilityRole="button"
          style={({ pressed }) => [styles.row, { backgroundColor: colors.warningBg, borderColor: colors.warningBg, opacity: pressed ? 0.7 : 1 }]}>
          <Icon name={sync.summary.rejected > 0 ? 'warning' : 'sync'} size={24} color={colors.warning} />
          <AppText variant="bodyStrong" style={{ flex: 1, color: colors.warning }} numberOfLines={1}>
            {sync.summary.rejected > 0 ? `${sync.summary.rejected} requiere(n) revisión` : `${sync.summary.pending} por enviar`}
          </AppText>
          <Icon name="chevronRight" size={18} color={colors.warning} />
        </Pressable>
      ) : !sync.isOnline ? (
        <Pill label="Sin internet" tone="warning" icon="offline" />
      ) : null}

      <PunchFeedback
        visible={!!outcome}
        kind={outcome?.kind === 'accepted' ? 'success' : 'queued'}
        title={outcome?.kind === 'accepted' ? PUNCH_DONE_LABEL[outcome.receipt.punch.type] : outcome ? 'Guardado en tu teléfono' : ''}
        subtitle={
          outcome?.kind === 'accepted'
            ? `${formatTime(outcome.receipt.punch.server_time, tz)} · Folio ${outcome.receipt.receipt_code}`
            : outcome
              ? `Aún no registrado. Se enviará al conectar (${formatTime(outcome.item.request.device_time, tz)}).`
              : undefined
        }
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
  header: { borderRadius: radius.xl, paddingHorizontal: spacing.xl, paddingTop: spacing.md, paddingBottom: spacing.lg, overflow: 'hidden' },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  roundBtn: { width: 48, height: 48, borderRadius: 24, backgroundColor: 'rgba(255,255,255,0.18)', alignItems: 'center', justifyContent: 'center' },
  goldLine: { position: 'absolute', left: 0, right: 0, bottom: 0, height: 4 },
  clock: { color: ON_BRAND, marginTop: spacing.xs },
  onBrandCenter: { color: ON_BRAND, textAlign: 'center' },
  statusRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.md },
  punch: { borderRadius: radius.xl, minHeight: 220, alignItems: 'center', justifyContent: 'center', gap: spacing.sm, padding: spacing.xl },
  punchIcon: {
    width: 104,
    height: 104,
    borderRadius: 52,
    borderWidth: 3,
    backgroundColor: 'rgba(255,255,255,0.14)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 60, paddingHorizontal: spacing.lg, borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth },
  center: { alignItems: 'center', gap: spacing.sm },
});
