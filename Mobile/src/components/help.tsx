import { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { HELP, type HelpTopic } from '@/features/help/content';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing, TOUCH_MIN } from '@/theme/tokens';

import { Icon } from './ui/Icon';
import { AppText, Button } from './ui/primitives';

/** Pasos numerados con íconos grandes. */
export function HelpSteps({ topic }: { topic: HelpTopic }) {
  const { colors } = useTheme();
  return (
    <View style={{ gap: spacing.md }}>
      {topic.steps.map((s, i) => (
        <View key={i} style={styles.step}>
          <View style={[styles.num, { backgroundColor: colors.primary }]}>
            <AppText variant="heading" style={{ color: colors.onPrimary }}>
              {i + 1}
            </AppText>
          </View>
          <Icon name={s.icon} size={28} color={colors.accent} />
          <AppText variant="bodyStrong" style={{ flex: 1 }}>
            {s.text}
          </AppText>
        </View>
      ))}
    </View>
  );
}

/** Botón «?» que abre la hoja de ayuda del módulo. */
export function HelpButton({ topic, light }: { topic: keyof typeof HELP; light?: boolean }) {
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const [open, setOpen] = useState(false);
  const t = HELP[topic];
  return (
    <>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={`Ayuda: ${t.title}`}
        hitSlop={8}
        style={({ pressed }) => [
          styles.button,
          { backgroundColor: light ? 'rgba(255,255,255,0.18)' : colors.surfaceMuted, opacity: pressed ? 0.7 : 1 },
        ]}>
        <AppText variant="heading" style={{ color: light ? '#FFFFFF' : colors.text }}>
          ?
        </AppText>
      </Pressable>
      <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)} statusBarTranslucent>
        <Pressable style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay }]} onPress={() => setOpen(false)} />
        <View style={[styles.sheet, { backgroundColor: colors.surface, paddingBottom: insets.bottom + spacing.xl }]}>
          <View style={[styles.grabber, { backgroundColor: colors.borderStrong }]} />
          <AppText variant="title">{t.title}</AppText>
          <ScrollView style={{ maxHeight: 420 }} contentContainerStyle={{ paddingVertical: spacing.sm }}>
            <HelpSteps topic={t} />
          </ScrollView>
          <Button title="Entendido" onPress={() => setOpen(false)} size="lg" />
        </View>
      </Modal>
    </>
  );
}

const styles = StyleSheet.create({
  button: { width: TOUCH_MIN, height: TOUCH_MIN, borderRadius: TOUCH_MIN / 2, alignItems: 'center', justifyContent: 'center' },
  sheet: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: spacing.xxl,
    paddingTop: spacing.md,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    gap: spacing.lg,
  },
  grabber: { width: 44, height: 5, borderRadius: 3, alignSelf: 'center' },
  step: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, minHeight: 52 },
  num: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
});
