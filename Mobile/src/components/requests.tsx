import * as DocumentPicker from 'expo-document-picker';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { router } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Pressable, StyleSheet, View } from 'react-native';

import type { EvidenceFile } from '@/api/endpoints';
import {
  comparisonOf,
  dueInfo,
  dueLabel,
  STAGE_LABEL,
  STAGE_ORDER,
  STAGE_TONE,
  stageOf,
  stageStep,
} from '@/features/requests/rectification';
import { EVIDENCE_PROBLEM_LABEL, evidenceStore, MAX_EVIDENCE_FILES, useEvidence } from '@/features/requests/evidenceStore';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import type { ApprovalStatus, Rectification, RectificationEvidence, RectificationSide } from '@/types/contracts';
import { APPROVAL_LABEL, approvalTone, formatCivilDate, formatTime, PUNCH_TYPE_LABEL, RECTIFICATION_KIND_LABEL } from '@/utils/format';

import { Icon } from './ui/Icon';
import { AppText, Button, Pill } from './ui/primitives';

/** «Entrada 09:02» o «—». */
export function describeSide(s: RectificationSide, tz: string): string {
  if (!s.type && !s.time && s.summary) return s.summary;
  const type = s.type ? PUNCH_TYPE_LABEL[s.type] : 'Marcaje';
  return s.time ? `${type} ${formatTime(s.time, tz)}` : (s.summary ?? `${type} sin hora`);
}

/** Tarjeta resumen de una rectificación (lista). */
export function RectificationItem({ r, tz, now, onPress }: { r: Rectification; tz: string; now: number; onPress?: () => void }) {
  const { colors } = useTheme();
  const stage = stageOf(r);
  const due = dueInfo(r, now);
  const { proposed } = comparisonOf(r);
  const highlight = stage === 'por_firmar';
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      accessibilityRole="button"
      accessibilityLabel={`${RECTIFICATION_KIND_LABEL[r.kind]}, ${formatCivilDate(r.date)}, ${STAGE_LABEL[stage]}`}
      style={({ pressed }) => [
        styles.rect,
        {
          backgroundColor: colors.surface,
          borderColor: highlight ? colors.gold : colors.border,
          borderWidth: highlight ? 2 : StyleSheet.hairlineWidth,
          opacity: pressed ? 0.75 : 1,
        },
      ]}>
      <View style={styles.rowBetween}>
        <AppText variant="bodyStrong" style={{ flex: 1 }} numberOfLines={1}>
          {formatCivilDate(r.date)}
        </AppText>
        <Pill label={STAGE_LABEL[stage]} tone={STAGE_TONE[stage]} />
      </View>
      <AppText variant="caption" color="textMuted" numberOfLines={1}>
        {RECTIFICATION_KIND_LABEL[r.kind]}
        {proposed.length ? ` · ${proposed.map((p) => describeSide(p, tz)).join(', ')}` : ''}
      </AppText>
      {due ? (
        <View style={styles.dueRow}>
          <Icon name="timer" size={16} color={due.overdue || due.urgent ? colors.danger : colors.goldStrong} />
          <AppText variant="captionStrong" style={{ color: due.overdue || due.urgent ? colors.danger : colors.goldStrong }}>
            {dueLabel(due)}
          </AppText>
        </View>
      ) : null}
      {onPress ? <Icon name="chevronRight" size={16} color={colors.textSubtle} style={styles.chev} /> : null}
    </Pressable>
  );
}

/** Barra de 4 pasos (autorizar → firmar → validar → aplicada). */
export function StageProgress({ r }: { r: Rectification }) {
  const { colors } = useTheme();
  const stage = stageOf(r);
  if (stage === 'rechazada') return <Pill label="Rechazada" tone="danger" icon="block" />;
  const step = stageStep(stage);
  return (
    <View style={{ gap: spacing.xs }} accessibilityLabel={`Paso ${step + 1} de ${STAGE_ORDER.length}: ${STAGE_LABEL[stage]}`}>
      <View style={styles.steps}>
        {STAGE_ORDER.map((s, i) => (
          <View key={s} style={[styles.stepBar, { backgroundColor: i <= step ? (s === 'por_firmar' && i === step ? colors.gold : colors.primary) : colors.surfaceMuted }]} />
        ))}
      </View>
      <View style={styles.steps}>
        {STAGE_ORDER.map((s, i) => (
          <AppText key={s} variant="caption" color={i === step ? 'text' : 'textSubtle'} style={{ flex: 1, fontSize: 11 }} numberOfLines={2}>
            {STAGE_LABEL[s]}
          </AppText>
        ))}
      </View>
    </View>
  );
}

/** «Registro original» frente a «Propuesta». El original se muestra sellado. */
export function Comparison({ r, tz }: { r: Rectification; tz: string }) {
  const { colors } = useTheme();
  const { original, proposed } = comparisonOf(r);
  const col = (title: string, items: RectificationSide[], kind: 'original' | 'proposed') => (
    <View
      style={[
        styles.col,
        { backgroundColor: kind === 'original' ? colors.surfaceMuted : colors.goldSoft, borderColor: kind === 'original' ? colors.border : colors.gold },
      ]}>
      <View style={styles.colHead}>
        <Icon name={kind === 'original' ? 'lock' : 'edit'} size={14} color={kind === 'original' ? colors.textMuted : colors.goldStrong} />
        <AppText variant="label" color={kind === 'original' ? 'textMuted' : 'goldStrong'} numberOfLines={1}>
          {title}
        </AppText>
      </View>
      {items.length ? (
        items.map((s, i) => (
          <View key={i} style={{ gap: 0 }}>
            <AppText variant="caption" color="textMuted" numberOfLines={1}>
              {s.type ? PUNCH_TYPE_LABEL[s.type] : 'Marcaje'}
            </AppText>
            <AppText variant="metric" tabular>
              {s.time ? formatTime(s.time, tz) : '—'}
            </AppText>
            {s.summary ? (
              <AppText variant="caption" color="textMuted" numberOfLines={3}>
                {s.summary}
              </AppText>
            ) : null}
          </View>
        ))
      ) : (
        <AppText variant="bodyStrong" color="textMuted">
          {kind === 'original' ? 'Sin registro' : 'Sin cambio'}
        </AppText>
      )}
    </View>
  );
  return (
    <View style={styles.compare}>
      {col('Registro original', original, 'original')}
      <Icon name="arrowRight" size={20} color={colors.textSubtle} />
      {col('Propuesta', proposed, 'proposed')}
    </View>
  );
}

/** Evidencias ya enviadas (nombre, tamaño y sello SHA-256; se abren sólo si hay URL). */
export function EvidenceList({ items }: { items: RectificationEvidence[] }) {
  const { colors } = useTheme();
  if (!items.length) return null;
  return (
    <View style={{ gap: spacing.xs }}>
      {items.map((e) => {
        const isImage = (e.mime ?? '').startsWith('image/');
        return (
          <Pressable
            key={String(e.id)}
            disabled={!e.url}
            onPress={() => e.url && void WebBrowser.openBrowserAsync(e.url)}
            accessibilityRole="link"
            style={({ pressed }) => [styles.evidence, { borderColor: colors.border, backgroundColor: colors.surface, opacity: pressed ? 0.7 : 1 }]}>
            {isImage && e.url ? (
              <Image source={{ uri: e.url }} style={styles.thumb} contentFit="cover" accessibilityLabel={e.name} />
            ) : (
              <View style={[styles.thumb, styles.docThumb, { backgroundColor: colors.surfaceMuted }]}>
                <Icon name="document" size={24} color={colors.textMuted} />
              </View>
            )}
            <View style={{ flex: 1 }}>
              <AppText variant="caption" numberOfLines={2}>
                {e.name}
              </AppText>
              <AppText variant="caption" color="textSubtle" numberOfLines={1}>
                {[e.size ? formatBytes(e.size) : null, e.sha256 ? `SHA-256 ${e.sha256.slice(0, 10)}…` : null].filter(Boolean).join(' · ')}
              </AppText>
            </View>
            {e.url ? <Icon name="chevronRight" size={16} color={colors.textSubtle} /> : null}
          </Pressable>
        );
      })}
    </View>
  );
}

function formatBytes(n: number): string {
  if (n >= 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(n / 1024))} KB`;
}

export function ApprovalPill({ status }: { status: ApprovalStatus }) {
  return <Pill label={APPROVAL_LABEL[status]} tone={approvalTone(status)} />;
}

function extOf(name: string, fallback: string): string {
  const m = /\.([a-z0-9]{2,5})$/i.exec(name);
  return m ? m[1]!.toLowerCase() : fallback;
}

/** Adjuntar evidencia: cámara, galería o documento (PDF). */
export function EvidencePicker({ required, max = MAX_EVIDENCE_FILES, error }: { required?: boolean; max?: number; error?: string | null }) {
  const evidence = useEvidence();
  const { colors } = useTheme();
  const [busy, setBusy] = useState(false);
  const full = evidence.length >= max;

  const add = (file: EvidenceFile) => {
    const problem = evidenceStore.add(file);
    if (problem) Alert.alert('No se agregó', EVIDENCE_PROBLEM_LABEL[problem]);
  };

  const fromGallery = async () => {
    setBusy(true);
    try {
      const r = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.7, allowsMultipleSelection: max - evidence.length > 1, selectionLimit: max - evidence.length });
      if (r.canceled) return;
      for (const a of r.assets) {
        const type = a.mimeType ?? 'image/jpeg';
        add({ uri: a.uri, name: a.fileName ?? `evidencia-${Date.now()}.${extOf(a.uri, 'jpg')}`, type, size: a.fileSize ?? null });
      }
    } finally {
      setBusy(false);
    }
  };

  const fromDocuments = async () => {
    setBusy(true);
    try {
      const r = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true, multiple: false });
      if (r.canceled) return;
      for (const a of r.assets) add({ uri: a.uri, name: a.name, type: a.mimeType ?? 'application/pdf', size: a.size ?? null });
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={{ gap: spacing.sm }}>
      <AppText variant="captionStrong" color={error ? 'danger' : 'textMuted'}>
        Evidencia {required ? '(obligatoria)' : '(opcional)'}
      </AppText>
      {evidence.map((f) => (
        <View key={f.uri} style={[styles.evidence, { borderColor: colors.borderStrong, backgroundColor: colors.surface }]}>
          {f.type.startsWith('image/') ? (
            <Image source={{ uri: f.uri }} style={styles.thumb} contentFit="cover" accessibilityLabel="Evidencia" />
          ) : (
            <View style={[styles.thumb, styles.docThumb, { backgroundColor: colors.surfaceMuted }]}>
              <Icon name="document" size={24} color={colors.textMuted} />
            </View>
          )}
          <AppText variant="caption" style={{ flex: 1 }} numberOfLines={2}>
            {f.name}
          </AppText>
          <Pressable onPress={() => evidenceStore.remove(f.uri)} accessibilityRole="button" accessibilityLabel="Quitar evidencia" hitSlop={12}>
            <Icon name="close" size={20} color={colors.danger} />
          </Pressable>
        </View>
      ))}
      {!full ? (
        <View style={styles.pickRow}>
          <PickButton icon="camera" label="Cámara" disabled={busy} onPress={() => router.push('/camara')} />
          <PickButton icon="photo" label="Galería" disabled={busy} onPress={() => void fromGallery()} />
          <PickButton icon="attach" label="Documento" disabled={busy} onPress={() => void fromDocuments()} />
        </View>
      ) : null}
      {error ? (
        <AppText variant="caption" color="danger" accessibilityLiveRegion="polite">
          {error}
        </AppText>
      ) : null}
    </View>
  );
}

function PickButton({ icon, label, onPress, disabled }: { icon: 'camera' | 'photo' | 'attach'; label: string; onPress: () => void; disabled?: boolean }) {
  const { colors } = useTheme();
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={`Adjuntar desde ${label}`}
      style={({ pressed }) => [styles.pick, { backgroundColor: colors.surfaceMuted, opacity: disabled ? 0.5 : pressed ? 0.7 : 1 }]}>
      <Icon name={icon} size={26} color={colors.primary} />
      <AppText variant="captionStrong" numberOfLines={1}>
        {label}
      </AppText>
    </Pressable>
  );
}

/** Acción secundaria reutilizable para «Corregir un día». */
export function NewRectificationButton({ date }: { date?: string }) {
  return (
    <Button
      title="Corregir un día"
      icon="add"
      onPress={() => router.push(date ? { pathname: '/rectificacion', params: { date } } : '/rectificacion')}
    />
  );
}

const styles = StyleSheet.create({
  rect: { borderRadius: radius.lg, padding: spacing.md, paddingRight: spacing.xxl + spacing.xs, gap: 4 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.sm },
  dueRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 2 },
  chev: { position: 'absolute', right: spacing.md, top: '50%' },
  steps: { flexDirection: 'row', gap: 6 },
  stepBar: { flex: 1, height: 6, borderRadius: 3 },
  compare: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  col: { flex: 1, borderRadius: radius.md, borderWidth: 1, padding: spacing.md, gap: spacing.xs, minHeight: 96 },
  colHead: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  evidence: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, borderWidth: 1, borderRadius: radius.md, padding: spacing.sm },
  thumb: { width: 52, height: 52, borderRadius: radius.sm },
  docThumb: { alignItems: 'center', justifyContent: 'center' },
  pickRow: { flexDirection: 'row', gap: spacing.sm },
  pick: { flex: 1, minHeight: 76, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center', gap: 4, paddingHorizontal: spacing.xs },
});
