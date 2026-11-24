import { router, Stack, useFocusEffect } from 'expo-router';
import { useCallback } from 'react';
import { View } from 'react-native';

import { HelpButton } from '@/components/help';
import { RectificationItem } from '@/components/requests';
import { Screen } from '@/components/ui/Screen';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { fetchPendingSignatures } from '@/features/requests/rectificationsApi';
import { useNow } from '@/hooks/useNow';
import { useQuery } from '@/hooks/useQuery';
import { spacing } from '@/theme/tokens';

/** Correcciones autorizadas por tu jefe que esperan tu firma. */
export default function PendingSignaturesScreen() {
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const now = useNow(60_000).getTime();
  const q = useQuery(fetchPendingSignatures, [], { cacheKey: 'rectifications.por_firmar' });
  const refresh = q.refresh;
  useFocusEffect(
    useCallback(() => {
      void refresh();
    }, [refresh]),
  );
  const list = q.data ?? [];
  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={() => void q.refresh()}>
      <Stack.Screen options={{ title: 'Por firmar', headerRight: () => <HelpButton topic="firma" /> }} />
      {q.loading && !q.data ? (
        <LoadingState />
      ) : q.error && !q.data ? (
        <ErrorState message={q.error} onRetry={() => void q.refresh()} />
      ) : !list.length ? (
        <EmptyState icon="signature" title="Nada por firmar" />
      ) : (
        <View style={{ gap: spacing.sm }}>
          {list.map((r) => (
            <RectificationItem
              key={r.id}
              r={r}
              tz={tz}
              now={now}
              onPress={() => router.push({ pathname: '/rectificaciones/[id]', params: { id: String(r.id) } })}
            />
          ))}
        </View>
      )}
    </Screen>
  );
}
