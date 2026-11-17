import { useState } from 'react';
import { View } from 'react-native';

import { describeError } from '@/api/errors';
import { Logo } from '@/components/ui/brand';
import { Icon } from '@/components/ui/Icon';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button } from '@/components/ui/primitives';
import { useAuth } from '@/features/auth/AuthProvider';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';

/**
 * Dispositivo pendiente. `pending_reason = 'device_change'`: cambio de teléfono
 * (un solo teléfono por colaborador); Sistemas/RH lo revisan.
 */
export default function DevicePendingScreen() {
  const { state, checkDevice, signOut } = useAuth();
  const { colors } = useTheme();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const hasSession = state.status === 'devicePending' && state.hasSession;
  const device = state.status === 'devicePending' ? state.device : null;
  const phoneChange = device?.pending_reason === 'device_change';

  const check = async () => {
    setBusy(true);
    setMessage(null);
    try {
      await checkDevice();
      setMessage('Sigue en revisión.');
    } catch (e) {
      setMessage(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen edges={['top', 'bottom']} contentStyle={{ flexGrow: 1 }}>
      <Logo width={120} />
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.lg }}>
        <View style={{ width: 128, height: 128, borderRadius: 64, backgroundColor: colors.warningBg, alignItems: 'center', justifyContent: 'center' }}>
          <Icon name={phoneChange ? 'phone' : 'pending'} size={64} color={colors.warning} />
        </View>
        <AppText variant="title" style={{ textAlign: 'center' }} accessibilityRole="header">
          {phoneChange ? 'Cambio de teléfono en revisión' : 'Teléfono en revisión'}
        </AppText>
        <AppText variant="heading" color="textMuted" style={{ textAlign: 'center' }}>
          Avisa a Sistemas o RH
        </AppText>
      </View>
      {message ? <Banner tone="info" title={message} /> : null}
      {hasSession ? (
        <Button title="Comprobar de nuevo" icon="refresh" onPress={() => void check()} loading={busy} size="lg" />
      ) : (
        <AppText variant="body" color="textMuted" style={{ textAlign: 'center' }}>
          Cuando lo aprueben, vuelve a entrar.
        </AppText>
      )}
      <Button title={hasSession ? 'Cerrar sesión' : 'Volver'} variant="ghost" onPress={() => void signOut(null)} />
    </Screen>
  );
}
