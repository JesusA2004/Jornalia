import { router } from 'expo-router';
import { useState } from 'react';

import { Screen } from '@/components/ui/Screen';
import { AppText, Card, EmptyState, ListRow } from '@/components/ui/primitives';
import { useAuth, useSignedIn } from '@/features/auth/AuthProvider';
import { spacing } from '@/theme/tokens';
import { formatDateTime } from '@/utils/format';

export default function AcknowledgementsScreen() {
  const { profile } = useSignedIn();
  const { refreshProfile } = useAuth();
  const [refreshing, setRefreshing] = useState(false);
  const docs = profile.pending_acknowledgements;
  return (
    <Screen
      edges={['bottom']}
      refreshing={refreshing}
      onRefresh={async () => {
        setRefreshing(true);
        await refreshProfile();
        setRefreshing(false);
      }}>
      <AppText variant="caption" color="textMuted">
        Documentos del sistema de registro de jornada que tu empresa te pide leer y acusar de recibido.
      </AppText>
      {docs.length === 0 ? (
        <EmptyState icon="seal" title="Sin documentos pendientes" message="Ya acusaste de recibido todos los documentos vigentes." />
      ) : (
        <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
          {docs.map((d, i) => (
            <ListRow
              key={d.id}
              icon="seal"
              iconTone="info"
              title={d.title}
              subtitle={`Versión ${d.version} · publicado ${formatDateTime(d.published_at)}`}
              onPress={() => router.push({ pathname: '/acuses/[id]', params: { id: String(d.id) } })}
              last={i === docs.length - 1}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
