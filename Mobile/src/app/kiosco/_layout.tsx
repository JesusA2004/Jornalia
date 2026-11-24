import { Stack } from 'expo-router';

import { useKiosk } from '@/features/kiosk/KioskProvider';
import { useTheme } from '@/theme/ThemeProvider';

/** Modo kiosco: marcaje compartido si está activado; si no, activación. */
export default function KioskLayout() {
  const { status } = useKiosk();
  const { colors } = useTheme();
  const active = status === 'active';
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.background } }}>
      <Stack.Protected guard={active}>
        <Stack.Screen name="index" options={{ gestureEnabled: false }} />
      </Stack.Protected>
      <Stack.Protected guard={!active}>
        <Stack.Screen name="activar" />
      </Stack.Protected>
    </Stack>
  );
}
