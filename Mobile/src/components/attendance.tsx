import { router } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { Punch, PunchType } from '@/types/contracts';
import { anomalyLabel, formatTime, PUNCH_TYPE_LABEL } from '@/utils/format';

import { Icon, type IconName } from './ui/Icon';
import { AppText, Pill } from './ui/primitives';

export const PUNCH_ICON: Record<PunchType, IconName> = {
  clock_in: 'login',
  clock_out: 'logout',
  break_start: 'coffee',
  break_end: 'work',
};

/**
 * Fila de marcaje original (sellado por el servidor). Los marcajes con
 * `source='system'` sólo existen en registros históricos (la salida
 * automática se eliminó): se muestran como tales, sin crear ni sugerir nada.
 */
export function PunchRow({ punch, tz, last, pressable = true }: { punch: Punch; tz?: string; last?: boolean; pressable?: boolean }) {
  const { colors } = useTheme();
  const flagged = punch.status === 'flagged';
  const automatic = punch.source === 'system';
  const label = PUNCH_TYPE_LABEL[punch.type];
  const received = punch.received_at ?? punch.server_time;
  const body = (
    <View style={[styles.row, !last && { borderBottomColor: colors.border, borderBottomWidth: StyleSheet.hairlineWidth }]}>
      <View style={[styles.icon, { backgroundColor: automatic ? colors.infoBg : flagged ? colors.warningBg : colors.surfaceMuted }]}>
        <Icon name={automatic ? 'event' : PUNCH_ICON[punch.type]} size={18} color={automatic ? colors.info : flagged ? colors.warning : colors.text} />
      </View>
      <View style={{ flex: 1, gap: 2 }}>
        <AppText variant="bodyStrong" color={automatic ? 'info' : 'text'}>
          {label}
        </AppText>
        {automatic ? (
          <AppText variant="caption" color="textMuted">
            Generado por sistema (histórico)
          </AppText>
        ) : punch.captured_offline && punch.device_time ? (
          <AppText variant="caption" color="textMuted" numberOfLines={1}>
            Capturado {formatTime(punch.device_time, tz ?? punch.timezone)} · recibido {formatTime(received, tz ?? punch.timezone)}
          </AppText>
        ) : null}
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
          {punch.captured_offline ? <Pill label="Sin conexión" icon="cloudOff" /> : null}
          {flagged ? <Pill label="En revisión" tone="warning" icon="warning" /> : null}
          {punch.rectified ? <Pill label="Corregido" tone="info" icon="edit" /> : null}
          {punch.anomalies.filter((a) => a !== 'auto_clock_out').slice(0, 2).map((a) => (
            <Pill key={a} label={anomalyLabel(a)} tone="warning" />
          ))}
          {punch.anomalies.length > 2 ? <Pill label={`+${punch.anomalies.length - 2}`} tone="warning" /> : null}
        </View>
      </View>
      <AppText variant="heading" tabular>
        {formatTime(punch.server_time, tz ?? punch.timezone)}
      </AppText>
      {pressable ? <Icon name="chevronRight" size={16} color={colors.textSubtle} /> : null}
    </View>
  );
  if (!pressable) return body;
  return (
    <Pressable
      onPress={() => router.push({ pathname: '/comprobante/[uuid]', params: { uuid: punch.uuid } })}
      accessibilityRole="button"
      accessibilityLabel={`${label} a las ${formatTime(punch.server_time, tz ?? punch.timezone)}. Ver comprobante`}
      style={({ pressed }) => ({ opacity: pressed ? 0.6 : 1 })}>
      {body}
    </Pressable>
  );
}

/** Métrica compacta (valor + etiqueta). */
export function Metric({ label, value, tone }: { label: string; value: string; tone?: 'warning' | 'danger' | 'success' }) {
  const { colors } = useTheme();
  return (
    <View style={[styles.metric, { backgroundColor: colors.surface, borderColor: colors.border }]}>
      <AppText variant="metric" color={tone ?? 'text'} tabular numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </AppText>
      <AppText variant="caption" color="textMuted" numberOfLines={1}>
        {label}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingVertical: spacing.md },
  icon: { width: 36, height: 36, borderRadius: radius.sm, alignItems: 'center', justifyContent: 'center' },
  metric: { flex: 1, borderRadius: radius.md, borderWidth: StyleSheet.hairlineWidth, padding: spacing.md, gap: 2 },
});
