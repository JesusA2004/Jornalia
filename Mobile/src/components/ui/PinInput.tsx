import { useRef } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';

import { PIN_MAX, sanitizePinInput } from '@/features/signature/pin';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';

import type { TextInputHandle } from './fields';
import { AppText } from './primitives';

/**
 * Captura de NIP: casillas grandes con puntos y un campo numérico oculto
 * (teclado numérico del sistema). El valor nunca se muestra en claro.
 */
export function PinInput({
  value,
  onChange,
  label,
  error,
  autoFocus,
  onComplete,
}: {
  value: string;
  onChange: (v: string) => void;
  label: string;
  error?: string | null;
  autoFocus?: boolean;
  onComplete?: (v: string) => void;
}) {
  const { colors } = useTheme();
  const ref = useRef<TextInputHandle>(null);
  return (
    <View style={{ gap: spacing.sm }}>
      <AppText variant="captionStrong" color="textMuted">
        {label}
      </AppText>
      <Pressable
        onPress={() => ref.current?.focus()}
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${value.length} dígitos`}
        style={styles.row}>
        {Array.from({ length: PIN_MAX }, (_, i) => {
          const filled = i < value.length;
          const current = i === value.length;
          return (
            <View
              key={i}
              style={[
                styles.box,
                {
                  borderColor: error ? colors.danger : current ? colors.primary : colors.borderStrong,
                  backgroundColor: colors.surface,
                },
              ]}>
              {filled ? <View style={[styles.dot, { backgroundColor: colors.text }]} /> : null}
            </View>
          );
        })}
      </Pressable>
      <TextInput
        ref={ref}
        value={value}
        onChangeText={(t) => {
          const v = sanitizePinInput(t);
          onChange(v);
          if (v.length === PIN_MAX) onComplete?.(v);
        }}
        keyboardType="number-pad"
        inputMode="numeric"
        maxLength={PIN_MAX}
        secureTextEntry
        autoFocus={autoFocus}
        textContentType="oneTimeCode"
        autoComplete="off"
        importantForAutofill="no"
        caretHidden
        style={styles.hidden}
        accessibilityElementsHidden
        importantForAccessibility="no"
      />
      {error ? (
        <AppText variant="caption" color="danger" accessibilityLiveRegion="polite">
          {error}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm },
  box: { flex: 1, aspectRatio: 0.85, maxWidth: 56, borderWidth: 2, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
  dot: { width: 14, height: 14, borderRadius: 7 },
  hidden: { position: 'absolute', opacity: 0, width: 1, height: 1 },
});
