import '@/features/offline/backgroundSync';
import '@/features/push/push';

import { Baloo2_600SemiBold } from '@expo-google-fonts/baloo-2/600SemiBold';
import { Baloo2_700Bold } from '@expo-google-fonts/baloo-2/700Bold';
import { Baloo2_800ExtraBold } from '@expo-google-fonts/baloo-2/800ExtraBold';
import { Montserrat_400Regular } from '@expo-google-fonts/montserrat/400Regular';
import { Montserrat_500Medium } from '@expo-google-fonts/montserrat/500Medium';
import { Montserrat_600SemiBold } from '@expo-google-fonts/montserrat/600SemiBold';
import { Montserrat_700Bold } from '@expo-google-fonts/montserrat/700Bold';
import { useFonts } from 'expo-font';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { KeyboardProvider } from 'react-native-keyboard-controller';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider, useAuth } from '@/features/auth/AuthProvider';
import { KioskProvider, useKiosk } from '@/features/kiosk/KioskProvider';
import { SyncProvider } from '@/features/offline/SyncProvider';
import { ThemeProvider, useTheme } from '@/theme/ThemeProvider';

void SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts({
    Montserrat_400Regular,
    Montserrat_500Medium,
    Montserrat_600SemiBold,
    Montserrat_700Bold,
    Baloo2_600SemiBold,
    Baloo2_700Bold,
    Baloo2_800ExtraBold,
  });

  return (
    <SafeAreaProvider>
      <KeyboardProvider>
        <ThemeProvider>
          <AuthProvider>
            <KioskProvider>
              <RootNavigator ready={fontsLoaded || !!fontError} />
            </KioskProvider>
          </AuthProvider>
        </ThemeProvider>
      </KeyboardProvider>
    </SafeAreaProvider>
  );
}

function RootNavigator({ ready }: { ready: boolean }) {
  const { state } = useAuth();
  const kiosk = useKiosk();
  const { scheme, colors } = useTheme();
  const loading = state.status === 'loading' || kiosk.status === 'loading' || !ready;
  // Un equipo activado como kiosco de sucursal sólo muestra el marcaje compartido.
  const kioskMode = kiosk.status === 'active';

  useEffect(() => {
    if (!loading) void SplashScreen.hideAsync();
  }, [loading]);

  if (loading) return null;

  return (
    <SyncProvider enabled={state.status === 'signedIn' && !kioskMode}>
      <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.background } }}>
        <Stack.Protected guard={!kioskMode && state.status === 'signedOut'}>
          <Stack.Screen name="login" />
        </Stack.Protected>
        <Stack.Protected guard={!kioskMode && state.status === 'devicePending'}>
          <Stack.Screen name="dispositivo-pendiente" />
        </Stack.Protected>
        <Stack.Protected guard={!kioskMode && state.status === 'deviceRevoked'}>
          <Stack.Screen name="dispositivo-revocado" />
        </Stack.Protected>
        <Stack.Protected guard={!kioskMode && state.status === 'signedIn'}>
          <Stack.Screen name="(app)" />
        </Stack.Protected>
        <Stack.Protected guard={kioskMode || state.status === 'signedOut'}>
          <Stack.Screen name="kiosco" />
        </Stack.Protected>
      </Stack>
    </SyncProvider>
  );
}
