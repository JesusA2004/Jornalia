import * as Crypto from 'expo-crypto';
import { useCallback, useEffect, useReducer, useState } from 'react';
import { ActivityIndicator, Alert, Modal, Pressable, StyleSheet, View } from 'react-native';

import { Logo } from '@/components/ui/brand';
import { TextField } from '@/components/ui/fields';
import { Icon } from '@/components/ui/Icon';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card, Pill } from '@/components/ui/primitives';
import { captureLocation, locationPermissionStatus } from '@/features/attendance/location';
import { autoResetMs, initialKioskFlow, kioskReducer } from '@/features/kiosk/flow';
import { KioskFaceStep } from '@/features/kiosk/KioskFaceStep';
import { useKiosk } from '@/features/kiosk/KioskProvider';
import { KioskPunchError } from '@/features/kiosk/punch';
import { verifyExitCode } from '@/features/kiosk/storage';
import { DigitsDisplay, Keypad, KIOSK_HELP, KIOSK_TYPES, KioskHelpButton, kioskTypeInfo } from '@/features/kiosk/ui';
import { useNow } from '@/hooks/useNow';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { LocationEvidence } from '@/types/contracts';
import { formatTime, formatTodayLong } from '@/utils/format';
import { isoNow } from '@/utils/time';

/** Ubicación del kiosco sólo si ya hay permiso; nunca bloquea el marcaje (máx. 6 s). */
async function kioskLocation(): Promise<LocationEvidence | null> {
  try {
    if ((await locationPermissionStatus()) !== 'granted') return null;
    return await Promise.race([
      captureLocation('record'),
      new Promise<null>((r) => setTimeout(() => r(null), 6000)),
    ]);
  } catch {
    return null;
  }
}

/**
 * Pantalla compartida de marcaje del kiosco. Nunca muestra datos de otros
 * colaboradores: sólo el resultado del marcaje recién hecho, y vuelve sola al
 * inicio borrando número y NIP de memoria.
 */
export default function KioskScreen() {
  const k = useKiosk();
  const { colors } = useTheme();
  const now = useNow(1000);
  const [flow, dispatch] = useReducer(kioskReducer, initialKioskFlow);
  const [exitOpen, setExitOpen] = useState(false);
  const kiosk = k.kiosk;
  const tz = kiosk?.timezone ?? 'America/Mexico_City';
  const serverNow = new Date(now.getTime() + k.serverOffsetMs);

  // Regreso automático a inicio (inactividad o tras la confirmación).
  useEffect(() => {
    const ms = autoResetMs(flow);
    if (ms === null) return;
    const t = setTimeout(() => dispatch({ type: 'reset' }), ms);
    return () => clearTimeout(t);
  }, [flow]);

  // Envío al elegir el tipo.
  useEffect(() => {
    if (flow.step !== 'sending') return;
    let cancelled = false;
    void (async () => {
      const location = await kioskLocation();
      try {
        const outcome = await k.punch({
          employee_number: flow.employee,
          pin: flow.pin,
          type: flow.type,
          client_uuid: Crypto.randomUUID(),
          device_time: isoNow(),
          timezone: tz,
          location,
          captured_offline: false,
          face_verification_id: flow.faceId,
        });
        if (!cancelled) dispatch({ type: 'success', outcome });
      } catch (e) {
        if (cancelled) return;
        const err = e instanceof KioskPunchError ? e : new KioskPunchError('No se registró el marcaje.', 'idle');
        dispatch({ type: 'failure', message: err.message, retry: err.retry });
      }
    })();
    return () => {
      cancelled = true;
    };
    // Sólo al entrar en «enviando».
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [flow.step]);

  const onFaceMatch = useCallback((id: string) => dispatch({ type: 'faceOk', id }), []);
  const onFaceFail = useCallback((message: string) => dispatch({ type: 'failure', message, retry: 'pin' }), []);
  const onFaceCancel = useCallback(() => dispatch({ type: 'reset' }), []);

  return (
    <Screen edges={['top', 'bottom']} contentStyle={{ flexGrow: 1 }}>
      <View style={styles.header}>
        <Pressable
          onLongPress={() => setExitOpen(true)}
          delayLongPress={2000}
          accessibilityRole="image"
          accessibilityLabel="Mr. Lana"
          accessibilityHint="Mantén presionado para salir del modo kiosco">
          <Logo width={110} />
        </Pressable>
        <View style={{ flexDirection: 'row', gap: spacing.sm, alignItems: 'center' }}>
          {!k.isOnline ? <Pill label="Sin internet" tone="warning" icon="offline" /> : null}
          {k.summary.pending > 0 ? <Pill label={`${k.summary.pending} por enviar`} tone="warning" icon="sync" /> : null}
          <KioskHelpButton topic={KIOSK_HELP} />
        </View>
      </View>

      <View style={{ alignItems: 'center' }}>
        <AppText variant="display" tabular accessibilityRole="timer" accessibilityLabel={`Hora ${formatTime(serverNow, tz)}`}>
          {formatTime(serverNow, tz)}
        </AppText>
        <AppText variant="bodyStrong" color="textMuted" numberOfLines={1}>
          {formatTodayLong(serverNow, tz)}
        </AppText>
        <AppText variant="heading" numberOfLines={2} style={{ textAlign: 'center', color: colors.gold }}>
          {kiosk?.branch ?? kiosk?.work_center ?? kiosk?.name ?? ''}
        </AppText>
      </View>

      {flow.step === 'idle' ? (
        <Pressable
          onPress={() => dispatch({ type: 'start' })}
          accessibilityRole="button"
          accessibilityLabel="Marcar asistencia"
          style={({ pressed }) => [styles.start, { backgroundColor: colors.primary, opacity: pressed ? 0.9 : 1 }]}>
          <Icon name="fingerprint" size={72} color={colors.onPrimary} />
          <AppText variant="title" style={{ color: colors.onPrimary, fontSize: 34, lineHeight: 42 }}>
            Marcar
          </AppText>
        </Pressable>
      ) : null}

      {flow.step === 'number' || flow.step === 'pin' ? (
        <View style={{ gap: spacing.lg }}>
          <AppText variant="title" style={{ textAlign: 'center' }} accessibilityRole="header">
            {flow.step === 'number' ? 'Número de empleado' : 'Tu NIP'}
          </AppText>
          <DigitsDisplay value={flow.digits} masked={flow.step === 'pin'} placeholder={flow.step === 'number' ? 'Número' : 'NIP'} />
          <Keypad
            onDigit={(d) => dispatch({ type: 'digit', d })}
            onBackspace={() => dispatch({ type: 'backspace' })}
            onNext={() => dispatch({ type: 'next', requiresFace: !!kiosk?.requires_face })}
            nextEnabled={flow.step === 'number' ? flow.digits.length > 0 : flow.digits.length >= 4}
          />
          <Button title={flow.step === 'number' ? 'Cancelar' : 'Atrás'} variant="ghost" size="lg" onPress={() => dispatch({ type: 'back' })} />
        </View>
      ) : null}

      {flow.step === 'face' ? <KioskFaceStep employee={flow.employee} pin={flow.pin} onMatch={onFaceMatch} onFail={onFaceFail} onCancel={onFaceCancel} /> : null}

      {flow.step === 'type' ? (
        <View style={{ gap: spacing.md }}>
          <AppText variant="title" style={{ textAlign: 'center' }} accessibilityRole="header">
            ¿Qué vas a marcar?
          </AppText>
          <View style={styles.grid}>
            {KIOSK_TYPES.map((t) => (
              <Pressable
                key={t.type}
                onPress={() => dispatch({ type: 'choose', punchType: t.type })}
                accessibilityRole="button"
                accessibilityLabel={t.label}
                style={({ pressed }) => [
                  styles.typeBtn,
                  {
                    backgroundColor: t.type === 'clock_in' || t.type === 'clock_out' ? colors.primary : colors.surface,
                    borderColor: t.type === 'clock_in' || t.type === 'clock_out' ? colors.primary : colors.border,
                    opacity: pressed ? 0.8 : 1,
                  },
                ]}>
                <Icon name={t.icon} size={44} color={t.type === 'clock_in' || t.type === 'clock_out' ? colors.onPrimary : colors.text} />
                <AppText
                  variant="heading"
                  style={{ color: t.type === 'clock_in' || t.type === 'clock_out' ? colors.onPrimary : colors.text, textAlign: 'center' }}
                  numberOfLines={2}>
                  {t.label}
                </AppText>
              </Pressable>
            ))}
          </View>
          <Button title="Cancelar" variant="ghost" size="lg" onPress={() => dispatch({ type: 'back' })} />
        </View>
      ) : null}

      {flow.step === 'sending' ? (
        <View style={styles.center}>
          <ActivityIndicator size="large" color={colors.text} />
          <AppText variant="heading">Registrando…</AppText>
        </View>
      ) : null}

      {flow.step === 'error' ? (
        <Card style={styles.center}>
          <Icon name="error" size={64} color={colors.danger} />
          <AppText variant="title" style={{ textAlign: 'center' }} accessibilityLiveRegion="assertive">
            {flow.message}
          </AppText>
          <AppText variant="bodyStrong" color="textMuted">
            No se registró el marcaje
          </AppText>
          <Button title="Intentar de nuevo" icon="refresh" size="lg" onPress={() => dispatch({ type: 'retry' })} style={{ alignSelf: 'stretch' }} />
          <Button title="Salir" variant="ghost" size="lg" onPress={() => dispatch({ type: 'reset' })} />
        </Card>
      ) : null}

      <Modal visible={flow.step === 'done'} animationType="fade" onRequestClose={() => dispatch({ type: 'reset' })} statusBarTranslucent>
        {flow.step === 'done' ? (
          <Pressable
            style={[styles.done, { backgroundColor: flow.outcome.kind === 'confirmed' ? colors.successBg : colors.warningBg }]}
            onPress={() => dispatch({ type: 'reset' })}
            accessibilityRole="button"
            accessibilityLabel="Terminar">
            <View style={[styles.doneCircle, { backgroundColor: flow.outcome.kind === 'confirmed' ? colors.success : colors.warning }]}>
              <Icon name={flow.outcome.kind === 'confirmed' ? 'check' : 'pending'} size={110} color="#FFFFFF" />
            </View>
            <AppText variant="title" style={{ textAlign: 'center', fontSize: 34, lineHeight: 42 }} accessibilityLiveRegion="assertive">
              {flow.outcome.kind === 'confirmed'
                ? `${kioskTypeInfo(flow.type).done}${flow.outcome.firstName ? `, ${flow.outcome.firstName}` : ''}`
                : 'Guardado, se enviará al conectar'}
            </AppText>
            <AppText variant="display" tabular>
              {formatTime(flow.outcome.time, tz)}
            </AppText>
            {flow.outcome.kind === 'confirmed' ? (
              <AppText variant="bodyStrong" color="textMuted">
                Folio {flow.outcome.receipt}
              </AppText>
            ) : (
              <AppText variant="bodyStrong" color="textMuted">
                {kioskTypeInfo(flow.type).label} · hora del equipo
              </AppText>
            )}
          </Pressable>
        ) : null}
      </Modal>

      <ExitKioskModal visible={exitOpen} onClose={() => setExitOpen(false)} />
    </Screen>
  );
}

/** Salida del modo kiosco: requiere el código de activación usado en este equipo. */
function ExitKioskModal({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const k = useKiosk();
  const { colors } = useTheme();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);

  const close = () => {
    setCode('');
    setError(null);
    onClose();
  };

  const confirm = async () => {
    if (!(await verifyExitCode(code))) {
      setError('Código incorrecto.');
      return;
    }
    const pending = k.summary.pending;
    const exit = (keepPending: boolean) => {
      close();
      void k.deactivate(keepPending);
    };
    if (pending > 0) {
      Alert.alert('Marcajes sin enviar', `Hay ${pending} marcaje(s) sin enviar. Se conservarán para enviarse al reactivar el kiosco.`, [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Salir y conservar', onPress: () => exit(true) },
      ]);
      return;
    }
    exit(true);
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={close} statusBarTranslucent>
      <View style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay, justifyContent: 'center', padding: spacing.xl }]}>
        <Card style={{ gap: spacing.md }}>
          <AppText variant="heading">Salir del modo kiosco</AppText>
          {error ? <Banner tone="danger" title={error} /> : null}
          <TextField
            label="Código de activación"
            value={code}
            onChangeText={(t) => setCode(t.toUpperCase())}
            autoCapitalize="characters"
            autoCorrect={false}
            secureTextEntry
          />
          <Button title="Salir" variant="danger" size="lg" onPress={() => void confirm()} />
          <Button title="Cancelar" variant="ghost" onPress={close} />
        </Card>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  start: { minHeight: 260, borderRadius: radius.xl, alignItems: 'center', justifyContent: 'center', gap: spacing.md },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
  typeBtn: { flexBasis: '47%', flexGrow: 1, minHeight: 140, borderRadius: radius.xl, borderWidth: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.sm, padding: spacing.md },
  center: { alignItems: 'center', gap: spacing.md, paddingVertical: spacing.xl },
  done: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.xl, padding: spacing.xxl },
  doneCircle: { width: 200, height: 200, borderRadius: 100, alignItems: 'center', justifyContent: 'center' },
});
