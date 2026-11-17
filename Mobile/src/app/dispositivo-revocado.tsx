import { View } from 'react-native';

import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button } from '@/components/ui/primitives';
import { Logo } from '@/components/ui/brand';
import { useAuth } from '@/features/auth/AuthProvider';
import { spacing } from '@/theme/tokens';

export default function DeviceRevokedScreen() {
  const { state, signOut } = useAuth();
  const message = state.status === 'deviceRevoked' ? state.message : null;
  return (
    <Screen edges={['top', 'bottom']}>
      <Logo width={132} />
      <View style={{ gap: spacing.sm, marginTop: spacing.xl }}>
        <AppText variant="title" accessibilityRole="header">
          Dispositivo revocado
        </AppText>
        <AppText variant="body" color="textMuted">
          Este teléfono ya no puede marcar. Habla con tu supervisor.
        </AppText>
      </View>
      {message ? <Banner tone="danger" title={message} /> : null}
      <Button title="Volver al inicio de sesión" onPress={() => void signOut(null)} size="lg" />
    </Screen>
  );
}
