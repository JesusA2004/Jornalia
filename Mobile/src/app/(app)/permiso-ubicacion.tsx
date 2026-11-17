import { router } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { Icon } from '@/components/ui/Icon';
import { Mascot } from '@/components/ui/brand';
import { Screen } from '@/components/ui/Screen';
import { AppText, Button } from '@/components/ui/primitives';
import { useWorkLocation } from '@/features/location/LocationProvider';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';

/** Permiso de ubicación en un paso, visual. */
export default function LocationPermissionScreen() {
  const loc = useWorkLocation();
  const { colors } = useTheme();
  const [busy, setBusy] = useState(false);

  const activate = async () => {
    setBusy(true);
    try {
      await loc.enable();
    } finally {
      setBusy(false);
      if (router.canGoBack()) router.back();
    }
  };

  return (
    <Screen edges={['top', 'bottom']} contentStyle={{ flexGrow: 1, justifyContent: 'center' }}>
      <View style={{ alignItems: 'center', gap: spacing.xl }}>
        <Mascot pose="saludo" height={170} />
        <View style={{ width: 104, height: 104, borderRadius: 52, backgroundColor: colors.successBg, alignItems: 'center', justifyContent: 'center' }}>
          <Icon name="location" size={56} color={colors.success} />
        </View>
        <AppText variant="title" style={{ textAlign: 'center' }}>
          Comparte tu ubicación durante tu turno
        </AppText>
        <View style={{ flexDirection: 'row', gap: spacing.lg }}>
          <Icon name="clock" size={28} color={colors.textMuted} />
          <Icon name="shield" size={28} color={colors.textMuted} />
          <Icon name="bell" size={28} color={colors.textMuted} />
        </View>
      </View>
      <View style={{ gap: spacing.sm, marginTop: spacing.xxl }}>
        <Button title="Activar ubicación" icon="location" size="lg" loading={busy} onPress={() => void activate()} />
        <Button title="Ahora no" variant="ghost" onPress={() => router.back()} />
      </View>
    </Screen>
  );
}
