import { useState } from 'react';
import { Pressable, View } from 'react-native';

import { Screen } from '@/components/ui/Screen';
import { AppText, Button, Card, EmptyState, ErrorState, LoadingState } from '@/components/ui/primitives';
import { usePaginated } from '@/hooks/usePaginated';
import { api } from '@/services/container';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';
import type { AppNotification } from '@/types/contracts';
import { formatDateTime } from '@/utils/format';

export default function NotificationsScreen() {
  const list = usePaginated((page) => api.notifications.list(page), []);
  const [readIds, setReadIds] = useState<Set<string>>(new Set());
  const [markingAll, setMarkingAll] = useState(false);
  const isRead = (n: AppNotification) => n.read_at !== null || readIds.has(n.id);
  const unread = list.items.filter((n) => !isRead(n)).length;

  const markAll = async () => {
    setMarkingAll(true);
    try {
      await api.notifications.readAll();
      setReadIds(new Set(list.items.map((n) => n.id)));
    } catch {
      // se conserva el estado; el usuario puede reintentar
    } finally {
      setMarkingAll(false);
    }
  };

  return (
    <Screen edges={['bottom']} refreshing={list.refreshing} onRefresh={() => void list.refresh()}>
      {unread > 0 ? (
        <Button title={`Marcar ${unread} como leídas`} variant="secondary" icon="check" loading={markingAll} onPress={() => void markAll()} />
      ) : null}
      {list.loading ? (
        <LoadingState />
      ) : list.error && !list.items.length ? (
        <ErrorState message={list.error} onRetry={() => void list.refresh()} />
      ) : !list.items.length ? (
        <EmptyState icon="bell" title="Sin notificaciones" message="Aquí verás avisos sobre tus solicitudes y tu jornada." />
      ) : (
        <View style={{ gap: spacing.sm }}>
          {list.items.map((n) => (
            <NotificationItem
              key={n.id}
              n={n}
              read={isRead(n)}
              onRead={() => {
                if (isRead(n)) return;
                setReadIds((s) => new Set(s).add(n.id));
                void api.notifications.read(n.id).catch(() => undefined);
              }}
            />
          ))}
          {list.hasMore ? <Button title="Cargar más" variant="ghost" loading={list.loadingMore} onPress={list.loadMore} /> : null}
        </View>
      )}
    </Screen>
  );
}

function NotificationItem({ n, read, onRead }: { n: AppNotification; read: boolean; onRead: () => void }) {
  const { colors } = useTheme();
  return (
    <Pressable onPress={onRead} accessibilityRole="button" accessibilityHint={read ? undefined : 'Marcar como leída'}>
      <Card style={[{ gap: 4 }, !read && { borderLeftWidth: 3, borderLeftColor: colors.accent }]}>
        <AppText variant="bodyStrong" color={read ? 'textMuted' : 'text'}>
          {n.title}
        </AppText>
        <AppText variant="caption">{n.body}</AppText>
        <AppText variant="caption" color="textSubtle">
          {formatDateTime(n.created_at)}
        </AppText>
      </Card>
    </Pressable>
  );
}
