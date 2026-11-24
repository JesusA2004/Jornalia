import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { PunchRow } from '@/components/attendance';
import { HelpButton } from '@/components/help';
import { RectificationItem } from '@/components/requests';
import { Segmented } from '@/components/ui/fields';
import { Icon } from '@/components/ui/Icon';
import { AppText, Button, Card, EmptyState, ErrorState, LoadingState, Pill } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { useNow } from '@/hooks/useNow';
import { usePaginated } from '@/hooks/usePaginated';
import { api } from '@/services/container';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';
import type { HistoryDay } from '@/types/contracts';
import {
  addDays,
  civilDateInZone,
  formatCivilDateLong,
  formatMinutes,
  WORKDAY_STATUS_LABEL,
  workdayTone,
} from '@/utils/format';

type Range = '7' | '30' | '90';

export default function HistoryScreen() {
  const { profile } = useSignedIn();
  const { colors } = useTheme();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const [range, setRange] = useState<Range>('30');
  const to = civilDateInZone(new Date(), tz);
  const from = addDays(to, -(Number(range) - 1));
  const list = usePaginated((page) => api.attendance.history({ from, to, page }), [from, to]);

  return (
    <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: colors.background }}>
      <FlatList
        data={list.items}
        keyExtractor={(d) => d.date}
        contentContainerStyle={{ paddingHorizontal: spacing.xl, paddingBottom: spacing.huge, gap: spacing.md }}
        refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={() => void list.refresh()} tintColor={colors.textMuted} />}
        onEndReachedThreshold={0.4}
        onEndReached={list.loadMore}
        ListHeaderComponent={
          <View style={{ gap: spacing.md, paddingTop: spacing.lg, paddingBottom: spacing.xs }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
              <AppText variant="title" accessibilityRole="header">
                Historial
              </AppText>
              <HelpButton topic="historial" />
            </View>
            <Segmented
              options={[
                { value: '7', label: '7 días' },
                { value: '30', label: '30 días' },
                { value: '90', label: '90 días' },
              ]}
              value={range}
              onChange={setRange}
            />
            <Button title="Solicitudes" icon="requests" variant="secondary" onPress={() => router.push('/solicitudes')} />
            <View style={styles.legend}>
              <Pill label="Registro original" icon="lock" />
              <Pill label="Rectificación" icon="edit" tone="info" />
            </View>
          </View>
        }
        ListEmptyComponent={
          list.loading ? (
            <LoadingState label="Cargando historial…" />
          ) : list.error ? (
            <ErrorState message={list.error} onRetry={() => void list.refresh()} />
          ) : (
            <EmptyState icon="history" title="Sin registros" message="No hay jornadas registradas en este periodo." />
          )
        }
        ListFooterComponent={
          list.loadingMore ? (
            <ActivityIndicator color={colors.textMuted} style={{ marginVertical: spacing.lg }} />
          ) : list.error && list.items.length > 0 ? (
            <AppText variant="caption" color="danger" style={{ textAlign: 'center' }}>
              {list.error}
            </AppText>
          ) : undefined
        }
        renderItem={({ item }) => <DayCard day={item} tz={tz} />}
      />
    </SafeAreaView>
  );
}

function DayCard({ day, tz }: { day: HistoryDay; tz: string }) {
  const { colors } = useTheme();
  const now = useNow(60_000).getTime();
  return (
    <Card style={{ gap: spacing.sm }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: spacing.sm }}>
        <View style={{ flex: 1 }}>
          <AppText variant="bodyStrong">{formatCivilDateLong(day.date)}</AppText>
          <AppText variant="caption" color="textMuted">
            Trabajado {formatMinutes(day.worked_minutes)}
            {day.late_minutes > 0 ? ` · Retardo ${day.late_minutes} min` : ''}
          </AppText>
        </View>
        <Pill label={WORKDAY_STATUS_LABEL[day.status]} tone={workdayTone(day.status)} />
      </View>

      {day.punches.length > 0 ? (
        <View>
          <AppText variant="label" color="textSubtle" style={{ marginTop: spacing.xs }}>
            Registro original
          </AppText>
          {day.punches.map((p, i) => (
            <PunchRow key={p.uuid} punch={p} tz={tz} last={i === day.punches.length - 1} />
          ))}
        </View>
      ) : (
        <AppText variant="caption" color="textMuted">
          Sin marcajes registrados.
        </AppText>
      )}

      {day.rectifications.length > 0 ? (
        <View style={{ gap: spacing.sm }}>
          <AppText variant="label" color="goldStrong">
            Correcciones
          </AppText>
          {day.rectifications.map((r) => (
            <RectificationItem
              key={r.id}
              r={r}
              tz={tz}
              now={now}
              onPress={() => router.push({ pathname: '/rectificaciones/[id]', params: { id: String(r.id) } })}
            />
          ))}
        </View>
      ) : null}

      <Pressable
        onPress={() => router.push({ pathname: '/rectificacion', params: { date: day.date } })}
        accessibilityRole="button"
        style={({ pressed }) => [styles.link, { opacity: pressed ? 0.6 : 1, borderTopColor: colors.border }]}>
        <Icon name="edit" size={16} color={colors.accent} />
        <AppText variant="captionStrong" color="accent">
          Corregir este día
        </AppText>
      </Pressable>
    </Card>
  );
}

const styles = StyleSheet.create({
  legend: { flexDirection: 'row', gap: spacing.sm },
  link: { flexDirection: 'row', alignItems: 'center', gap: spacing.xs, borderTopWidth: StyleSheet.hairlineWidth, paddingTop: spacing.md, marginTop: spacing.xs },
});
