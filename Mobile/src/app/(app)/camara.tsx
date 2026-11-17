import { CameraView, useCameraPermissions } from 'expo-camera';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Screen } from '@/components/ui/Screen';
import { Icon } from '@/components/ui/Icon';
import { AppText, Button, EmptyState } from '@/components/ui/primitives';
import { evidenceStore } from '@/features/requests/evidenceStore';
import { spacing } from '@/theme/tokens';

/** Captura de evidencia fotográfica para solicitudes (no se usa para identificar a la persona). */
export default function CameraScreen() {
  const [permission, requestPermission] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!permission) {
    return (
      <Screen edges={['top', 'bottom']}>
        <ActivityIndicator />
      </Screen>
    );
  }

  if (!permission.granted) {
    return (
      <Screen edges={['top', 'bottom']}>
        <EmptyState
          icon="camera"
          title="Permiso de cámara"
          message="La cámara se usa sólo para fotografiar documentos de evidencia (por ejemplo, un justificante médico)."
          action={
            permission.canAskAgain
              ? { label: 'Permitir cámara', onPress: () => void requestPermission(), icon: 'camera' }
              : { label: 'Abrir Ajustes', onPress: () => void Linking.openSettings() }
          }
        />
        <Button title="Cancelar" variant="ghost" onPress={() => router.back()} />
      </Screen>
    );
  }

  const take = async () => {
    if (!camera.current || busy) return;
    setBusy(true);
    setError(null);
    try {
      const photo = await camera.current.takePictureAsync({ quality: 0.7, skipProcessing: false });
      if (!photo?.uri) throw new Error('Sin imagen');
      evidenceStore.set({ uri: photo.uri, name: `evidencia-${Date.now()}.jpg`, type: 'image/jpeg' });
      router.back();
    } catch {
      setError('No se pudo tomar la foto. Intenta de nuevo.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: '#000' }}>
      <CameraView ref={camera} style={StyleSheet.absoluteFill} facing="back" onCameraReady={() => setReady(true)} />
      <SafeAreaView edges={['top', 'bottom']} style={styles.overlay}>
        <View style={styles.top}>
          <Pressable onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="Cerrar cámara" style={styles.round} hitSlop={10}>
            <Icon name="close" size={22} color="#fff" />
          </Pressable>
          <AppText variant="bodyStrong" style={{ color: '#fff' }}>
            Evidencia
          </AppText>
          <View style={{ width: 44 }} />
        </View>
        <View style={{ alignItems: 'center', gap: spacing.md, paddingBottom: spacing.xl }}>
          {error ? (
            <AppText variant="caption" style={{ color: '#fff' }}>
              {error}
            </AppText>
          ) : (
            <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.8)' }}>
              Encuadra el documento completo y con buena luz
            </AppText>
          )}
          <Pressable
            onPress={() => void take()}
            disabled={!ready || busy}
            accessibilityRole="button"
            accessibilityLabel="Tomar foto"
            style={[styles.shutter, { opacity: ready && !busy ? 1 : 0.5 }]}>
            {busy ? <ActivityIndicator color="#000" /> : <View style={styles.shutterInner} />}
          </Pressable>
        </View>
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: { flex: 1, justifyContent: 'space-between' },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: spacing.lg, paddingTop: spacing.sm },
  round: { width: 44, height: 44, borderRadius: 22, backgroundColor: 'rgba(0,0,0,0.45)', alignItems: 'center', justifyContent: 'center' },
  shutter: { width: 76, height: 76, borderRadius: 38, borderWidth: 4, borderColor: '#fff', alignItems: 'center', justifyContent: 'center' },
  shutterInner: { width: 58, height: 58, borderRadius: 29, backgroundColor: '#fff' },
});
