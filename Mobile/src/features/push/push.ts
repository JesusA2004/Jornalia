import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { AndroidImportance } from 'expo-notifications';
import { type Href, router } from 'expo-router';
import { useEffect } from 'react';
import { Platform } from 'react-native';

import { routeForNotification } from '@/features/notifications/routing';
import { api } from '@/services/container';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldPlaySound: false,
    shouldSetBadge: false,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
});

export type PushRegistration =
  | { status: 'registered'; token: string }
  | { status: 'denied' }
  | { status: 'unsupported'; reason: string }
  /** Sin google-services.json en el build: Firebase no está inicializado. */
  | { status: 'missing_firebase' }
  | { status: 'error'; message: string };

/**
 * Registra el token push NATIVO (FCM en Android, `getDevicePushTokenAsync`), no
 * el token de Expo. Requiere un build con `google-services.json`
 * (`googleServicesFile` condicional en app.config.ts).
 */
export async function registerPushToken(deviceUuid: string, askPermission: boolean): Promise<PushRegistration> {
  if (!Device.isDevice && Platform.OS === 'ios') {
    return { status: 'unsupported', reason: 'El simulador de iOS no recibe notificaciones push remotas.' };
  }
  try {
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'Avisos de asistencia',
        importance: AndroidImportance.DEFAULT,
      });
    }
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== 'granted' && askPermission) status = (await Notifications.requestPermissionsAsync()).status;
    if (status !== 'granted') return { status: 'denied' };

    let token: string;
    try {
      const t = await Notifications.getDevicePushTokenAsync();
      token = typeof t.data === 'string' ? t.data : JSON.stringify(t.data);
    } catch (e) {
      const msg = e instanceof Error ? e.message : '';
      if (/firebase|FirebaseApp|google-services/i.test(msg)) return { status: 'missing_firebase' };
      throw e;
    }
    // El contrato sólo admite FCM; en iOS el token nativo es de APNs y no se registra.
    if (Platform.OS !== 'android') return { status: 'unsupported', reason: 'Push FCM sólo en Android.' };
    await api.devices.pushToken({ device_uuid: deviceUuid, push_token: token, provider: 'fcm' });
    return { status: 'registered', token };
  } catch (e) {
    return { status: 'error', message: e instanceof Error ? e.message : 'Error desconocido' };
  }
}

/**
 * Navegación al tocar una notificación push (en frío o con la app abierta):
 * abre la solicitud, comprobante o aviso correspondiente y lo marca leído.
 * `enabled=false` mientras el flujo del convenio bloquea la app.
 */
export function useNotificationNavigation(enabled: boolean): void {
  useEffect(() => {
    if (!enabled) return;
    const open = (response: Notifications.NotificationResponse | null) => {
      if (!response) return;
      const content = response.notification.request.content;
      const data = (content.data ?? {}) as Record<string, unknown>;
      const kind = typeof data.kind === 'string' ? data.kind : null;
      if (typeof data.notification_id === 'string') void api.notifications.read(data.notification_id).catch(() => undefined);
      router.push(routeForNotification(kind, data) as Href);
    };
    try {
      open(Notifications.getLastNotificationResponse());
      Notifications.clearLastNotificationResponse();
    } catch {
      // módulo no disponible (web)
    }
    const sub = Notifications.addNotificationResponseReceivedListener(open);
    return () => sub.remove();
  }, [enabled]);
}
