import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiRequestError } from '@/api/errors';
import { HelpButton } from '@/components/help';
import { Markdown } from '@/components/Markdown';
import { Screen } from '@/components/ui/Screen';
import { AppText, Button, Card, EmptyState, ErrorState, ListRow, LoadingState, Pill, SectionHeader } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { mergeSigned, readLocalSigned } from '@/features/documents/signedStore';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { spacing } from '@/theme/tokens';
import { formatDateTime } from '@/utils/format';

/** Consulta de lo firmado: convenio, aviso y acuses. */
export default function DocumentsScreen() {
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const [open, setOpen] = useState<number | null>(null);
  const q = useQuery(
    async () => {
      const local = await readLocalSigned();
      try {
        return mergeSigned(await api.documents.signed(), local);
      } catch (e) {
        // Servidor sin consulta de firmados todavía: constancias guardadas en el teléfono.
        if (e instanceof ApiRequestError && (e.status === 404 || e.status === 405)) return local;
        throw e;
      }
    },
    [],
    { cacheKey: 'documents.signed' },
  );
  const acks = profile.pending_acknowledgements;
  const docs = q.data ?? [];

  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={() => void q.refresh()}>
      <Stack.Screen options={{ title: 'Documentos', headerRight: () => <HelpButton topic="documentos" /> }} />
      {acks.length ? (
        <>
          <SectionHeader title="Por confirmar" />
          <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
            {acks.map((d, i) => (
              <ListRow
                key={d.id}
                icon="seal"
                iconTone="warning"
                title={d.title}
                subtitle={`Versión ${d.version}`}
                onPress={() => router.push({ pathname: '/acuses/[id]', params: { id: String(d.id) } })}
                last={i === acks.length - 1}
              />
            ))}
          </Card>
        </>
      ) : null}

      <SectionHeader title="Firmados" />
      {q.loading && !q.data ? (
        <LoadingState />
      ) : q.error && !q.data ? (
        <ErrorState message={q.error} onRetry={() => void q.refresh()} />
      ) : !docs.length ? (
        <EmptyState icon="signature" title="Sin documentos firmados" />
      ) : (
        <View style={{ gap: spacing.sm }}>
          {docs.map((d) => (
            <Card key={d.id} style={{ gap: spacing.sm }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }}>
                <AppText variant="bodyStrong" style={{ flex: 1 }}>
                  {d.title}
                </AppText>
                <Pill label={d.code === 'AVISO_PRIVACIDAD' ? 'Aceptado' : 'Firmado'} tone="success" icon="signature" />
              </View>
              <AppText variant="caption" color="textMuted">
                Versión {d.version}
                {d.signed_at ? ` · ${formatDateTime(d.signed_at, tz)}` : ''}
                {d.signature_method ? ` · ${d.signature_method === 'pin' ? 'NIP' : 'Huella'}` : ''}
              </AppText>
              {d.hash ? (
                <AppText variant="caption" color="textSubtle" numberOfLines={1}>
                  Sello {d.hash.slice(0, 16)}…
                </AppText>
              ) : null}
              {open === d.id && d.body_markdown ? <Markdown source={d.body_markdown} /> : null}
              <View style={{ flexDirection: 'row', gap: spacing.sm }}>
                {d.body_markdown ? (
                  <Button title={open === d.id ? 'Ocultar' : 'Leer'} variant="secondary" icon="document" onPress={() => setOpen(open === d.id ? null : d.id)} style={{ flex: 1 }} />
                ) : null}
              </View>
            </Card>
          ))}
        </View>
      )}
      <AppText variant="caption" color="textSubtle" style={{ textAlign: 'center' }}>
        {docs.length ? `${docs.length} documento(s)` : ''}
      </AppText>
    </Screen>
  );
}
