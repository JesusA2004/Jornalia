import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Alert, Pressable, View } from 'react-native';

import { describeError } from '@/api/errors';
import { Markdown } from '@/components/Markdown';
import { Screen } from '@/components/ui/Screen';
import { Icon } from '@/components/ui/Icon';
import { AppText, Banner, Button, Card, EmptyState } from '@/components/ui/primitives';
import { useAuth, useSignedIn } from '@/features/auth/AuthProvider';
import { api } from '@/services/container';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';
import { formatDateTime } from '@/utils/format';

export default function AcknowledgementDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { profile } = useSignedIn();
  const { refreshProfile } = useAuth();
  const { colors } = useTheme();
  const doc = profile.pending_acknowledgements.find((d) => String(d.id) === id);
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!doc) return <EmptyState icon="seal" title="Documento no disponible" message="Es posible que ya lo hayas acusado." />;

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.profile.acknowledge(doc.id, { accepted: true, device_uuid: await getOrCreateDeviceUuid() });
      Alert.alert('Acuse registrado', `Quedó registrado tu acuse de «${doc.title}».`);
      router.back();
      await refreshProfile();
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen edges={['bottom']}>
      <View style={{ gap: 2 }}>
        <AppText variant="heading">{doc.title}</AppText>
        <AppText variant="caption" color="textMuted">
          {doc.code} · versión {doc.version} · {formatDateTime(doc.published_at)}
        </AppText>
      </View>
      <Card>
        <Markdown source={doc.body_markdown} />
      </Card>
      {error ? <Banner tone="danger" title="No se pudo registrar" message={error} /> : null}
      <Pressable
        onPress={() => setAccepted((a) => !a)}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: accepted }}
        style={{ flexDirection: 'row', gap: spacing.md, alignItems: 'flex-start' }}>
        <View
          style={{
            width: 24,
            height: 24,
            borderRadius: 6,
            borderWidth: 2,
            borderColor: accepted ? colors.primary : colors.borderStrong,
            backgroundColor: accepted ? colors.primary : 'transparent',
            alignItems: 'center',
            justifyContent: 'center',
          }}>
          {accepted ? <Icon name="check" size={16} color={colors.onPrimary} /> : null}
        </View>
        <AppText variant="body" style={{ flex: 1 }}>
          Leí el documento y acuso de recibido su contenido.
        </AppText>
      </Pressable>
      <Button title="Registrar acuse" icon="seal" onPress={() => void submit()} disabled={!accepted} loading={busy} size="lg" />
    </Screen>
  );
}
