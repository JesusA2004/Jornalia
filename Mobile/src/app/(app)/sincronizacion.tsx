import { router, Stack } from 'expo-router';
import { Alert, View } from 'react-native';

import { HelpButton } from '@/components/help';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card, EmptyState, ListRow, Pill, SectionHeader } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { useToday } from '@/features/attendance/TodayProvider';
import type { QueuedPunch } from '@/features/offline/queue';
import { useSync } from '@/features/offline/SyncProvider';
import { useNow } from '@/hooks/useNow';
import { spacing } from '@/theme/tokens';
import { civilDateInZone, formatDateTime, formatTime, PUNCH_TYPE_LABEL } from '@/utils/format';

export default function SyncScreen() {
  const sync = useSync();
  const today = useToday();
  const { profile } = useSignedIn();
  const now = useNow(15000);
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const maxHours = today.data?.policy.offline_max_hours ?? null;

  const pending = sync.items.filter((i) => i.status === 'pending');
  const rejected = sync.items.filter((i) => i.status === 'rejected');
  const synced = sync.items.filter((i) => i.status === 'synced').reverse();

  const run = async () => {
    const r = await sync.syncNow(true);
    if (r && r.stoppedBy === null && r.sent > 0) {
      void today.refresh();
    }
  };

  const describePending = (i: QueuedPunch) => {
    const parts = [`Capturado ${formatDateTime(i.request.device_time, tz)} (hora del teléfono)`];
    if (i.attempts > 0) parts.push(`${i.attempts} intento(s)`);
    if (i.last_error) parts.push(i.last_error);
    if (i.next_attempt_at > now.getTime()) parts.push(`Próximo intento ${formatTime(new Date(i.next_attempt_at), tz)}`);
    return parts.join(' · ');
  };

  const late = maxHours !== null && pending.some((i) => now.getTime() - i.created_at > maxHours * 3600_000);

  return (
    <Screen edges={['bottom']}>
      <Card style={{ gap: spacing.sm }}>
        <View style={{ flexDirection: 'row', gap: spacing.sm, flexWrap: 'wrap' }}>
          <Pill label={sync.isOnline ? 'En línea' : 'Sin conexión'} tone={sync.isOnline ? 'success' : 'warning'} icon={sync.isOnline ? 'online' : 'offline'} />
          <Pill label={`${pending.length} pendiente(s)`} tone={pending.length ? 'warning' : 'neutral'} />
          {rejected.length ? <Pill label={`${rejected.length} rechazado(s)`} tone="danger" /> : null}
        </View>
        {sync.lastSyncAt ? (
          <AppText variant="caption" color="textMuted">
            Último envío {formatDateTime(new Date(sync.lastSyncAt).toISOString(), tz)}
          </AppText>
        ) : null}
        <Button
          title={sync.syncing ? 'Sincronizando…' : 'Sincronizar ahora'}
          icon="sync"
          loading={sync.syncing}
          disabled={!sync.isOnline || pending.length === 0}
          onPress={() => void run()}
        />
      </Card>

      <Stack.Screen options={{ headerRight: () => <HelpButton topic="sincronizacion" /> }} />
      <AppText variant="caption" color="textMuted">
        Pendiente = guardado en tu teléfono, aún no registrado.
      </AppText>
      {late ? (
        <Banner
          tone="warning"
          title="Sincronización tardía"
          message={`Más de ${maxHours} h sin enviar: tu jefe los revisará.`}
        />
      ) : null}

      {sync.items.length === 0 ? (
        <EmptyState icon="cloudDone" title="Todo enviado" />
      ) : null}

      {pending.length ? (
        <>
          <SectionHeader title="Pendientes de sincronización" />
          <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
            {pending.map((i, idx) => (
              <ListRow
                key={i.client_uuid}
                icon="pending"
                iconTone="warning"
                title={PUNCH_TYPE_LABEL[i.request.type]}
                subtitle={describePending(i)}
                last={idx === pending.length - 1}
              />
            ))}
          </Card>
        </>
      ) : null}

      {rejected.length ? (
        <>
          <SectionHeader title="Requieren revisión" />
          <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
            {rejected.map((i, idx) => (
              <ListRow
                key={i.client_uuid}
                icon="error"
                iconTone="danger"
                title={`${PUNCH_TYPE_LABEL[i.request.type]} · ${formatDateTime(i.request.device_time, tz)}`}
                subtitle={`Requiere revisión: ${i.error?.message ?? i.last_error ?? 'rechazado'}`}
                last={idx === rejected.length - 1}
                onPress={() =>
                  Alert.alert('Marcaje rechazado', `${i.error?.message ?? ''}\n\n¿Qué deseas hacer?`, [
                    { text: 'Cerrar', style: 'cancel' },
                    {
                      text: 'Solicitar rectificación',
                      onPress: () => router.push({ pathname: '/rectificacion', params: { date: civilDateInZone(new Date(i.request.device_time), tz) } }),
                    },
                    { text: 'Descartar aviso', style: 'destructive', onPress: () => void sync.dismissRejected(i.client_uuid) },
                  ])
                }
              />
            ))}
          </Card>
        </>
      ) : null}

      {synced.length ? (
        <>
          <SectionHeader title="Sincronizados recientemente" />
          <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
            {synced.map((i, idx) => (
              <ListRow
                key={i.client_uuid}
                icon={i.server_punch?.status === 'flagged' ? 'warning' : 'cloudDone'}
                iconTone={i.server_punch?.status === 'flagged' ? 'warning' : 'success'}
                title={`${PUNCH_TYPE_LABEL[i.request.type]}${i.server_punch?.status === 'flagged' ? ' · en revisión' : ''}`}
                subtitle={
                  i.server_punch
                    ? `Capturado ${formatTime(i.request.device_time, tz)} · recibido ${formatDateTime(i.server_punch.received_at ?? i.server_punch.server_time, tz)}`
                    : 'Registrado'
                }
                onPress={i.server_punch ? () => router.push({ pathname: '/comprobante/[uuid]', params: { uuid: i.server_punch!.uuid } }) : undefined}
                last={idx === synced.length - 1}
              />
            ))}
          </Card>
        </>
      ) : null}
    </Screen>
  );
}
