import { Screen } from '@/components/ui/Screen';
import { AppText, Card, EmptyState, ErrorState, ListRow, LoadingState, Pill, type Tone } from '@/components/ui/primitives';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { spacing } from '@/theme/tokens';
import type { DeviceStatus } from '@/types/contracts';
import { DEVICE_STATUS_LABEL, formatDateTime } from '@/utils/format';

const TONE: Record<DeviceStatus, Tone> = { approved: 'success', pending: 'warning', revoked: 'danger' };

export default function DevicesScreen() {
  const q = useQuery(() => api.devices.list(), []);
  const devices = q.data;
  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={() => void q.refresh()}>
      <AppText variant="caption" color="textMuted">
        Teléfonos vinculados a tu cuenta para registrar asistencia. Para retirar un dispositivo, solicítalo a tu administrador.
      </AppText>
      {q.loading ? (
        <LoadingState />
      ) : !devices ? (
        <ErrorState message={q.error ?? 'Sin datos'} onRetry={() => void q.refresh()} />
      ) : devices.length === 0 ? (
        <EmptyState icon="phone" title="Sin dispositivos" />
      ) : (
        <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
          {devices.map((d, i) => (
            <ListRow
              key={d.id}
              icon="phone"
              iconTone={TONE[d.status]}
              title={`${d.name}${d.is_current ? ' (este teléfono)' : ''}`}
              subtitle={[
                d.model,
                d.platform === 'android' ? 'Android' : d.platform === 'ios' ? 'iOS' : 'Web',
                d.last_seen_at ? `Última actividad ${formatDateTime(d.last_seen_at)}` : null,
              ]
                .filter(Boolean)
                .join(' · ')}
              right={<Pill label={DEVICE_STATUS_LABEL[d.status]} tone={TONE[d.status]} />}
              last={i === devices.length - 1}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
