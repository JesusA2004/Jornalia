import { useEffect } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import Animated, {
  Easing,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withDelay,
  withRepeat,
  withSpring,
  withTiming,
} from 'react-native-reanimated';

import { useWorkLocation } from '@/features/location/LocationProvider';
import type { TrackingKey } from '@/features/location/workLocation';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing, TOUCH_MIN } from '@/theme/tokens';

import { Icon, type IconName } from './ui/Icon';
import { AppText, Button } from './ui/primitives';

/** Punto que late (estado «activo»). Respeta «reducir movimiento». */
export function PulseDot({ color, size = 12 }: { color: string; size?: number }) {
  const reduce = useReducedMotion();
  const p = useSharedValue(0);
  useEffect(() => {
    if (!reduce) p.value = withRepeat(withTiming(1, { duration: 1400, easing: Easing.out(Easing.quad) }), -1, false);
  }, [p, reduce]);
  const halo = useAnimatedStyle(() => ({ opacity: 0.5 * (1 - p.value), transform: [{ scale: 1 + p.value * 1.6 }] }));
  return (
    <View style={{ width: size * 2.6, height: size * 2.6, alignItems: 'center', justifyContent: 'center' }}>
      <Animated.View style={[{ position: 'absolute', width: size, height: size, borderRadius: size, backgroundColor: color }, halo]} />
      <View style={{ width: size, height: size, borderRadius: size, backgroundColor: color }} />
    </View>
  );
}

const LOCATION_UI: Record<TrackingKey, { label: string; tone: 'ok' | 'pause' | 'action' | 'muted'; icon: IconName }> = {
  active: { label: 'Ubicación activa', tone: 'ok', icon: 'location' },
  active_foreground_only: { label: 'Ubicación activa', tone: 'ok', icon: 'location' },
  starting: { label: 'Activando ubicación…', tone: 'muted', icon: 'location' },
  paused_window: { label: 'En pausa (fuera de turno)', tone: 'pause', icon: 'clock' },
  stopped: { label: 'Ubicación en pausa', tone: 'action', icon: 'locationOff' },
  no_permission: { label: 'Activa la ubicación', tone: 'action', icon: 'locationOff' },
  gps_off: { label: 'Enciende el GPS', tone: 'action', icon: 'locationOff' },
  not_required: { label: 'Ubicación no requerida', tone: 'muted', icon: 'locationOff' },
  unavailable: { label: 'Ubicación no disponible', tone: 'muted', icon: 'locationOff' },
};

/** Indicador simple del estado de la ubicación de trabajo. */
export function LocationIndicator() {
  const loc = useWorkLocation();
  const { colors } = useTheme();
  const ui = LOCATION_UI[loc.key];
  const onPress =
    loc.key === 'no_permission'
      ? () => void loc.enable()
      : loc.key === 'gps_off'
        ? () => void loc.turnOnGps()
        : loc.key === 'stopped'
          ? () => void loc.refresh()
          : loc.key === 'active_foreground_only' && loc.reading?.canAskBackground
            ? () => void loc.enable()
            : undefined;
  const bg = ui.tone === 'ok' ? colors.successBg : ui.tone === 'action' ? colors.warningBg : colors.surfaceMuted;
  const fg = ui.tone === 'ok' ? colors.success : ui.tone === 'action' ? colors.warning : colors.textMuted;
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      accessibilityRole={onPress ? 'button' : 'text'}
      accessibilityLabel={ui.label}
      style={({ pressed }) => [styles.loc, { backgroundColor: bg, opacity: pressed ? 0.75 : 1 }]}>
      {ui.tone === 'ok' ? <PulseDot color={colors.success} /> : <Icon name={ui.icon} size={22} color={fg} />}
      <AppText variant="bodyStrong" style={{ color: fg, flex: 1 }} numberOfLines={1}>
        {ui.label}
      </AppText>
      {onPress ? (
        <View style={[styles.locAction, { backgroundColor: ui.tone === 'action' ? colors.primary : 'transparent' }]}>
          <AppText variant="captionStrong" style={{ color: ui.tone === 'action' ? '#000' : fg }}>
            {ui.tone === 'action' ? 'Activar' : 'Mejorar'}
          </AppText>
        </View>
      ) : null}
    </Pressable>
  );
}

/**
 * Confirmación visual a pantalla completa tras marcar:
 *  - success: palomita verde grande;
 *  - queued: reloj ámbar («se enviará al conectar»).
 */
export function PunchFeedback({
  visible,
  kind,
  title,
  subtitle,
  onClose,
  extraAction,
}: {
  visible: boolean;
  kind: 'success' | 'queued';
  title: string;
  subtitle?: string;
  onClose: () => void;
  extraAction?: { label: string; onPress: () => void };
}) {
  const { colors } = useTheme();
  const reduce = useReducedMotion();
  const scale = useSharedValue(0.4);
  const fade = useSharedValue(0);
  useEffect(() => {
    if (!visible) return;
    scale.value = reduce ? 1 : 0.4;
    fade.value = 0;
    scale.value = reduce ? 1 : withSpring(1, { damping: 11, stiffness: 160 });
    fade.value = withDelay(reduce ? 0 : 150, withTiming(1, { duration: 250 }));
  }, [visible, reduce, scale, fade]);
  const circle = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));
  const text = useAnimatedStyle(() => ({ opacity: fade.value }));
  const color = kind === 'success' ? colors.success : colors.warning;
  const bg = kind === 'success' ? colors.successBg : colors.warningBg;

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose} statusBarTranslucent>
      <View style={[StyleSheet.absoluteFill, styles.overlay, { backgroundColor: colors.background }]}>
        <Animated.View style={[styles.bigCircle, { backgroundColor: bg }, circle]}>
          <View style={[styles.innerCircle, { backgroundColor: color }]}>
            <Icon name={kind === 'success' ? 'check' : 'pending'} size={88} color="#FFFFFF" />
          </View>
        </Animated.View>
        <Animated.View style={[{ alignItems: 'center', gap: spacing.sm, paddingHorizontal: spacing.xxl }, text]}>
          <AppText variant="title" style={{ textAlign: 'center', fontSize: 32, lineHeight: 40 }} accessibilityLiveRegion="assertive">
            {title}
          </AppText>
          {subtitle ? (
            <AppText variant="heading" color="textMuted" style={{ textAlign: 'center' }}>
              {subtitle}
            </AppText>
          ) : null}
        </Animated.View>
        <View style={{ alignSelf: 'stretch', paddingHorizontal: spacing.xxl, gap: spacing.sm }}>
          <Button title="Listo" onPress={onClose} size="lg" />
          {extraAction ? <Button title={extraAction.label} variant="ghost" onPress={extraAction.onPress} /> : null}
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  loc: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: 56, borderRadius: radius.lg, paddingHorizontal: spacing.md },
  locAction: { minHeight: 36, minWidth: TOUCH_MIN + 24, paddingHorizontal: spacing.md, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
  overlay: { alignItems: 'center', justifyContent: 'space-evenly', paddingVertical: spacing.huge },
  bigCircle: { width: 220, height: 220, borderRadius: 110, alignItems: 'center', justifyContent: 'center' },
  innerCircle: { width: 156, height: 156, borderRadius: 78, alignItems: 'center', justifyContent: 'center' },
});
