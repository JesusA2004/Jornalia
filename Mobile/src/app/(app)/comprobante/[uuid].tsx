import { Stack, useLocalSearchParams } from 'expo-router';
import { Platform, Share, StyleSheet, View } from 'react-native';

import { Screen } from '@/components/ui/Screen';
import { Icon } from '@/components/ui/Icon';
import { AppText, Banner, Button, Card, ErrorState, ListRow, LoadingState, Pill, SectionHeader } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { PunchReceipt, PunchSource } from '@/types/contracts';
import { anomalyLabel, AUTH_METHOD_LABEL, formatCivilDateLong, formatDateTime, formatMinutes, formatTime, PUNCH_TYPE_LABEL } from '@/utils/format';

const SOURCE_LABEL: Record<PunchSource, string> = {
  mobile: 'App móvil',
  mobile_offline: 'App móvil (sin conexión)',
  web: 'Web',
  kiosk: 'Kiosco',
  import: 'Importación',
  system: 'Salida automática del sistema',
};

export default function ReceiptScreen() {
  const { uuid } = useLocalSearchParams<{ uuid: string }>();
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const q = useQuery(() => api.attendance.receipt(uuid), [uuid], { cacheKey: `receipt.${uuid}` });

  if (q.loading) return <LoadingState label="Cargando comprobante…" />;
  if (!q.data) return <ErrorState message={q.error ?? 'Comprobante no disponible.'} onRetry={() => void q.refresh()} />;
  return <ReceiptBody receipt={q.data} tz={tz} stale={q.stale} />;
}

function ReceiptBody({ receipt, tz, stale }: { receipt: PunchReceipt; tz: string; stale: boolean }) {
  const { colors } = useTheme();
  const p = receipt.punch;
  const flagged = p.status === 'flagged';

  const share = () =>
    Share.share({
      message: [
        'Comprobante de marcaje — MR LANA Asistencias',
        `${PUNCH_TYPE_LABEL[p.type]}: ${formatDateTime(p.server_time, tz)} (${tz})`,
        `Folio: ${receipt.receipt_code}`,
        `Huella de integridad: ${p.hash}`,
      ].join('\n'),
    });

  return (
    <Screen edges={['bottom']}>
      <Stack.Screen options={{ title: 'Comprobante de marcaje' }} />
      {stale ? <Banner tone="warning" icon="cloudOff" title="Comprobante guardado" message="Mostrando la copia local; sin conexión con el servidor." /> : null}

      <Card style={{ alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.xxl }}>
        <View style={[styles.badge, { backgroundColor: flagged ? colors.warningBg : colors.successBg }]}>
          <Icon name={flagged ? 'warning' : 'checkCircle'} size={30} color={flagged ? colors.warning : colors.success} />
        </View>
        <AppText variant="heading">{PUNCH_TYPE_LABEL[p.type]}</AppText>
        <AppText variant="display" tabular style={{ fontSize: 44, lineHeight: 52 }}>
          {formatTime(p.server_time, tz, true)}
        </AppText>
        <AppText variant="caption" color="textMuted">
          {formatCivilDateLong(receipt.workday.date)}
        </AppText>
        <View style={{ alignSelf: 'center' }}>
          <Pill label={flagged ? 'Con observaciones' : 'Registrado'} tone={flagged ? 'warning' : 'success'} />
        </View>
        <View style={[styles.folio, { borderColor: colors.border }]}>
          <AppText variant="label" color="textMuted">
            Folio
          </AppText>
          <AppText variant="heading" selectable tabular style={{ fontFamily: Platform.select({ ios: 'Menlo', default: 'monospace' }) }}>
            {receipt.receipt_code}
          </AppText>
        </View>
      </Card>

      {p.anomalies.length > 0 ? (
        <Banner
          tone="warning"
          title="Observaciones del servidor"
          message={p.anomalies.map(anomalyLabel).join(', ')}
        />
      ) : null}

      <SectionHeader title="Detalle" />
      <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
        <ListRow title="Hora oficial (servidor)" subtitle={formatDateTime(p.server_time, tz)} />
        <ListRow
          title="Hora del teléfono (declarada)"
          subtitle={
            p.device_time
              ? `${formatDateTime(p.device_time, tz)}${p.clock_skew_seconds ? ` · desfase ${p.clock_skew_seconds} s` : ''}`
              : 'No disponible'
          }
        />
        <ListRow title="Verificación" subtitle={AUTH_METHOD_LABEL[p.auth_method]} />
        <ListRow title="Origen" subtitle={`${SOURCE_LABEL[p.source]}${p.captured_offline ? ' · capturado sin conexión' : ''}`} />
        <ListRow
          title="Ubicación"
          subtitle={
            p.location
              ? `${p.location.latitude.toFixed(5)}, ${p.location.longitude.toFixed(5)}${
                  p.location.accuracy_meters !== null ? ` · ±${Math.round(p.location.accuracy_meters)} m` : ''
                }${p.location.is_mocked ? ' · reportada como simulada' : ''}`
              : 'No registrada'
          }
        />
        <ListRow title="Rectificación" subtitle={p.rectified ? 'Existe una rectificación aprobada que afecta este evento' : 'Sin rectificaciones'} last />
      </Card>

      <SectionHeader title="Jornada" />
      <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
        <ListRow title="Trabajado" subtitle={formatMinutes(receipt.workday.worked_minutes)} />
        <ListRow title="Descanso" subtitle={formatMinutes(receipt.workday.break_minutes)} />
        <ListRow title="Retardo" subtitle={`${receipt.workday.late_minutes} min`} last />
      </Card>

      <SectionHeader title="Integridad" />
      <Card style={{ gap: spacing.xs }}>
        <AppText variant="caption" color="textMuted">
          Secuencia #{p.sequence} · Huella SHA-256 encadenada
        </AppText>
        <AppText variant="caption" selectable style={{ fontFamily: Platform.select({ ios: 'Menlo', default: 'monospace' }) }}>
          {p.hash}
        </AppText>
      </Card>

      <Button title="Compartir comprobante" icon="receipt" variant="secondary" onPress={() => void share()} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  badge: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center' },
  folio: { marginTop: spacing.md, alignItems: 'center', borderTopWidth: StyleSheet.hairlineWidth, paddingTop: spacing.md, alignSelf: 'stretch', gap: 2, borderRadius: radius.sm },
});
