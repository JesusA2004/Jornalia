import { Stack } from 'expo-router';
import { useEffect } from 'react';

import { useSignedIn } from '@/features/auth/AuthProvider';
import { TodayProvider } from '@/features/attendance/TodayProvider';
import { LocationProvider } from '@/features/location/LocationProvider';
import { registerPushToken } from '@/features/push/push';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { fonts } from '@/theme/tokens';

export default function AppLayout() {
  const { colors } = useTheme();
  const session = useSignedIn();
  const userId = session.profile.user.id;

  // Registro del token push (una vez por sesión). Si falta EAS_PROJECT_ID no se registra.
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const uuid = await getOrCreateDeviceUuid();
      if (!cancelled) await registerPushToken(uuid, true);
    })();
    return () => {
      cancelled = true;
    };
  }, [userId]);

  return (
    <LocationProvider userId={userId}>
    <TodayProvider>
      <Stack
        screenOptions={{
          headerShadowVisible: false,
          headerStyle: { backgroundColor: colors.background },
          headerTintColor: colors.text,
          headerTitleStyle: { fontFamily: fonts.semibold, fontSize: 17 },
          headerBackButtonDisplayMode: 'minimal',
          contentStyle: { backgroundColor: colors.background },
        }}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="comprobante/[uuid]" options={{ title: 'Comprobante de marcaje' }} />
        <Stack.Screen name="rectificacion" options={{ title: 'Corregir un día' }} />
        <Stack.Screen name="incidencia" options={{ title: 'Nueva incidencia' }} />
        <Stack.Screen name="vacaciones" options={{ title: 'Solicitar vacaciones' }} />
        <Stack.Screen name="notificaciones" options={{ title: 'Notificaciones' }} />
        <Stack.Screen name="dispositivos" options={{ title: 'Dispositivos' }} />
        <Stack.Screen name="sincronizacion" options={{ title: 'Sincronización' }} />
        <Stack.Screen name="acuses/index" options={{ title: 'Documentos por firmar' }} />
        <Stack.Screen name="acuses/[id]" options={{ title: 'Acuse de recibo' }} />
        <Stack.Screen name="solicitudes" options={{ title: 'Solicitudes' }} />
        <Stack.Screen name="guia" options={{ title: 'Guía de uso' }} />
        <Stack.Screen name="permiso-ubicacion" options={{ presentation: 'fullScreenModal', headerShown: false }} />
        <Stack.Screen name="rostro" options={{ presentation: 'fullScreenModal', headerShown: false }} />
        <Stack.Screen name="camara" options={{ presentation: 'fullScreenModal', headerShown: false }} />
      </Stack>
    </TodayProvider>
    </LocationProvider>
  );
}
