import { CameraView, useCameraPermissions } from 'expo-camera';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, View } from 'react-native';
import Animated, {
  Easing,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSequence,
  withTiming,
} from 'react-native-reanimated';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiRequestError, describeError } from '@/api/errors';
import { Icon, type IconName } from '@/components/ui/Icon';
import { AppText, Button } from '@/components/ui/primitives';
import {
  buildVerifyParts,
  type CapturedFrame,
  capturePlan,
  type CapturePhase,
  normalizeTimestamps,
  pickPictureSize,
  plannedShots,
  RESULT_UI,
  toFormData,
  validateFrames,
} from '@/features/face/faceVerify';
import { faceStore } from '@/features/face/faceStore';
import { api } from '@/services/container';
import { brand, radius, spacing } from '@/theme/tokens';
import type { FaceVerificationResultCode, PunchType } from '@/types/contracts';

/**
 * Verificación facial con reto de movimiento (cámara frontal).
 * Las fotos NO se espejan (mirror=false) → se envía mirrored='0'.
 */
const MIRRORED = false;

const STEP_ICON: Record<CapturePhase['kind'], IconName> = {
  front: 'face',
  turn_left: 'arrowLeft',
  turn_right: 'arrowRight',
  move_closer: 'zoomIn',
  move_away: 'zoomOut',
};

type UiState =
  | { phase: 'starting' }
  | { phase: 'capturing'; step: CapturePhase; done: number; total: number }
  | { phase: 'verifying' }
  | { phase: 'failed'; title: string; icon: IconName; canRetry: boolean };

/** Volver a Inicio aunque la pantalla se haya abierto por enlace directo. */
function leave() {
  if (router.canGoBack()) router.back();
  else router.replace('/');
}

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

function StepArrow({ kind }: { kind: CapturePhase['kind'] }) {
  const reduce = useReducedMotion();
  const v = useSharedValue(0);
  useEffect(() => {
    v.value = 0;
    if (!reduce)
      v.value = withRepeat(
        withSequence(withTiming(1, { duration: 550, easing: Easing.out(Easing.quad) }), withTiming(0, { duration: 350 })),
        -1,
      );
  }, [kind, v, reduce]);
  const style = useAnimatedStyle(() => {
    if (kind === 'turn_left') return { transform: [{ translateX: -28 * v.value }] };
    if (kind === 'turn_right') return { transform: [{ translateX: 28 * v.value }] };
    if (kind === 'move_closer') return { transform: [{ scale: 1 + 0.35 * v.value }] };
    if (kind === 'move_away') return { transform: [{ scale: 1 - 0.3 * v.value }] };
    return { opacity: 1 };
  });
  return (
    <Animated.View style={[styles.arrow, style]}>
      <Icon name={STEP_ICON[kind]} size={64} color="#000000" />
    </Animated.View>
  );
}

export default function FaceVerificationScreen() {
  const params = useLocalSearchParams<{ type: PunchType }>();
  const type = (params.type ?? 'clock_in') as PunchType;
  const [permission, requestPermission] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [ready, setReady] = useState(false);
  const [pictureSize, setPictureSize] = useState<string | undefined>(undefined);
  const [ui, setUi] = useState<UiState>({ phase: 'starting' });
  const running = useRef(false);
  const cancelled = useRef(false);

  useEffect(
    () => () => {
      cancelled.current = true;
    },
    [],
  );

  const useFingerprint = useCallback(() => {
    faceStore.set({ kind: 'fallback', type });
    leave();
  }, [type]);

  const run = useCallback(async () => {
    if (running.current || !camera.current) return;
    running.current = true;
    setUi({ phase: 'starting' });
    // Un reintento automático con reto nuevo si el anterior expiró o no es válido.
    try {
      for (let attempt = 0; attempt < 2; attempt++) {
        try {
          const challenge = await api.faceVerification.challenge();
          const t0 = Date.now();
          const plan = capturePlan(challenge);
          const total = plannedShots(plan);
          const frames: CapturedFrame[] = [];
          for (const step of plan) {
            if (cancelled.current) return;
            setUi({ phase: 'capturing', step, done: frames.length, total });
            await wait(step.settleMs);
            for (let i = 0; i < step.shots && frames.length < total; i++) {
              if (cancelled.current || !camera.current) return;
              const t = Date.now() - t0;
              const photo = await camera.current.takePictureAsync({
                quality: 0.6,
                shutterSound: false,
              });
              if (photo?.uri) frames.push({ uri: photo.uri, t });
              setUi({ phase: 'capturing', step, done: frames.length, total });
              if (i < step.shots - 1) await wait(step.gapMs);
            }
          }
          const ordered = normalizeTimestamps(frames);
          const problem = validateFrames(ordered);
          if (problem) {
            setUi({
              phase: 'failed',
              title: problem === 'too_late' ? 'Se acabó el tiempo, intenta de nuevo' : 'No se tomaron suficientes fotos',
              icon: 'refresh',
              canRetry: true,
            });
            return;
          }
          setUi({ phase: 'verifying' });
          const result = await api.faceVerification.verify(toFormData(buildVerifyParts(challenge.id, ordered, MIRRORED)));
          if (result.result === 'match') {
            faceStore.set({
              kind: 'match',
              type,
              verificationId: result.id,
              expiresAt: result.expires_at,
            });
            leave();
            return;
          }
          const r = RESULT_UI[result.result as Exclude<FaceVerificationResultCode, 'match'>] ?? RESULT_UI.error;
          setUi({
            phase: 'failed',
            title: r.title,
            icon: r.icon,
            canRetry: true,
          });
        } catch (e) {
          if (e instanceof ApiRequestError) {
            if ((e.code === 'face_challenge_expired' || e.code === 'face_challenge_invalid') && attempt === 0) {
              continue;
            }
            const canRetry = !['face_not_enrolled', 'face_locked', 'face_unavailable'].includes(e.code);
            setUi({
              phase: 'failed',
              title: e.message,
              icon: e.code === 'face_not_enrolled' ? 'face' : 'warning',
              canRetry,
            });
          } else {
            setUi({
              phase: 'failed',
              title: describeError(e),
              icon: 'warning',
              canRetry: true,
            });
          }
        }
        break;
      }
    } finally {
      running.current = false;
    }
  }, [type]);

  const onReady = useCallback(async () => {
    setReady(true);
    try {
      const sizes = (await camera.current?.getAvailablePictureSizesAsync()) ?? [];
      setPictureSize(pickPictureSize(sizes));
    } catch {
      // se usa el tamaño por omisión
    }
  }, []);

  // Iniciar el reto cuando la cámara está lista.
  useEffect(() => {
    if (!ready) return;
    const id = setTimeout(() => void run(), 400);
    return () => clearTimeout(id);
  }, [ready, run]);

  if (!permission) return <View style={styles.black} />;

  if (!permission.granted) {
    return (
      <SafeAreaView style={[styles.black, styles.center, { padding: spacing.xxl, gap: spacing.lg }]}>
        <Icon name="camera" size={64} color="#FFFFFF" />
        <AppText variant="title" style={styles.white}>
          Permite la cámara
        </AppText>
        <Button
          title={permission.canAskAgain ? 'Permitir cámara' : 'Abrir Ajustes'}
          icon="camera"
          size="lg"
          onPress={() => void (permission.canAskAgain ? requestPermission() : Linking.openSettings())}
          style={{ alignSelf: 'stretch' }}
        />
        <Button title="Usar huella" variant="ghost" icon="fingerprint" onPress={useFingerprint} />
      </SafeAreaView>
    );
  }

  const progress = ui.phase === 'capturing' ? ui.done / ui.total : ui.phase === 'verifying' ? 1 : 0;

  return (
    <View style={styles.black}>
      <CameraView
        ref={camera}
        style={StyleSheet.absoluteFill}
        facing="front"
        mirror={MIRRORED}
        pictureSize={pictureSize}
        onCameraReady={() => void onReady()}
      />
      <SafeAreaView edges={['top', 'bottom']} style={styles.overlay}>
        <View style={styles.top}>
          <Pressable onPress={() => leave()} accessibilityRole="button" accessibilityLabel="Cerrar" style={styles.round} hitSlop={10}>
            <Icon name="close" size={24} color="#FFFFFF" />
          </Pressable>
          <View
            style={styles.progressTrack}
            accessibilityRole="progressbar"
            accessibilityValue={{
              min: 0,
              max: 100,
              now: Math.round(progress * 100),
            }}>
            <View style={[styles.progressFill, { width: `${progress * 100}%` }]} />
          </View>
        </View>

        <View style={styles.oval} pointerEvents="none" />

        <View style={styles.bottom}>
          {ui.phase === 'capturing' ? (
            <>
              <StepArrow kind={ui.step.kind} />
              <AppText variant="title" style={[styles.white, { textAlign: 'center' }]} accessibilityLiveRegion="assertive">
                {ui.step.label}
              </AppText>
            </>
          ) : ui.phase === 'verifying' || ui.phase === 'starting' ? (
            <>
              <ActivityIndicator color="#FFFFFF" size="large" />
              <AppText variant="heading" style={styles.white}>
                {ui.phase === 'verifying' ? 'Verificando…' : 'Preparando…'}
              </AppText>
            </>
          ) : (
            <View style={styles.failCard}>
              <Icon name={ui.icon} size={44} color={brand.green} />
              <AppText variant="heading" style={[styles.white, { textAlign: 'center' }]}>
                {ui.title}
              </AppText>
              {ui.canRetry ? (
                <Button title="Intentar de nuevo" icon="refresh" size="lg" onPress={() => void run()} style={{ alignSelf: 'stretch' }} />
              ) : null}
              <Button
                title="Usar huella"
                icon="fingerprint"
                variant="secondary"
                size="lg"
                onPress={useFingerprint}
                style={{ alignSelf: 'stretch' }}
              />
            </View>
          )}
        </View>
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  black: { flex: 1, backgroundColor: '#000000' },
  center: { alignItems: 'center', justifyContent: 'center' },
  white: { color: '#FFFFFF' },
  overlay: { flex: 1, justifyContent: 'space-between', alignItems: 'center' },
  top: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.sm,
    alignSelf: 'stretch',
  },
  round: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: 'rgba(0,0,0,0.5)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  progressTrack: {
    flex: 1,
    height: 10,
    borderRadius: 5,
    backgroundColor: 'rgba(255,255,255,0.3)',
    overflow: 'hidden',
  },
  progressFill: { height: 10, borderRadius: 5, backgroundColor: brand.green },
  oval: {
    width: 250,
    height: 330,
    borderRadius: 165,
    borderWidth: 5,
    borderColor: brand.green,
  },
  bottom: {
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing.xl,
    minHeight: 200,
    alignSelf: 'stretch',
    justifyContent: 'flex-end',
  },
  arrow: {
    width: 104,
    height: 104,
    borderRadius: 52,
    backgroundColor: brand.green,
    alignItems: 'center',
    justifyContent: 'center',
  },
  failCard: {
    alignSelf: 'stretch',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: 'rgba(0,0,0,0.75)',
    borderRadius: radius.xl,
    padding: spacing.xl,
  },
});
