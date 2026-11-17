import { Image } from 'expo-image';
import { router } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { evidenceStore, useEvidence } from '@/features/requests/evidenceStore';

import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { ApprovalStatus, Rectification } from '@/types/contracts';
import { APPROVAL_LABEL, approvalTone, formatDateTime, formatTime, PUNCH_TYPE_LABEL, RECTIFICATION_KIND_LABEL } from '@/utils/format';

import { Icon } from './ui/Icon';
import { AppText, Button, Pill } from './ui/primitives';

/** Rectificación: se muestra siempre separada y diferenciada del registro original. */
export function RectificationItem({ r, tz }: { r: Rectification; tz: string }) {
  const { colors } = useTheme();
  return (
    <View style={[styles.rect, { borderLeftColor: colors.info, backgroundColor: colors.infoBg }]}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm }}>
        <AppText variant="captionStrong" style={{ flex: 1 }}>
          {RECTIFICATION_KIND_LABEL[r.kind]}
        </AppText>
        <Pill label={APPROVAL_LABEL[r.status]} tone={approvalTone(r.status)} />
      </View>
      {r.proposed_type || r.proposed_time ? (
        <AppText variant="caption">
          Propuesto: {r.proposed_type ? PUNCH_TYPE_LABEL[r.proposed_type] : 'mismo tipo'}
          {r.proposed_time ? ` a las ${formatTime(r.proposed_time, tz)}` : ''}
        </AppText>
      ) : null}
      <AppText variant="caption" color="textMuted">
        Motivo: {r.reason}
      </AppText>
      {r.review_comment ? (
        <AppText variant="caption" color="textMuted">
          {r.reviewer ? `${r.reviewer}: ` : ''}
          {r.review_comment}
        </AppText>
      ) : null}
      <AppText variant="caption" color="textSubtle">
        Solicitada {formatDateTime(r.created_at, tz)}
      </AppText>
    </View>
  );
}

export function ApprovalPill({ status }: { status: ApprovalStatus }) {
  return <Pill label={APPROVAL_LABEL[status]} tone={approvalTone(status)} />;
}

const styles = StyleSheet.create({
  rect: { borderLeftWidth: 3, borderRadius: radius.sm, padding: spacing.md, gap: 2 },
});

/** Adjuntar evidencia fotográfica (cámara). */
export function EvidencePicker({ required }: { required?: boolean }) {
  const evidence = useEvidence();
  const { colors } = useTheme();
  return (
    <View style={{ gap: spacing.xs }}>
      <AppText variant="captionStrong" color="textMuted">
        Evidencia {required ? '(obligatoria)' : '(opcional)'}
      </AppText>
      {evidence ? (
        <View style={[evidenceStyles.box, { borderColor: colors.borderStrong, backgroundColor: colors.surface }]}>
          <Image source={{ uri: evidence.uri }} style={evidenceStyles.thumb} contentFit="cover" accessibilityLabel="Foto de evidencia" />
          <View style={{ flex: 1 }}>
            <AppText variant="bodyStrong">Foto adjunta</AppText>
            <AppText variant="caption" color="textMuted">
              Se enviará con tu solicitud.
            </AppText>
          </View>
          <Pressable onPress={() => evidenceStore.set(null)} accessibilityRole="button" accessibilityLabel="Quitar evidencia" hitSlop={10}>
            <Icon name="trash" size={20} color={colors.danger} />
          </Pressable>
        </View>
      ) : (
        <Button title="Tomar foto de evidencia" icon="camera" variant="secondary" onPress={() => router.push('/camara')} />
      )}
    </View>
  );
}

const evidenceStyles = StyleSheet.create({
  box: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, borderWidth: 1, borderRadius: radius.md, padding: spacing.sm },
  thumb: { width: 56, height: 56, borderRadius: radius.sm },
});
