import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import { KeyboardAvoidingView } from 'react-native-keyboard-controller';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { describeError } from '@/api/errors';
import { HelpButton } from '@/components/help';
import { Comparison, describeSide, EvidenceList, StageProgress } from '@/components/requests';
import { SignSheet } from '@/components/SignSheet';
import { TextField } from '@/components/ui/fields';
import { Icon } from '@/components/ui/Icon';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card, ErrorState, LoadingState, Pill, SectionHeader } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { canSignNow, dueInfo, dueLabel, signatureStatement, STAGE_LABEL, STAGE_TONE, stageOf, validateDeclineReason } from '@/features/requests/rectification';
import { fetchRectification } from '@/features/requests/rectificationsApi';
import { useNow } from '@/hooks/useNow';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import { formatCivilDateLong, formatDateTime, RECTIFICATION_KIND_LABEL } from '@/utils/format';

/** Detalle de una rectificación: original vs propuesta, evidencia y firma. */
export default function RectificationDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const numericId = Number(id);
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const { colors } = useTheme();
  const now = useNow(60_000).getTime();
  const q = useQuery(() => fetchRectification(numericId), [numericId]);
  const [signing, setSigning] = useState(false);
  const [declining, setDeclining] = useState(false);
  const [done, setDone] = useState<'signed' | 'declined' | null>(null);

  const r = q.data;
  const stage = r ? stageOf(r) : null;
  const due = r ? dueInfo(r, now) : null;
  const canSign = !!r && canSignNow(r, now) && !done;

  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={() => void q.refresh()}>
      <Stack.Screen options={{ title: 'Corrección', headerRight: () => <HelpButton topic="firma" /> }} />
      {q.loading && !r ? (
        <LoadingState />
      ) : !r || !stage ? (
        <ErrorState message={q.error ?? 'No disponible.'} onRetry={() => void q.refresh()} />
      ) : (
        <>
          <View style={{ gap: 2 }}>
            <AppText variant="heading">{formatCivilDateLong(r.date)}</AppText>
            <View style={{ flexDirection: 'row', gap: spacing.sm, flexWrap: 'wrap', alignItems: 'center' }}>
              <Pill label={STAGE_LABEL[stage]} tone={STAGE_TONE[stage]} />
              <AppText variant="caption" color="textMuted">
                {RECTIFICATION_KIND_LABEL[r.kind]}
              </AppText>
            </View>
          </View>

          <StageProgress r={r} />

          {done === 'signed' ? <Banner tone="success" title="Firmada. Ahora la valida RH." /> : null}
          {done === 'declined' ? <Banner tone="info" title="Rechazaste la corrección." /> : null}

          {due ? (
            <View style={[styles.due, { backgroundColor: due.overdue || due.urgent ? colors.dangerBg : colors.goldSoft }]}>
              <Icon name="timer" size={22} color={due.overdue || due.urgent ? colors.danger : colors.goldStrong} />
              <View style={{ flex: 1 }}>
                <AppText variant="bodyStrong" style={{ color: due.overdue || due.urgent ? colors.danger : colors.goldStrong }}>
                  {dueLabel(due)}
                </AppText>
                <AppText variant="caption" color="textMuted">
                  Límite {formatDateTime(r.signature_due_at!, tz)}
                </AppText>
              </View>
            </View>
          ) : null}

          <Comparison r={r} tz={tz} />
          <AppText variant="caption" color="textMuted">
            <Icon name="lock" size={12} color={colors.textMuted} /> El registro original no se modifica.
          </AppText>

          <SectionHeader title="Motivo" />
          <Card>
            <AppText variant="body">{r.reason}</AppText>
          </Card>

          {r.evidences?.length ? (
            <>
              <SectionHeader title="Evidencia" />
              <EvidenceList items={r.evidences} />
            </>
          ) : null}

          {r.reviewer ? (
            <AppText variant="caption" color="textMuted">
              Revisó: {r.reviewer}
              {r.review_comment ? ` · ${r.review_comment}` : ''}
            </AppText>
          ) : null}
          {r.signature?.signed_at ? (
            <Pill
              label={`Firmada ${formatDateTime(r.signature.signed_at, tz)} · ${r.signature.method === 'pin' ? 'NIP' : 'Huella'}`}
              tone="success"
              icon="signature"
            />
          ) : null}
          {r.applied_at ? <Pill label={`Aplicada ${formatDateTime(r.applied_at, tz)}`} tone="success" icon="checkCircle" /> : null}
          {r.rejection_reason ? (
            <AppText variant="caption" color="textMuted">
              Motivo de rechazo: {r.rejection_reason}
            </AppText>
          ) : null}

          {canSign ? (
            <View style={{ gap: spacing.sm }}>
              <Button title="Firmar" icon="signature" size="lg" onPress={() => setSigning(true)} />
              <Button title="Rechazar" icon="block" variant="danger" onPress={() => setDeclining(true)} />
            </View>
          ) : null}

          <SignSheet
            visible={signing}
            title="Firmar corrección"
            statement={signatureStatement(r, (s) => describeSide(s, tz))}
            purpose="rectification"
            subjectId={r.id}
            payloadSha256={r.signature_payload_sha256}
            submit={async (body) => {
              await api.rectifications.sign(r.id, body);
            }}
            onClose={() => setSigning(false)}
            onSigned={() => {
              setSigning(false);
              setDone('signed');
              void q.refresh();
            }}
          />
          <DeclineSheet
            visible={declining}
            onClose={() => setDeclining(false)}
            submit={async (reason) => {
              await api.rectifications.decline(r.id, reason);
              setDeclining(false);
              setDone('declined');
              void q.refresh();
            }}
          />
          {done ? <Button title="Volver" variant="secondary" onPress={() => router.back()} /> : null}
        </>
      )}
    </Screen>
  );
}

function DeclineSheet({ visible, onClose, submit }: { visible: boolean; onClose: () => void; submit: (reason: string) => Promise<void> }) {
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const send = async () => {
    const problem = validateDeclineReason(reason);
    if (problem) return setError(problem);
    setBusy(true);
    setError(null);
    try {
      await submit(reason.trim());
      setReason('');
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <Pressable style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay }]} onPress={busy ? undefined : onClose} accessibilityLabel="Cerrar" />
      <KeyboardAvoidingView behavior="padding" style={{ flex: 1, justifyContent: 'flex-end' }} pointerEvents="box-none">
        <View style={[styles.sheet, { backgroundColor: colors.surface, paddingBottom: insets.bottom + spacing.xl }]}>
          <AppText variant="heading">Rechazar corrección</AppText>
          <TextField label="Motivo" value={reason} onChangeText={setReason} multiline maxLength={500} error={error} autoFocus />
          <Button title="Rechazar" variant="danger" icon="block" size="lg" loading={busy} onPress={() => void send()} />
          <Button title="Cancelar" variant="secondary" disabled={busy} onPress={onClose} />
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  due: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, padding: spacing.md, borderRadius: radius.md },
  sheet: { paddingHorizontal: spacing.xxl, paddingTop: spacing.xl, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, gap: spacing.md },
});
