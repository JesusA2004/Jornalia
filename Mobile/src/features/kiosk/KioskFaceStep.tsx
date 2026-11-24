import { CameraView, useCameraPermissions } from 'expo-camera';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { Icon } from '@/components/ui/Icon';
import { AppText, Button } from '@/components/ui/primitives';
import {
  buildVerifyParts,
  type CapturedFrame,
  capturePlan,
  normalizeTimestamps,
  pickPictureSize,
  plannedShots,
  RESULT_UI,
  toFormData,
  validateFrames,
} from '@/features/face/faceVerify';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { FaceVerificationResultCode } from '@/types/contracts';

import { kioskApi } from './KioskProvider';

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Verificación facial en el kiosco (cuando la organización la exige): mismo
 * reto de movimiento que la app del colaborador, contra los endpoints del
 * kiosco con el número de empleado. Las fotos no se guardan en el equipo.
 */
export function KioskFaceStep({
  employee,
  pin,
  onMatch,
  onFail,
  onCancel,
}: {
  employee: string;
  /** NIP en memoria sólo durante este paso (el servidor identifica con número + NIP). */
  pin: string;
  onMatch: (verificationId: string) => void;
  onFail: (message: string) => void;
  onCancel: () => void;
}) {
  const { colors } = useTheme();
  const [permission, requestPermission] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [ready, setReady] = useState(false);
  const [size, setSize] = useState<string | undefined>(undefined);
  const [label, setLabel] = useState('Mira al frente');
  const [verifying, setVerifying] = useState(false);
  const running = useRef(false);
  const alive = useRef(true);

  useEffect(
    () => () => {
      alive.current = false;
    },
    [],
  );

  const run = useCallback(async () => {
    if (running.current || !camera.current) return;
    running.current = true;
    try {
      const challenge = await kioskApi.faceChallenge(employee, pin);
      const t0 = Date.now();
      const plan = capturePlan(challenge);
      const total = plannedShots(plan);
      const frames: CapturedFrame[] = [];
      for (const step of plan) {
        if (!alive.current) return;
        setLabel(step.label);
        await wait(step.settleMs);
        for (let i = 0; i < step.shots && frames.length < total; i++) {
          if (!alive.current || !camera.current) return;
          const t = Date.now() - t0;
          const photo = await camera.current.takePictureAsync({ quality: 0.6, shutterSound: false });
          if (photo?.uri) frames.push({ uri: photo.uri, t });
          if (i < step.shots - 1) await wait(step.gapMs);
        }
      }
      const ordered = normalizeTimestamps(frames);
      if (validateFrames(ordered)) {
        onFail('No se tomaron suficientes fotos. Intenta de nuevo.');
        return;
      }
      setVerifying(true);
      const parts = buildVerifyParts(challenge.id, ordered, false);
      parts.push(['employee_number', employee]);
      parts.push(['pin', pin]);
      const result = await kioskApi.faceVerify(toFormData(parts));
      if (!alive.current) return;
      if (result.result === 'match') onMatch(result.id);
      else onFail((RESULT_UI[result.result as Exclude<FaceVerificationResultCode, 'match'>] ?? RESULT_UI.error).title);
    } catch (e) {
      if (!alive.current) return;
      // No se revela si el número existe: cualquier rechazo de identidad es genérico.
      if (e instanceof ApiRequestError && (e.status === 404 || e.status === 422 || e.status === 403)) onFail('Número o NIP incorrecto');
      else onFail(describeError(e));
    } finally {
      running.current = false;
    }
  }, [employee, pin, onFail, onMatch]);

  useEffect(() => {
    if (!ready) return;
    const t = setTimeout(() => void run(), 400);
    return () => clearTimeout(t);
  }, [ready, run]);

  if (!permission) return <ActivityIndicator />;
  if (!permission.granted) {
    return (
      <View style={styles.center}>
        <Icon name="camera" size={56} color={colors.text} />
        <AppText variant="heading">Permite la cámara</AppText>
        <Button title="Permitir cámara" icon="camera" size="lg" onPress={() => void requestPermission()} style={{ alignSelf: 'stretch' }} />
        <Button title="Cancelar" variant="ghost" onPress={onCancel} />
      </View>
    );
  }

  return (
    <View style={{ gap: spacing.lg, alignItems: 'center' }}>
      <View style={[styles.cam, { borderColor: colors.gold }]}>
        <CameraView
          ref={camera}
          style={StyleSheet.absoluteFill}
          facing="front"
          mirror={false}
          pictureSize={size}
          onCameraReady={() => {
            setReady(true);
            void camera.current
              ?.getAvailablePictureSizesAsync()
              .then((s) => setSize(pickPictureSize(s)))
              .catch(() => undefined);
          }}
        />
      </View>
      {verifying ? (
        <View style={{ flexDirection: 'row', gap: spacing.sm, alignItems: 'center' }}>
          <ActivityIndicator color={colors.text} />
          <AppText variant="heading">Verificando…</AppText>
        </View>
      ) : (
        <AppText variant="title" style={{ textAlign: 'center' }} accessibilityLiveRegion="assertive">
          {label}
        </AppText>
      )}
      <Button title="Cancelar" variant="ghost" onPress={onCancel} />
    </View>
  );
}

const styles = StyleSheet.create({
  center: { alignItems: 'center', gap: spacing.md },
  cam: { width: 260, height: 340, borderRadius: radius.xl, overflow: 'hidden', borderWidth: 5 },
});
