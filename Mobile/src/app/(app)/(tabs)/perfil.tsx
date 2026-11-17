import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { describeError } from '@/api/errors';
import { HelpButton } from '@/components/help';
import { Screen } from '@/components/ui/Screen';
import { AppText, Button, Card, ListRow, SectionHeader } from '@/components/ui/primitives';
import { API_URL, APP_VARIANT, APP_VERSION } from '@/config/env';
import { useAuth, useSignedIn } from '@/features/auth/AuthProvider';
import { useFaceStatus } from '@/features/face/useFaceStatus';
import { useWorkLocation } from '@/features/location/LocationProvider';
import { useSync } from '@/features/offline/SyncProvider';
import { canProtectWithBiometrics } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';

export default function ProfileScreen() {
  const session = useSignedIn();
  const { signOut, upgradeSecret, refreshProfile } = useAuth();
  const sync = useSync();
  const loc = useWorkLocation();
  const face = useFaceStatus();
  const { colors } = useTheme();
  const [busy, setBusy] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const { profile, secretProtection } = session;
  const e = profile.employee;
  const name = e?.full_name ?? profile.user.name;
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');
  const pendingDocs = profile.pending_acknowledgements.length;

  const confirmSignOut = () => {
    const pending = sync.summary.pending;
    Alert.alert(
      '¿Cerrar sesión?',
      pending > 0 ? `Tienes ${pending} marcaje(s) por enviar. Se enviarán al volver a entrar.` : 'La ubicación de trabajo se detendrá.',
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Cerrar sesión', style: 'destructive', onPress: () => void signOut(null) },
      ],
    );
  };

  const upgrade = async () => {
    setBusy(true);
    try {
      await upgradeSecret();
      Alert.alert('Listo', 'Ahora marcas con tu huella o rostro.');
    } catch (err) {
      Alert.alert('No se pudo', describeError(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      title="Perfil"
      headerRight={<HelpButton topic="perfil" />}
      refreshing={refreshing}
      onRefresh={async () => {
        setRefreshing(true);
        await refreshProfile();
        setRefreshing(false);
      }}>
      <Card style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.lg }}>
        <View style={{ width: 60, height: 60, borderRadius: 30, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' }}>
          <AppText variant="title" style={{ color: '#000' }}>
            {initials}
          </AppText>
        </View>
        <View style={{ flex: 1 }}>
          <AppText variant="heading" numberOfLines={2}>
            {name}
          </AppText>
          <AppText variant="caption" color="textMuted" numberOfLines={1}>
            {e ? [e.position, e.employee_number].filter(Boolean).join(' · ') : 'Tu cuenta no está vinculada a un colaborador'}
          </AppText>
          <AppText variant="caption" color="textMuted" numberOfLines={1}>
            {profile.organization.name}
          </AppText>
        </View>
      </Card>

      <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
        <ListRow icon="requests" iconTone="info" title="Solicitudes" subtitle="Correcciones, incidencias y vacaciones" onPress={() => router.push('/solicitudes')} />
        <ListRow icon="help" iconTone="success" title="Guía de uso" onPress={() => router.push('/guia')} />
        <ListRow icon="bell" title="Notificaciones" onPress={() => router.push('/notificaciones')} />
        <ListRow
          icon="seal"
          iconTone={pendingDocs ? 'warning' : 'neutral'}
          title="Documentos"
          subtitle={pendingDocs ? `${pendingDocs} por confirmar` : undefined}
          onPress={() => router.push('/acuses')}
          last
        />
      </Card>

      <SectionHeader title="Este teléfono" />
      <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
        <ListRow
          icon="fingerprint"
          iconTone={secretProtection ? 'success' : 'danger'}
          title={secretProtection === 'biometric' ? 'Marcas con huella o rostro' : secretProtection === 'credential' ? 'Marcas con tu PIN' : 'No puede marcar'}
          right={
            secretProtection === 'credential' && canProtectWithBiometrics() ? (
              <Button title="Usar huella" variant="secondary" loading={busy} onPress={() => void upgrade()} />
            ) : undefined
          }
        />
        <ListRow
          icon="location"
          iconTone={loc.key === 'active' || loc.key === 'active_foreground_only' ? 'success' : 'warning'}
          title="Ubicación de trabajo"
          subtitle={loc.reading?.background === 'granted' ? 'Permitida todo el tiempo' : loc.reading?.foreground === 'granted' ? 'Mientras uses la app' : 'Sin permiso'}
          onPress={() => void loc.enable()}
        />
        <ListRow
          icon="face"
          iconTone={face.data?.available && face.data.enrolled ? 'success' : 'neutral'}
          title="Verificación facial"
          subtitle={
            face.loading
              ? '…'
              : !face.data?.available
                ? 'No disponible'
                : face.data.enrolled
                  ? 'Registrada'
                  : 'No registrada (acude con RH)'
          }
        />
        <ListRow icon="phone" title="Dispositivos" onPress={() => router.push('/dispositivos')} />
        <ListRow
          icon="sync"
          iconTone={sync.summary.pending ? 'warning' : 'success'}
          title="Envío de marcajes"
          subtitle={sync.summary.pending ? `${sync.summary.pending} por enviar` : 'Todo enviado'}
          onPress={() => router.push('/sincronizacion')}
          last
        />
      </Card>

      <Button title="Cerrar sesión" icon="logout" variant="danger" size="lg" onPress={confirmSignOut} />

      <AppText variant="caption" color="textSubtle" style={{ textAlign: 'center' }}>
        Versión {APP_VERSION}
        {APP_VARIANT !== 'production' ? ` · ${APP_VARIANT} · ${API_URL}` : ''}
      </AppText>
    </Screen>
  );
}
