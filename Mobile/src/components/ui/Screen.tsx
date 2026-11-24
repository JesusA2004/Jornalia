import type { ReactNode } from 'react';
import { RefreshControl, ScrollView, type StyleProp, View, type ViewStyle } from 'react-native';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { type Edge, SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';

import { SharingBar } from '../feedback';

import { AppText } from './primitives';

/**
 * Contenedor de pantalla: safe areas, desplazamiento, pull-to-refresh,
 * manejo de teclado y aviso visible cuando se comparte la ubicación.
 */
export function Screen({
  children,
  title,
  subtitle,
  headerRight,
  refreshing,
  onRefresh,
  scroll = true,
  keyboard = false,
  edges = ['top'],
  contentStyle,
  footer,
  sharingBar = true,
}: {
  children: ReactNode;
  title?: string;
  subtitle?: string;
  headerRight?: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  scroll?: boolean;
  keyboard?: boolean;
  edges?: Edge[];
  contentStyle?: StyleProp<ViewStyle>;
  footer?: ReactNode;
  /** Aviso fijo «Compartiendo ubicación de trabajo» (por omisión sí). */
  sharingBar?: boolean;
}) {
  const { colors } = useTheme();
  const header = title ? (
    <View style={{ flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', gap: spacing.md, marginBottom: spacing.sm }}>
      <View style={{ flex: 1 }}>
        <AppText variant="title" accessibilityRole="header">
          {title}
        </AppText>
        {subtitle ? (
          <AppText variant="caption" color="textMuted">
            {subtitle}
          </AppText>
        ) : null}
      </View>
      {headerRight}
    </View>
  ) : null;

  const padding: ViewStyle = { paddingHorizontal: spacing.xl, paddingTop: spacing.lg, paddingBottom: spacing.huge, gap: spacing.lg };

  const ScrollComponent = keyboard ? KeyboardAwareScrollView : ScrollView;
  const body = scroll ? (
    <ScrollComponent
      bottomOffset={spacing.xxl}
      contentContainerStyle={[padding, contentStyle]}
      keyboardShouldPersistTaps="handled"
      contentInsetAdjustmentBehavior="automatic"
      refreshControl={
        onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={colors.textMuted} colors={[colors.accent]} /> : undefined
      }>
      {header}
      {children}
    </ScrollComponent>
  ) : (
    <View style={[{ flex: 1 }, padding, contentStyle]}>
      {header}
      {children}
    </View>
  );

  return (
    <SafeAreaView edges={edges} style={{ flex: 1, backgroundColor: colors.background }}>
      {sharingBar ? <SharingBar /> : null}
      {body}
      {footer}
    </SafeAreaView>
  );
}
