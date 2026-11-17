import { router, Stack, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, View } from 'react-native';

import { describeError } from '@/api/errors';
import { HelpButton } from '@/components/help';
import { ApprovalPill, RectificationItem } from '@/components/requests';
import { Segmented } from '@/components/ui/fields';
import { Screen } from '@/components/ui/Screen';
import { AppText, Button, Card, EmptyState, ErrorState, LoadingState, ProgressBar, SectionHeader } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { usePaginated } from '@/hooks/usePaginated';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { spacing } from '@/theme/tokens';
import type { Incidence, VacationRequest } from '@/types/contracts';
import { formatCivilDate, formatDateTime } from '@/utils/format';

type Tab = 'rect' | 'inc' | 'vac';

export default function RequestsScreen() {
  const [tab, setTab] = useState<Tab>('rect');
  const [version, setVersion] = useState(0);
  // Recarga al volver de un formulario.
  useFocusEffect(useCallback(() => setVersion((v) => v + 1), []));

  return (
    <Screen edges={['bottom']}>
      <Stack.Screen options={{ headerRight: () => <HelpButton topic="solicitudes" /> }} />
      <Segmented
        options={[
          { value: 'rect', label: 'Correcciones' },
          { value: 'inc', label: 'Incidencias' },
          { value: 'vac', label: 'Vacaciones' },
        ]}
        value={tab}
        onChange={setTab}
      />
      {tab === 'rect' ? <Rectifications version={version} /> : tab === 'inc' ? <Incidences version={version} /> : <Vacations version={version} />}
    </Screen>
  );
}

function LoadMore({ visible, loading, onPress }: { visible: boolean; loading: boolean; onPress: () => void }) {
  return visible ? <Button title="Cargar más" variant="ghost" loading={loading} onPress={onPress} /> : null;
}

function Rectifications({ version }: { version: number }) {
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const list = usePaginated((page) => api.rectifications.list(page), [version]);
  return (
    <>
      <Button title="Corregir un día" icon="add" onPress={() => router.push('/rectificacion')} />
      {list.loading ? (
        <LoadingState />
      ) : list.error && !list.items.length ? (
        <ErrorState message={list.error} onRetry={() => void list.refresh()} />
      ) : !list.items.length ? (
        <EmptyState icon="edit" title="Sin correcciones" />
      ) : (
        <View style={{ gap: spacing.sm }}>
          {list.items.map((r) => (
            <View key={r.id} style={{ gap: 4 }}>
              <AppText variant="captionStrong" color="textMuted">
                {formatCivilDate(r.date)}
              </AppText>
              <RectificationItem r={r} tz={tz} />
            </View>
          ))}
          <LoadMore visible={list.hasMore} loading={list.loadingMore} onPress={list.loadMore} />
        </View>
      )}
    </>
  );
}

function Incidences({ version }: { version: number }) {
  const list = usePaginated((page) => api.incidences.list(page), [version]);
  return (
    <>
      <Button title="Nueva incidencia" icon="add" onPress={() => router.push('/incidencia')} />
      {list.loading ? (
        <LoadingState />
      ) : list.error && !list.items.length ? (
        <ErrorState message={list.error} onRetry={() => void list.refresh()} />
      ) : !list.items.length ? (
        <EmptyState icon="incidence" title="Sin incidencias" />
      ) : (
        <View style={{ gap: spacing.sm }}>
          {list.items.map((i) => (
            <IncidenceCard key={i.id} i={i} />
          ))}
          <LoadMore visible={list.hasMore} loading={list.loadingMore} onPress={list.loadMore} />
        </View>
      )}
    </>
  );
}

function IncidenceCard({ i }: { i: Incidence }) {
  return (
    <Card style={{ gap: 4 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm }}>
        <AppText variant="bodyStrong" style={{ flex: 1 }}>
          {i.type.name}
        </AppText>
        <ApprovalPill status={i.status} />
      </View>
      <AppText variant="caption" color="textMuted">
        {i.starts_on === i.ends_on ? formatCivilDate(i.starts_on) : `${formatCivilDate(i.starts_on)} – ${formatCivilDate(i.ends_on)}`}
        {i.has_evidence ? ' · Con evidencia' : ''}
      </AppText>
      {i.reason ? <AppText variant="caption">{i.reason}</AppText> : null}
      {i.review_comment ? (
        <AppText variant="caption" color="textMuted">
          {i.reviewer ? `${i.reviewer}: ` : ''}
          {i.review_comment}
        </AppText>
      ) : null}
    </Card>
  );
}

function Vacations({ version }: { version: number }) {
  const balance = useQuery(() => api.vacations.balance(), [version], { cacheKey: 'vacations.balance' });
  const list = usePaginated((page) => api.vacations.list(page), [version]);
  const [cancelling, setCancelling] = useState<number | null>(null);

  const cancel = (v: VacationRequest) => {
    Alert.alert('Cancelar solicitud', `¿Cancelar tu solicitud del ${formatCivilDate(v.starts_on)} al ${formatCivilDate(v.ends_on)}?`, [
      { text: 'No', style: 'cancel' },
      {
        text: 'Sí, cancelar',
        style: 'destructive',
        onPress: async () => {
          setCancelling(v.id);
          try {
            await api.vacations.cancel(v.id);
            await Promise.all([list.refresh(), balance.refresh()]);
          } catch (e) {
            Alert.alert('No se pudo cancelar', describeError(e));
          } finally {
            setCancelling(null);
          }
        },
      },
    ]);
  };

  const b = balance.data;
  return (
    <>
      {balance.loading ? (
        <LoadingState label="Calculando saldo…" />
      ) : b ? (
        <Card style={{ gap: spacing.sm }}>
          <AppText variant="label" color="textMuted">
            Saldo disponible
          </AppText>
          <View style={{ flexDirection: 'row', alignItems: 'baseline', gap: spacing.sm }}>
            <AppText variant="display" tabular style={{ fontSize: 44, lineHeight: 52 }}>
              {b.available_days}
            </AppText>
            <AppText variant="body" color="textMuted">
              {b.available_days === 1 ? 'día' : 'días'} de {b.entitled_days}
            </AppText>
          </View>
          <ProgressBar value={b.entitled_days > 0 ? (b.used_days + b.pending_days) / b.entitled_days : 0} />
          <AppText variant="caption" color="textMuted">
            Usados {b.used_days} · En revisión {b.pending_days} · Año de servicio {b.service_year}
          </AppText>
          <AppText variant="caption" color="textMuted">
            Periodo {formatCivilDate(b.period_starts_on)} – {formatCivilDate(b.period_ends_on)} · Prima vacacional {b.vacation_premium_percent} %
          </AppText>
          <AppText variant="caption" color="textSubtle">
            Ley: {b.legal_days} días{b.extra_days > 0 ? ` + ${b.extra_days} adicionales` : ''} · {b.rule_source}
          </AppText>
          {balance.stale ? (
            <AppText variant="caption" color="warning">
              Saldo guardado; sin conexión con el servidor.
            </AppText>
          ) : null}
        </Card>
      ) : (
        <ErrorState message={balance.error ?? 'Sin saldo disponible.'} onRetry={() => void balance.refresh()} />
      )}
      <Button
        title="Solicitar vacaciones"
        icon="vacation"
        disabled={!b || b.available_days <= 0}
        onPress={() => router.push('/vacaciones')}
      />
      <SectionHeader title="Mis solicitudes" />
      {list.loading ? (
        <LoadingState />
      ) : list.error && !list.items.length ? (
        <ErrorState message={list.error} onRetry={() => void list.refresh()} />
      ) : !list.items.length ? (
        <EmptyState icon="vacation" title="Sin solicitudes" />
      ) : (
        <View style={{ gap: spacing.sm }}>
          {list.items.map((v) => (
            <Card key={v.id} style={{ gap: 4 }}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm }}>
                <AppText variant="bodyStrong" style={{ flex: 1 }}>
                  {formatCivilDate(v.starts_on)} – {formatCivilDate(v.ends_on)}
                </AppText>
                <ApprovalPill status={v.status} />
              </View>
              <AppText variant="caption" color="textMuted">
                {v.days} {v.days === 1 ? 'día' : 'días'} · solicitada {formatDateTime(v.created_at)}
              </AppText>
              {v.comment ? <AppText variant="caption">{v.comment}</AppText> : null}
              {v.review_comment ? (
                <AppText variant="caption" color="textMuted">
                  {v.reviewer ? `${v.reviewer}: ` : ''}
                  {v.review_comment}
                </AppText>
              ) : null}
              {v.status === 'pending' ? (
                <Button title="Cancelar solicitud" variant="ghost" loading={cancelling === v.id} onPress={() => cancel(v)} />
              ) : null}
            </Card>
          ))}
          <LoadMore visible={list.hasMore} loading={list.loadingMore} onPress={list.loadMore} />
        </View>
      )}
    </>
  );
}
