import { type Href, router } from 'expo-router';
import { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { HelpSteps } from '@/components/help';
import { Icon, type IconName } from '@/components/ui/Icon';
import { AppText, Button } from '@/components/ui/primitives';
import type { HelpTopic } from '@/features/help/content';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { PunchType } from '@/types/contracts';

/** Etiquetas e íconos propios del kiosco (texto corto y grande). */
export const KIOSK_TYPES: { type: PunchType; label: string; done: string; icon: IconName }[] = [
  { type: 'clock_in', label: 'Entrada', done: 'Entrada registrada', icon: 'login' },
  { type: 'clock_out', label: 'Salida', done: 'Salida registrada', icon: 'logout' },
  { type: 'break_start', label: 'Inicio descanso', done: 'Inicio de descanso registrado', icon: 'coffee' },
  { type: 'break_end', label: 'Fin descanso', done: 'Fin de descanso registrado', icon: 'work' },
];

export const kioskTypeInfo = (t: PunchType) => KIOSK_TYPES.find((k) => k.type === t) ?? KIOSK_TYPES[0]!;

/** Ayuda del kiosco (única explicación del módulo). */
export const KIOSK_HELP: HelpTopic = {
  title: 'Kiosco de sucursal',
  steps: [
    { icon: 'person', text: 'Escribe tu número de empleado.' },
    { icon: 'lock', text: 'Escribe tu NIP de firma.' },
    { icon: 'clock', text: 'Elige entrada, salida o descanso.' },
    { icon: 'checkCircle', text: 'Palomita verde: quedó registrado.' },
    { icon: 'pending', text: 'Reloj ámbar: se enviará al volver internet.' },
  ],
};

export const KIOSK_ACTIVATE_HELP: HelpTopic = {
  title: 'Activar kiosco',
  steps: [
    { icon: 'shield', text: 'RH te da un código de activación.' },
    { icon: 'phone', text: 'Este equipo queda como kiosco de la sucursal.' },
    { icon: 'person', text: 'Cada colaborador marca con número y NIP.' },
    { icon: 'lock', text: 'Para salir: mantén presionado el logo.' },
  ],
};

/** Botón «?» del kiosco. */
export function KioskHelpButton({ topic }: { topic: HelpTopic }) {
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const [open, setOpen] = useState(false);
  return (
    <>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={`Ayuda: ${topic.title}`}
        hitSlop={8}
        style={({ pressed }) => [styles.help, { backgroundColor: colors.surfaceMuted, opacity: pressed ? 0.7 : 1 }]}>
        <AppText variant="heading">?</AppText>
      </Pressable>
      <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)} statusBarTranslucent>
        <Pressable style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay }]} onPress={() => setOpen(false)} />
        <View style={[styles.sheet, { backgroundColor: colors.surface, paddingBottom: insets.bottom + spacing.xl }]}>
          <AppText variant="title">{topic.title}</AppText>
          <ScrollView style={{ maxHeight: 440 }}>
            <HelpSteps topic={topic} />
          </ScrollView>
          <Button title="Entendido" size="lg" onPress={() => setOpen(false)} />
        </View>
      </Modal>
    </>
  );
}

/** Enlace discreto en el inicio de sesión para activar el modo kiosco. */
export function KioskEntryLinkView() {
  return (
    <Button
      title="Modo kiosco"
      icon="phone"
      variant="ghost"
      onPress={() => router.push('/kiosco/activar' as Href)}
      accessibilityHint="Activa este equipo como kiosco de sucursal"
    />
  );
}

/** Teclado numérico grande propio (no usa el teclado del sistema). */
export function Keypad({
  onDigit,
  onBackspace,
  onNext,
  nextEnabled,
  nextLabel = 'Continuar',
}: {
  onDigit: (d: string) => void;
  onBackspace: () => void;
  onNext: () => void;
  nextEnabled: boolean;
  nextLabel?: string;
}) {
  const { colors } = useTheme();
  const key = (label: string, onPress: () => void, opts: { icon?: IconName; primary?: boolean; disabled?: boolean; a11y?: string } = {}) => (
    <Pressable
      key={label}
      onPress={onPress}
      disabled={opts.disabled}
      accessibilityRole="button"
      accessibilityLabel={opts.a11y ?? label}
      accessibilityState={{ disabled: !!opts.disabled }}
      style={({ pressed }) => [
        styles.key,
        {
          backgroundColor: opts.primary ? colors.primary : colors.surface,
          borderColor: opts.primary ? colors.primary : colors.border,
          opacity: opts.disabled ? 0.4 : pressed ? 0.7 : 1,
        },
      ]}>
      {opts.icon ? (
        <Icon name={opts.icon} size={30} color={opts.primary ? colors.onPrimary : colors.text} />
      ) : (
        <AppText variant="title" style={{ fontSize: 34, lineHeight: 42 }} tabular>
          {label}
        </AppText>
      )}
    </Pressable>
  );
  const rows = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
  ];
  return (
    <View style={styles.pad}>
      {rows.map((r) => (
        <View key={r.join('')} style={styles.padRow}>
          {r.map((d) => key(d, () => onDigit(d)))}
        </View>
      ))}
      <View style={styles.padRow}>
        {key('Borrar', onBackspace, { icon: 'arrowLeft', a11y: 'Borrar' })}
        {key('0', () => onDigit('0'))}
        {key(nextLabel, onNext, { icon: 'arrowRight', primary: true, disabled: !nextEnabled, a11y: nextLabel })}
      </View>
    </View>
  );
}

/** Indicador de dígitos: número visible, NIP enmascarado. */
export function DigitsDisplay({ value, masked, placeholder }: { value: string; masked?: boolean; placeholder: string }) {
  const { colors } = useTheme();
  return (
    <View style={[styles.display, { backgroundColor: colors.surface, borderColor: colors.borderStrong }]} accessibilityLabel={masked ? `${value.length} dígitos` : value || placeholder}>
      {value.length === 0 ? (
        <AppText variant="heading" color="textSubtle">
          {placeholder}
        </AppText>
      ) : masked ? (
        <View style={{ flexDirection: 'row', gap: spacing.md }}>
          {Array.from({ length: value.length }, (_, i) => (
            <View key={i} style={[styles.dot, { backgroundColor: colors.text }]} />
          ))}
        </View>
      ) : (
        <AppText variant="display" style={{ fontSize: 44, lineHeight: 52 }} tabular numberOfLines={1} adjustsFontSizeToFit>
          {value}
        </AppText>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  help: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center' },
  sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: spacing.xxl, gap: spacing.lg, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl },
  pad: { gap: spacing.md, alignSelf: 'stretch' },
  padRow: { flexDirection: 'row', gap: spacing.md },
  key: { flex: 1, minHeight: 72, borderRadius: radius.lg, borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  display: { minHeight: 80, borderRadius: radius.lg, borderWidth: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: spacing.lg },
  dot: { width: 18, height: 18, borderRadius: 9 },
});
