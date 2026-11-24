import { useState } from 'react';
import { Alert, Pressable, View } from 'react-native';

import { HelpButton } from '@/components/help';
import { Markdown } from '@/components/Markdown';
import { SignSheet } from '@/components/SignSheet';
import { BrandGradient, Logo } from '@/components/ui/brand';
import { Icon } from '@/components/ui/Icon';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card, LoadingState } from '@/components/ui/primitives';
import { useAuth } from '@/features/auth/AuthProvider';
import { useDocuments } from '@/features/documents/DocumentsProvider';
import { actionLabel, documentStatement, purposeFor } from '@/features/documents/gate';
import { saveLocalSigned, toSignedDocument } from '@/features/documents/signedStore';
import { api } from '@/services/container';
import { useTheme } from '@/theme/ThemeProvider';
import { radius, spacing } from '@/theme/tokens';
import { formatDateTime } from '@/utils/format';

/**
 * Flujo bloqueante: convenio de registro electrónico y aviso de privacidad.
 * Hasta firmarlos/aceptarlos no se puede marcar.
 */
export default function PendingDocumentsScreen() {
  const docs = useDocuments();
  const { signOut } = useAuth();
  const { colors } = useTheme();
  const [read, setRead] = useState<Record<number, boolean>>({});
  const [signing, setSigning] = useState(false);
  const doc = docs.docs[0] ?? null;
  const total = docs.docs.length;

  if (!doc) return <LoadingState label="Cargando…" />;
  const accepted = read[doc.id] === true;
  const verb = actionLabel(doc.code);

  return (
    <Screen
      edges={['top', 'bottom']}
      footer={
        <View style={{ paddingHorizontal: spacing.xl, paddingBottom: spacing.lg, paddingTop: spacing.sm, gap: spacing.sm, backgroundColor: colors.background }}>
          <Pressable
            onPress={() => setRead((r) => ({ ...r, [doc.id]: !accepted }))}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: accepted }}
            style={{ flexDirection: 'row', gap: spacing.md, alignItems: 'center', minHeight: 48 }}>
            <View
              style={{
                width: 26,
                height: 26,
                borderRadius: 7,
                borderWidth: 2,
                borderColor: accepted ? colors.primary : colors.borderStrong,
                backgroundColor: accepted ? colors.primary : 'transparent',
                alignItems: 'center',
                justifyContent: 'center',
              }}>
              {accepted ? <Icon name="check" size={18} color={colors.onPrimary} /> : null}
            </View>
            <AppText variant="bodyStrong" style={{ flex: 1 }}>
              Leí el documento completo
            </AppText>
          </Pressable>
          <Button title={verb} icon="signature" size="lg" disabled={!accepted} onPress={() => setSigning(true)} />
        </View>
      }>
      <BrandGradient style={{ borderRadius: radius.xl, padding: spacing.xl, gap: spacing.sm }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
          <Logo width={92} />
          <HelpButton topic="convenio" light />
        </View>
        <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.85)' }}>
          {total > 1 ? `Documento 1 de ${total}` : 'Último paso'}
        </AppText>
        <AppText variant="title" style={{ color: '#FFFFFF' }} accessibilityRole="header">
          {doc.title}
        </AppText>
        <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.85)' }}>
          Versión {doc.version} · {formatDateTime(doc.published_at)}
        </AppText>
      </BrandGradient>

      {docs.fromCache ? <Banner tone="warning" icon="offline" title="Sin internet. Conéctate para firmar." /> : null}

      <Card>
        <Markdown source={doc.body_markdown} />
      </Card>
      <Button
        title="Cerrar sesión"
        variant="ghost"
        icon="logout"
        onPress={() =>
          Alert.alert('¿Cerrar sesión?', 'Sin este documento no puedes marcar.', [
            { text: 'Cancelar', style: 'cancel' },
            { text: 'Cerrar sesión', style: 'destructive', onPress: () => void signOut(null) },
          ])
        }
      />

      <SignSheet
        visible={signing}
        title={`${verb} ${doc.code === 'AVISO_PRIVACIDAD' ? 'aviso' : 'convenio'}`}
        statement={documentStatement(doc)}
        purpose={purposeFor(doc)}
        subjectId={doc.id}
        payloadSha256={doc.signature_payload_sha256}
        submit={async (body) => {
          const result = await api.documents.sign(doc.id, body);
          await saveLocalSigned(toSignedDocument(doc, result, body.method));
        }}
        onClose={() => setSigning(false)}
        onSigned={() => {
          setSigning(false);
          docs.markSigned(doc.id);
        }}
      />
    </Screen>
  );
}
