import { Stack } from 'expo-router';
import { useEffect } from 'react';

import { useSignedIn } from '@/features/auth/AuthProvider';
import { TodayProvider } from '@/features/attendance/TodayProvider';
import { DocumentsProvider, useDocuments } from '@/features/documents/DocumentsProvider';
import { LocationProvider } from '@/features/location/LocationProvider';
import { registerPushToken, useNotificationNavigation } from '@/features/push/push';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { fonts } from '@/theme/tokens';

export default function AppLayout() {
  const session = useSignedIn();
  const userId = session.profile.user.id;

  // Registro del token push (una vez por sesión). Sin Firebase en el build no se registra.
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
    <DocumentsProvider userId={userId}>
      <TodayProvider>
        <LocationProvider userId={userId}>
          <AppStack />
        </LocationProvider>
      </TodayProvider>
    </DocumentsProvider>
  );
}

/**
 * Mientras haya convenio o aviso de privacidad sin firmar, sólo existen las
 * pantallas del flujo bloqueante (no se puede marcar).
 */
function AppStack() {
  const { colors } = useTheme();
  const docs = useDocuments();
  const blocked = docs.blocked;
  useNotificationNavigation(!blocked);

  return (
    <Stack
      screenOptions={{
        headerShadowVisible: false,
        headerStyle: { backgroundColor: colors.background },
        headerTintColor: colors.text,
        headerTitleStyle: { fontFamily: fonts.semibold, fontSize: 17 },
        headerBackButtonDisplayMode: 'minimal',
        contentStyle: { backgroundColor: colors.background },
      }}>
      <Stack.Protected guard={blocked}>
        <Stack.Screen name="convenio" options={{ headerShown: false, gestureEnabled: false }} />
      </Stack.Protected>
      <Stack.Protected guard={!blocked}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="comprobante/[uuid]" options={{ title: 'Comprobante' }} />
        <Stack.Screen name="rectificacion" options={{ title: 'Corregir un día' }} />
        <Stack.Screen name="rectificaciones/[id]" options={{ title: 'Corrección' }} />
        <Stack.Screen name="firmas" options={{ title: 'Por firmar' }} />
        <Stack.Screen name="incidencia" options={{ title: 'Nueva incidencia' }} />
        <Stack.Screen name="vacaciones" options={{ title: 'Vacaciones' }} />
        <Stack.Screen name="notificaciones" options={{ title: 'Avisos' }} />
        <Stack.Screen name="dispositivos" options={{ title: 'Dispositivos' }} />
        <Stack.Screen name="sincronizacion" options={{ title: 'Envío de marcajes' }} />
        <Stack.Screen name="documentos" options={{ title: 'Documentos' }} />
        <Stack.Screen name="acuses/index" options={{ title: 'Por confirmar' }} />
        <Stack.Screen name="acuses/[id]" options={{ title: 'Acuse de recibo' }} />
        <Stack.Screen name="solicitudes" options={{ title: 'Solicitudes' }} />
        <Stack.Screen name="guia" options={{ title: 'Guía de uso' }} />
        <Stack.Screen name="permiso-ubicacion" options={{ presentation: 'fullScreenModal', headerShown: false }} />
        <Stack.Screen name="rostro" options={{ presentation: 'fullScreenModal', headerShown: false }} />
        <Stack.Screen name="camara" options={{ presentation: 'fullScreenModal', headerShown: false }} />
      </Stack.Protected>
      {/* El NIP se puede crear también durante el flujo bloqueante (para firmar el convenio). */}
      <Stack.Screen name="nip" options={{ title: 'NIP de firma' }} />
    </Stack>
  );
}
