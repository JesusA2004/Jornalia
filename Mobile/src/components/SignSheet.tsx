import { router } from 'expo-router';
import { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { KeyboardAvoidingView } from 'react-native-keyboard-controller';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiRequestError, describeError } from '@/api/errors';
import type { ConsentPurpose } from '@/crypto/signature';
import { isPinComplete } from '@/features/signature/pin';
import { canSignWithDevice, SignatureInputError } from '@/features/signature/sign';
import { useSignatureMethods, useSigner } from '@/features/signature/useSigner';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { SignatureMethod, SignRequest } from '@/types/contracts';

import { Icon } from './ui/Icon';
import { PinInput } from './ui/PinInput';
import { AppText, Banner, Button } from './ui/primitives';

/**
 * Hoja de firma electrónica: muestra la declaración exacta que se firma y
 * permite firmar con la biometría del teléfono o con el NIP de firma.
 * `submit` recibe el cuerpo listo para POST …/sign; si lanza, se muestra el error.
 */
export function SignSheet({
  visible,
  title,
  statement,
  purpose,
  subjectId,
  payloadSha256,
  submit,
  onClose,
  onSigned,
}: {
  visible: boolean;
  title: string;
  statement: string;
  purpose: ConsentPurpose;
  subjectId: number | string;
  /** `signature_payload_sha256` del servidor (habilita la firma con huella). */
  payloadSha256?: string | null;
  submit: (body: SignRequest) => Promise<void>;
  onClose: () => void;
  onSigned: () => void;
}) {
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const methods = useSignatureMethods();
  const sign = useSigner();
  const deviceOk = !!methods.device && canSignWithDevice(payloadSha256);
  const [mode, setMode] = useState<SignatureMethod>(deviceOk ? 'biometric' : 'pin');
  const [pinMissing, setPinMissing] = useState(false);
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Al abrir la hoja se reinicia el estado (ajuste durante el render, sin efecto).
  const [wasVisible, setWasVisible] = useState(visible);
  if (visible !== wasVisible) {
    setWasVisible(visible);
    if (visible) {
      setPin('');
      setError(null);
      setPinMissing(false);
      setMode(deviceOk ? 'biometric' : 'pin');
    }
  }

  const run = async (method: SignatureMethod, value?: string) => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const body = await sign(purpose, subjectId, method, value ?? pin, payloadSha256);
      await submit(body);
      onSigned();
    } catch (e) {
      setPin('');
      if (e instanceof SignatureInputError) setError(e.message);
      else if (e instanceof ApiRequestError) {
        const pinError = e.fieldErrors.pin?.[0];
        if (e.code === 'pin_not_set' || /define tu NIP/i.test(pinError ?? '')) setPinMissing(true);
        setError(pinError ?? e.fieldErrors.device_signature?.[0] ?? e.message);
      } else setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  const needsPin = mode === 'pin' && (pinMissing || methods.pinSet === false);

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <Pressable style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay }]} onPress={busy ? undefined : onClose} accessibilityLabel="Cerrar" />
      <KeyboardAvoidingView behavior="padding" style={styles.wrap} pointerEvents="box-none">
        <View style={[styles.sheet, { backgroundColor: colors.surface, paddingBottom: insets.bottom + spacing.xl }]}>
          <View style={[styles.grabber, { backgroundColor: colors.borderStrong }]} />
          <View style={styles.titleRow}>
            <View style={[styles.seal, { backgroundColor: colors.goldSoft }]}>
              <Icon name="signature" size={22} color={colors.goldStrong} />
            </View>
            <AppText variant="heading" style={{ flex: 1 }} accessibilityRole="header">
              {title}
            </AppText>
          </View>
          <ScrollView style={{ maxHeight: 160 }} contentContainerStyle={[styles.statement, { backgroundColor: colors.surfaceMuted }]}>
            <AppText variant="caption">{statement}</AppText>
          </ScrollView>

          {error ? <Banner tone="danger" title={error} /> : null}

          {needsPin ? (
            <>
              <Banner tone="warning" icon="lock" title="Primero crea tu NIP" />
              <Button
                title="Crear NIP"
                icon="lock"
                size="lg"
                onPress={() => {
                  onClose();
                  router.push('/nip');
                }}
              />
            </>
          ) : mode === 'biometric' ? (
            <>
              <Button
                title={methods.device === 'credential' ? 'Firmar con bloqueo del teléfono' : 'Firmar con huella o rostro'}
                icon="fingerprint"
                size="lg"
                loading={busy}
                onPress={() => void run('biometric')}
              />
              <Button title="Usar NIP" variant="ghost" icon="lock" disabled={busy} onPress={() => setMode('pin')} />
            </>
          ) : (
            <>
              <PinInput label="NIP de firma" value={pin} onChange={setPin} autoFocus onComplete={(v) => void run('pin', v)} />
              <Button title="Firmar" icon="signature" size="lg" loading={busy} disabled={!isPinComplete(pin)} onPress={() => void run('pin')} />
              {deviceOk ? (
                <Button title="Usar huella" variant="ghost" icon="fingerprint" disabled={busy} onPress={() => setMode('biometric')} />
              ) : null}
            </>
          )}
          <Button title="Cancelar" variant="secondary" disabled={busy} onPress={onClose} />
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, justifyContent: 'flex-end' },
  sheet: {
    paddingHorizontal: spacing.xxl,
    paddingTop: spacing.md,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    gap: spacing.md,
  },
  grabber: { width: 44, height: 5, borderRadius: 3, alignSelf: 'center' },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  seal: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center' },
  statement: { padding: spacing.md, borderRadius: radius.md },
});
