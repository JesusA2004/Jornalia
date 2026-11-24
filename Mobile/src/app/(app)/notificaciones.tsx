import { type Href, router, Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { ApiRequestError } from '@/api/errors';
import { PUNCH_ICON } from '@/components/attendance';
import { HelpButton } from '@/components/help';
import { Segmented } from '@/components/ui/fields';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Screen } from '@/components/ui/Screen';
import { AppText, Button, Card, EmptyState, ErrorState, LoadingState, Pill, type Tone } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { buildReceipts, type LocalReceipt, readLocalReceipts, type ReceiptEntry, type ReceiptState } from '@/features/notifications/receipts';
import { isPunchReceipt, needsReadConfirmation, routeForNotification } from '@/features/notifications/routing';
import { useSync } from '@/features/offline/SyncProvider';
import { usePaginated } from '@/hooks/usePaginated';
import { api } from '@/services/container';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { AppNotification, NotificationCategory } from '@/types/contracts';
import { formatDateTime, formatTime, PUNCH_TYPE_LABEL } from '@/utils/format';

type Tab = 'avisos' | 'acuses';

const CATEGORY_ICON: Record<NotificationCategory, IconName> = {
  message: 'megaphone',
  reminder: 'clock',
  request: 'requests',
  attendance: 'receipt',
  system: 'shield',
};

/** Bandeja: avisos del servidor y acuses de cada marcaje (siempre visibles aquí). */
export default function NotificationsScreen() {
  const params = useLocalSearchParams<{ focus?: string; tab?: Tab }>();
  const [tab, setTab] = useState<Tab>(params.tab === 'acuses' ? 'acuses' : 'avisos');
  const list = usePaginated((page) => api.notifications.list(page), []);
  const [readIds, setReadIds] = useState<Set<string>>(new Set());
  const [markingAll, setMarkingAll] = useState(false);
  const isRead = (n: AppNotification) => n.read_at !== null || readIds.has(n.id);
  const notices = list.items.filter((n) => !isPunchReceipt(n));
  const unread = notices.filter((n) => !isRead(n) && !needsReadConfirmation(n)).length;

  const markRead = (n: AppNotification) => {
    if (isRead(n)) return;
    setReadIds((s) => new Set(s).add(n.id));
    void api.notifications.read(n.id).catch(() => undefined);
  };

  const markAll = async () => {
    setMarkingAll(true);
    try {
      // Los comunicados importantes requieren confirmación individual: se marcan uno por uno.
      const simple = notices.filter((n) => !isRead(n) && !needsReadConfirmation(n));
      await Promise.all(simple.map((n) => api.notifications.read(n.id).catch(() => undefined)));
      setReadIds((s) => new Set([...s, ...simple.map((n) => n.id)]));
    } finally {
      setMarkingAll(false);
    }
  };

  return (
    <Screen edges={['bottom']} refreshing={list.refreshing} onRefresh={() => void list.refresh()}>
      <Stack.Screen options={{ title: 'Avisos', headerRight: () => <HelpButton topic="avisos" /> }} />
      <Segmented
        options={[
          { value: 'avisos', label: 'Avisos' },
          { value: 'acuses', label: 'Acuses' },
        ]}
        value={tab}
        onChange={setTab}
      />
      {tab === 'acuses' ? (
        <Receipts notifications={list.items} onRead={markRead} />
      ) : (
        <>
          {unread > 1 ? (
            <Button title={`Marcar ${unread} como leídos`} variant="secondary" icon="check" loading={markingAll} onPress={() => void markAll()} />
          ) : null}
          {list.loading ? (
            <LoadingState />
          ) : list.error && !list.items.length ? (
            <ErrorState message={list.error} onRetry={() => void list.refresh()} />
          ) : !notices.length ? (
            <EmptyState icon="bell" title="Sin avisos" />
          ) : (
            <View style={{ gap: spacing.sm }}>
              {notices.map((n) => (
                <NotificationItem key={n.id} n={n} read={isRead(n)} focused={params.focus === n.id} onRead={() => markRead(n)} onConfirmed={() => setReadIds((s) => new Set(s).add(n.id))} />
              ))}
              {list.hasMore ? <Button title="Cargar más" variant="ghost" loading={list.loadingMore} onPress={list.loadMore} /> : null}
            </View>
          )}
        </>
      )}
    </Screen>
  );
}

function NotificationItem({
  n,
  read,
  focused,
  onRead,
  onConfirmed,
}: {
  n: AppNotification;
  read: boolean;
  focused: boolean;
  onRead: () => void;
  onConfirmed: () => void;
}) {
  const { colors } = useTheme();
  const important = needsReadConfirmation(n);
  const [busy, setBusy] = useState(false);
  const [confirmed, setConfirmed] = useState(read);
  const target = routeForNotification(n.kind, n.data);
  const navigable = target.pathname !== '/notificaciones';

  const confirm = async () => {
    setBusy(true);
    try {
      const id = n.data.announcement_id;
      if (id) {
        await api.announcements.read(id, await getOrCreateDeviceUuid()).catch((e: unknown) => {
          // Servidor sin confirmación dedicada: basta con marcar el aviso como leído.
          if (!(e instanceof ApiRequestError && (e.status === 404 || e.status === 405))) throw e;
        });
      }
      await api.notifications.read(n.id).catch(() => undefined);
      setConfirmed(true);
      onConfirmed();
    } finally {
      setBusy(false);
    }
  };

  return (
    <Pressable
      onPress={() => {
        if (!important) onRead();
        if (navigable) router.push(target as Href);
      }}
      accessibilityRole="button"
      accessibilityHint={navigable ? 'Abrir detalle' : read ? undefined : 'Marcar como leído'}>
      <Card
        style={[
          { gap: spacing.xs },
          !read && { borderLeftWidth: 4, borderLeftColor: important ? colors.gold : colors.primary },
          focused && { borderColor: colors.primary, borderWidth: 2 },
        ]}>
        <View style={styles.head}>
          <View style={[styles.icon, { backgroundColor: important ? colors.goldSoft : colors.surfaceMuted }]}>
            <Icon name={CATEGORY_ICON[n.category] ?? 'bell'} size={18} color={important ? colors.goldStrong : colors.text} />
          </View>
          <AppText variant="bodyStrong" color={read ? 'textMuted' : 'text'} style={{ flex: 1 }} numberOfLines={2}>
            {n.title}
          </AppText>
          {navigable ? <Icon name="chevronRight" size={16} color={colors.textSubtle} /> : null}
        </View>
        <AppText variant="caption">{n.body}</AppText>
        <AppText variant="caption" color="textSubtle">
          {formatDateTime(n.created_at)}
        </AppText>
        {important ? (
          confirmed ? (
            <Pill label="Lectura confirmada" tone="success" icon="check" />
          ) : (
            <Button title="Confirmar lectura" icon="check" loading={busy} onPress={() => void confirm()} />
          )
        ) : null}
      </Card>
    </Pressable>
  );
}

const STATE_UI: Record<ReceiptState, { label: string; tone: Tone; icon: IconName }> = {
  confirmed: { label: 'Registrado', tone: 'success', icon: 'cloudDone' },
  review: { label: 'En revisión', tone: 'warning', icon: 'warning' },
  pending: { label: 'Pendiente de envío', tone: 'warning', icon: 'pending' },
  rejected: { label: 'Rechazado', tone: 'danger', icon: 'error' },
};

function Receipts({ notifications, onRead }: { notifications: AppNotification[]; onRead: (n: AppNotification) => void }) {
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const { items } = useSync();
  const [local, setLocal] = useState<LocalReceipt[] | null>(null);
  useEffect(() => {
    let alive = true;
    void readLocalReceipts().then((l) => alive && setLocal(l));
    return () => {
      alive = false;
    };
  }, []);
  const entries = useMemo(() => buildReceipts(local ?? [], items, notifications), [local, items, notifications]);
  if (local === null) return <LoadingState />;
  if (!entries.length) return <EmptyState icon="receipt" title="Sin acuses" />;
  return (
    <View style={{ gap: spacing.sm }}>
      {entries.map((e) => (
        <ReceiptRow
          key={e.key}
          e={e}
          tz={tz}
          onPress={() => {
            const n = e.notificationId ? notifications.find((x) => x.id === e.notificationId) : undefined;
            if (n) onRead(n);
            if (e.punchUuid) router.push({ pathname: '/comprobante/[uuid]', params: { uuid: e.punchUuid } });
            else router.push('/sincronizacion');
          }}
        />
      ))}
    </View>
  );
}

function ReceiptRow({ e, tz, onPress }: { e: ReceiptEntry; tz: string; onPress: () => void }) {
  const { colors } = useTheme();
  const ui = STATE_UI[e.state];
  const time = e.serverTime ?? e.deviceTime;
  return (
    <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => [styles.receipt, { backgroundColor: colors.surface, borderColor: colors.border, opacity: pressed ? 0.75 : 1 }]}>
      <View style={[styles.icon, { backgroundColor: colors.brandSoft }]}>
        <Icon name={e.type ? PUNCH_ICON[e.type] : 'receipt'} size={20} color={colors.brandInk} />
      </View>
      <View style={{ flex: 1, gap: 2 }}>
        <AppText variant="bodyStrong" numberOfLines={1}>
          {e.type ? PUNCH_TYPE_LABEL[e.type] : 'Marcaje'}
        </AppText>
        <AppText variant="caption" color="textMuted" numberOfLines={1}>
          {time ? formatDateTime(time, tz) : '—'}
          {e.offline && e.deviceTime && e.serverTime ? ` · capturado ${formatTime(e.deviceTime, tz)}` : ''}
        </AppText>
        <Pill label={ui.label} tone={ui.tone} icon={ui.icon} />
      </View>
      {!e.read ? <View style={[styles.dot, { backgroundColor: colors.primary }]} /> : null}
      <Icon name="chevronRight" size={16} color={colors.textSubtle} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  icon: { width: 36, height: 36, borderRadius: radius.sm, alignItems: 'center', justifyContent: 'center' },
  receipt: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, padding: spacing.md, borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth },
  dot: { width: 10, height: 10, borderRadius: 5 },
});
