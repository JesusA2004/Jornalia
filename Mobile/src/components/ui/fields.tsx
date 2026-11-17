import DateTimePicker, { DateTimePickerAndroid, type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { type ComponentRef, forwardRef, useState } from 'react';
import { Modal, Platform, Pressable, StyleSheet, TextInput, type TextInputProps, View } from 'react-native';

import { useTheme } from '@/theme/ThemeProvider';
import { fonts, radius, spacing } from '@/theme/tokens';

import { Icon, type IconName } from './Icon';
import { AppText, Button } from './primitives';

function FieldShell({ label, error, hint, children }: { label: string; error?: string | null; hint?: string; children: React.ReactNode }) {
  return (
    <View style={{ gap: spacing.xs }}>
      <AppText variant="captionStrong" color="textMuted">
        {label}
      </AppText>
      {children}
      {error ? (
        <AppText variant="caption" color="danger" accessibilityLiveRegion="polite">
          {error}
        </AppText>
      ) : hint ? (
        <AppText variant="caption" color="textSubtle">
          {hint}
        </AppText>
      ) : null}
    </View>
  );
}

export type TextInputHandle = ComponentRef<typeof TextInput>;

export const TextField = forwardRef<
  TextInputHandle,
  TextInputProps & { label: string; error?: string | null; hint?: string; icon?: IconName; secureToggle?: boolean }
>(function TextField({ label, error, hint, icon, secureToggle, style, secureTextEntry, multiline, ...rest }, ref) {
  const { colors } = useTheme();
  const [hidden, setHidden] = useState(true);
  return (
    <FieldShell label={label} error={error} hint={hint}>
      <View
        style={[
          styles.input,
          { backgroundColor: colors.surface, borderColor: error ? colors.danger : colors.borderStrong },
          multiline && { minHeight: 110, alignItems: 'flex-start', paddingVertical: spacing.md },
        ]}>
        {icon ? <Icon name={icon} size={18} color={colors.textSubtle} /> : null}
        <TextInput
          ref={ref}
          {...rest}
          multiline={multiline}
          accessibilityLabel={label}
          secureTextEntry={secureToggle ? hidden : secureTextEntry}
          placeholderTextColor={colors.textSubtle}
          style={[
            { flex: 1, fontFamily: fonts.regular, fontSize: 16, color: colors.text, paddingVertical: 0 },
            multiline && { textAlignVertical: 'top', minHeight: 86 },
            style,
          ]}
        />
        {secureToggle ? (
          <Pressable
            onPress={() => setHidden((h) => !h)}
            hitSlop={10}
            accessibilityRole="button"
            accessibilityLabel={hidden ? 'Mostrar contraseña' : 'Ocultar contraseña'}>
            <Icon name={hidden ? 'eye' : 'eyeOff'} size={20} color={colors.textMuted} />
          </Pressable>
        ) : null}
      </View>
    </FieldShell>
  );
});

/**
 * Selector de fecha u hora nativo. En Android usa el diálogo del sistema; en
 * iOS, una hoja inferior con el selector en línea.
 */
export function DateTimeField({
  label,
  value,
  onChange,
  mode = 'date',
  display,
  placeholder = 'Seleccionar',
  minimumDate,
  maximumDate,
  error,
  hint,
}: {
  label: string;
  value: Date | null;
  onChange: (d: Date) => void;
  mode?: 'date' | 'time';
  display: (d: Date) => string;
  placeholder?: string;
  minimumDate?: Date;
  maximumDate?: Date;
  error?: string | null;
  hint?: string;
}) {
  const { colors, scheme } = useTheme();
  const [iosOpen, setIosOpen] = useState(false);
  const [draft, setDraft] = useState<Date>(value ?? new Date());

  const open = () => {
    if (Platform.OS === 'android') {
      DateTimePickerAndroid.open({
        value: value ?? new Date(),
        mode,
        is24Hour: true,
        minimumDate,
        maximumDate,
        onChange: (e: DateTimePickerEvent, d?: Date) => {
          if (e.type === 'set' && d) onChange(d);
        },
      });
    } else {
      setDraft(value ?? new Date());
      setIosOpen(true);
    }
  };

  return (
    <FieldShell label={label} error={error} hint={hint}>
      <Pressable
        onPress={open}
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${value ? display(value) : placeholder}`}
        style={[styles.input, { backgroundColor: colors.surface, borderColor: error ? colors.danger : colors.borderStrong }]}>
        <Icon name={mode === 'date' ? 'calendar' : 'clock'} size={18} color={colors.textSubtle} />
        <AppText variant="body" color={value ? 'text' : 'textSubtle'} style={{ flex: 1 }}>
          {value ? display(value) : placeholder}
        </AppText>
      </Pressable>
      {Platform.OS === 'ios' ? (
        <Modal visible={iosOpen} transparent animationType="slide" onRequestClose={() => setIosOpen(false)}>
          <Pressable style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay }]} onPress={() => setIosOpen(false)} />
          <View style={[styles.sheet, { backgroundColor: colors.surface }]}>
            <DateTimePicker
              value={draft}
              mode={mode}
              display={mode === 'date' ? 'inline' : 'spinner'}
              themeVariant={scheme}
              minimumDate={minimumDate}
              maximumDate={maximumDate}
              onChange={(_e, d) => d && setDraft(d)}
              locale="es-MX"
            />
            <Button
              title="Listo"
              onPress={() => {
                onChange(draft);
                setIosOpen(false);
              }}
            />
          </View>
        </Modal>
      ) : null}
    </FieldShell>
  );
}

/** Control segmentado simple (pestañas internas). */
export function Segmented<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
}) {
  const { colors } = useTheme();
  return (
    <View style={[styles.segmented, { backgroundColor: colors.surfaceMuted }]} accessibilityRole="tablist">
      {options.map((o) => {
        const active = o.value === value;
        return (
          <Pressable
            key={o.value}
            onPress={() => onChange(o.value)}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
            style={[styles.segment, active && { backgroundColor: colors.surface, borderColor: colors.border, borderWidth: StyleSheet.hairlineWidth }]}>
            <AppText variant="captionStrong" color={active ? 'text' : 'textMuted'} numberOfLines={1}>
              {o.label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Opciones tipo "chip" de selección única. */
export function ChoiceChips<T extends string | number>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (v: T) => void;
}) {
  const { colors } = useTheme();
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm }} accessibilityRole="radiogroup">
      {options.map((o) => {
        const active = o.value === value;
        return (
          <Pressable
            key={String(o.value)}
            onPress={() => onChange(o.value)}
            accessibilityRole="radio"
            accessibilityState={{ checked: active }}
            style={[
              styles.chip,
              { borderColor: active ? colors.primary : colors.borderStrong, backgroundColor: active ? colors.primary : colors.surface },
            ]}>
            <AppText variant="captionStrong" color={active ? 'onPrimary' : 'text'}>
              {o.label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  input: {
    minHeight: 50,
    borderWidth: 1,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  sheet: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    padding: spacing.xl,
    paddingBottom: spacing.huge,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    gap: spacing.md,
  },
  segmented: { flexDirection: 'row', padding: 3, borderRadius: radius.md },
  segment: { flex: 1, alignItems: 'center', paddingVertical: 8, borderRadius: radius.sm },
  chip: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill, borderWidth: 1 },
});
