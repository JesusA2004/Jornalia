import { router } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { Logo } from '@/components/ui/brand';
import { TextField } from '@/components/ui/fields';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button } from '@/components/ui/primitives';
import { useKiosk } from '@/features/kiosk/KioskProvider';
import { KIOSK_ACTIVATE_HELP, KioskHelpButton } from '@/features/kiosk/ui';
import { spacing } from '@/theme/tokens';

/** Activación del equipo como kiosco de sucursal con el código que entrega RH. */
export default function KioskActivateScreen() {
  const { activate } = useKiosk();
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (busy || code.trim().length < 4) {
      setError('Escribe el código completo.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await activate(code);
    } catch (e) {
      setError(
        e instanceof ApiRequestError && (e.status === 422 || e.status === 404 || e.status === 410)
          ? 'Código no válido o vencido.'
          : describeError(e),
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen keyboard edges={['top', 'bottom']}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <Logo width={120} />
        <KioskHelpButton topic={KIOSK_ACTIVATE_HELP} />
      </View>
      <View style={{ gap: spacing.xs }}>
        <AppText variant="title" accessibilityRole="header">
          Activar kiosco
        </AppText>
        <AppText variant="body" color="textMuted">
          Código de activación de RH.
        </AppText>
      </View>
      {error ? <Banner tone="danger" title={error} /> : null}
      <TextField
        label="Código"
        value={code}
        onChangeText={(t) => setCode(t.toUpperCase())}
        autoCapitalize="characters"
        autoCorrect={false}
        autoComplete="off"
        maxLength={64}
        returnKeyType="go"
        onSubmitEditing={() => void submit()}
        icon="shield"
      />
      <Button title="Activar" icon="check" size="lg" loading={busy} onPress={() => void submit()} />
      <Button title="Volver" variant="ghost" onPress={() => (router.canGoBack() ? router.back() : router.replace('/login'))} />
    </Screen>
  );
}
