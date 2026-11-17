import type { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  type StyleProp,
  StyleSheet,
  Text,
  type TextProps,
  View,
  type ViewStyle,
} from 'react-native';

import { useTheme } from '@/theme/ThemeProvider';
import { type ColorName, radius, spacing, type TextVariant, typography } from '@/theme/tokens';

import { Icon, type IconName } from './Icon';

/* ─────────────────────────────── Texto ─────────────────────────────── */

export function AppText({
  variant = 'body',
  color = 'text',
  style,
  tabular,
  ...rest
}: TextProps & { variant?: TextVariant; color?: ColorName; tabular?: boolean }) {
  const { colors } = useTheme();
  return (
    <Text
      {...rest}
      style={[typography[variant], { color: colors[color] }, tabular && { fontVariant: ['tabular-nums'] }, style]}
    />
  );
}

/* ─────────────────────────────── Tarjeta ─────────────────────────────── */

export function Card({ children, style, padded = true }: { children: ReactNode; style?: StyleProp<ViewStyle>; padded?: boolean }) {
  const { colors } = useTheme();
  return (
    <View
      style={[
        styles.card,
        { backgroundColor: colors.surface, borderColor: colors.border },
        padded && { padding: spacing.lg },
        style,
      ]}>
      {children}
    </View>
  );
}

/* ─────────────────────────────── Botón ─────────────────────────────── */

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Button({
  title,
  onPress,
  variant = 'primary',
  icon,
  loading,
  disabled,
  size = 'md',
  style,
  accessibilityHint,
}: {
  title: string;
  onPress: () => void;
  variant?: ButtonVariant;
  icon?: IconName;
  loading?: boolean;
  disabled?: boolean;
  size?: 'md' | 'lg';
  style?: StyleProp<ViewStyle>;
  accessibilityHint?: string;
}) {
  const { colors } = useTheme();
  const bg =
    variant === 'primary' ? colors.primary : variant === 'danger' ? colors.dangerBg : variant === 'secondary' ? colors.surfaceMuted : 'transparent';
  const fg: ColorName = variant === 'primary' ? 'onPrimary' : variant === 'danger' ? 'danger' : variant === 'ghost' ? 'accent' : 'text';
  const inactive = disabled || loading;
  return (
    <Pressable
      onPress={onPress}
      disabled={inactive}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      accessibilityHint={accessibilityHint}
      style={({ pressed }) => [
        styles.button,
        size === 'lg' && styles.buttonLg,
        { backgroundColor: pressed && variant === 'primary' ? colors.primaryPressed : bg, opacity: inactive ? 0.55 : pressed ? 0.85 : 1 },
        style,
      ]}>
      {loading ? (
        <ActivityIndicator color={colors[fg]} />
      ) : (
        <>
          {icon ? <Icon name={icon} size={18} color={colors[fg]} /> : null}
          <AppText variant="bodyStrong" color={fg}>
            {title}
          </AppText>
        </>
      )}
    </Pressable>
  );
}

/* ─────────────────────────────── Píldora de estado ─────────────────────────────── */

export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

export function toneColors(tone: Tone): { fg: ColorName; bg: ColorName } {
  switch (tone) {
    case 'success':
      return { fg: 'success', bg: 'successBg' };
    case 'warning':
      return { fg: 'warning', bg: 'warningBg' };
    case 'danger':
      return { fg: 'danger', bg: 'dangerBg' };
    case 'info':
      return { fg: 'info', bg: 'infoBg' };
    default:
      return { fg: 'textMuted', bg: 'surfaceMuted' };
  }
}

export function Pill({ label, tone = 'neutral', icon }: { label: string; tone?: Tone; icon?: IconName }) {
  const { colors } = useTheme();
  const t = toneColors(tone);
  return (
    <View style={[styles.pill, { backgroundColor: colors[t.bg] }]}>
      {icon ? <Icon name={icon} size={13} color={colors[t.fg]} /> : null}
      <AppText variant="captionStrong" color={t.fg} numberOfLines={1}>
        {label}
      </AppText>
    </View>
  );
}

/* ─────────────────────────────── Aviso ─────────────────────────────── */

export function Banner({
  tone = 'info',
  icon,
  title,
  message,
  action,
}: {
  tone?: Tone;
  icon?: IconName;
  title: string;
  message?: string;
  action?: { label: string; onPress: () => void };
}) {
  const { colors } = useTheme();
  const t = toneColors(tone);
  const defaultIcon: IconName = tone === 'danger' ? 'error' : tone === 'warning' ? 'warning' : tone === 'success' ? 'checkCircle' : 'info';
  return (
    <View style={[styles.banner, { backgroundColor: colors[t.bg] }]} accessibilityRole="alert">
      <Icon name={icon ?? defaultIcon} size={20} color={colors[t.fg]} style={{ marginTop: 1 }} />
      <View style={{ flex: 1, gap: 2 }}>
        <AppText variant="bodyStrong" color={t.fg}>
          {title}
        </AppText>
        {message ? (
          <AppText variant="caption" color="text">
            {message}
          </AppText>
        ) : null}
        {action ? (
          <Pressable onPress={action.onPress} accessibilityRole="button" hitSlop={8} style={{ marginTop: spacing.xs }}>
            <AppText variant="captionStrong" color={t.fg}>
              {action.label}
            </AppText>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

/* ─────────────────────────────── Encabezado de sección ─────────────────────────────── */

export function SectionHeader({ title, action }: { title: string; action?: { label: string; onPress: () => void } }) {
  return (
    <View style={styles.sectionHeader}>
      <AppText variant="label" color="textMuted">
        {title}
      </AppText>
      {action ? (
        <Pressable onPress={action.onPress} hitSlop={10} accessibilityRole="button">
          <AppText variant="captionStrong" color="accent">
            {action.label}
          </AppText>
        </Pressable>
      ) : null}
    </View>
  );
}

/* ─────────────────────────────── Fila de lista ─────────────────────────────── */

export function ListRow({
  icon,
  iconTone = 'neutral',
  title,
  subtitle,
  right,
  onPress,
  showChevron,
  last,
}: {
  icon?: IconName;
  iconTone?: Tone;
  title: string;
  subtitle?: string | null;
  right?: ReactNode;
  onPress?: () => void;
  showChevron?: boolean;
  last?: boolean;
}) {
  const { colors } = useTheme();
  const t = toneColors(iconTone);
  const content = (
    <View style={[styles.row, !last && { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border }]}>
      {icon ? (
        <View style={[styles.rowIcon, { backgroundColor: colors[t.bg] }]}>
          <Icon name={icon} size={18} color={colors[t.fg === 'textMuted' ? 'text' : t.fg]} />
        </View>
      ) : null}
      <View style={{ flex: 1, gap: 1 }}>
        <AppText variant="bodyStrong" numberOfLines={2}>
          {title}
        </AppText>
        {subtitle ? (
          <AppText variant="caption" color="textMuted" numberOfLines={3}>
            {subtitle}
          </AppText>
        ) : null}
      </View>
      {right}
      {showChevron ?? !!onPress ? <Icon name="chevronRight" size={16} color={colors.textSubtle} /> : null}
    </View>
  );
  if (!onPress) return content;
  return (
    <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => ({ opacity: pressed ? 0.6 : 1 })}>
      {content}
    </Pressable>
  );
}

/* ─────────────────────────────── Estados ─────────────────────────────── */

export function LoadingState({ label = 'Cargando…' }: { label?: string }) {
  const { colors } = useTheme();
  return (
    <View style={styles.state} accessibilityLiveRegion="polite">
      <ActivityIndicator color={colors.textMuted} />
      <AppText variant="caption" color="textMuted">
        {label}
      </AppText>
    </View>
  );
}

export function EmptyState({
  icon = 'info',
  title,
  message,
  action,
}: {
  icon?: IconName;
  title: string;
  message?: string;
  action?: { label: string; onPress: () => void; icon?: IconName };
}) {
  const { colors } = useTheme();
  return (
    <View style={styles.state}>
      <View style={[styles.stateIcon, { backgroundColor: colors.surfaceMuted }]}>
        <Icon name={icon} size={26} color={colors.textMuted} />
      </View>
      <AppText variant="heading" style={{ textAlign: 'center' }}>
        {title}
      </AppText>
      {message ? (
        <AppText variant="body" color="textMuted" style={{ textAlign: 'center' }}>
          {message}
        </AppText>
      ) : null}
      {action ? <Button title={action.label} icon={action.icon} variant="secondary" onPress={action.onPress} style={{ marginTop: spacing.sm }} /> : null}
    </View>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <EmptyState
      icon="cloudOff"
      title="No se pudo cargar"
      message={message}
      action={onRetry ? { label: 'Reintentar', onPress: onRetry, icon: 'refresh' } : undefined}
    />
  );
}

/* ─────────────────────────────── Barra de progreso ─────────────────────────────── */

export function ProgressBar({ value, tone = 'info' }: { value: number; tone?: Tone }) {
  const { colors } = useTheme();
  const pct = Math.max(0, Math.min(1, value));
  const t = toneColors(tone);
  return (
    <View
      style={[styles.progressTrack, { backgroundColor: colors.surfaceMuted }]}
      accessibilityRole="progressbar"
      accessibilityValue={{ min: 0, max: 100, now: Math.round(pct * 100) }}>
      <View style={[styles.progressFill, { width: `${pct * 100}%`, backgroundColor: colors[t.fg === 'textMuted' ? 'primary' : t.fg] }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth },
  button: {
    minHeight: 48,
    borderRadius: radius.md,
    paddingHorizontal: spacing.lg,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
  },
  buttonLg: { minHeight: 56, borderRadius: radius.lg },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: radius.pill,
    alignSelf: 'flex-start',
  },
  banner: { flexDirection: 'row', gap: spacing.md, padding: spacing.md, borderRadius: radius.md },
  sectionHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: spacing.sm },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingVertical: spacing.md },
  rowIcon: { width: 36, height: 36, borderRadius: radius.sm, alignItems: 'center', justifyContent: 'center' },
  state: { alignItems: 'center', justifyContent: 'center', gap: spacing.sm, paddingVertical: spacing.huge, paddingHorizontal: spacing.xl },
  stateIcon: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center', marginBottom: spacing.xs },
  progressTrack: { height: 8, borderRadius: 4, overflow: 'hidden' },
  progressFill: { height: 8, borderRadius: 4 },
});
