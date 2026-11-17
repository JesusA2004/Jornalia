import { StatusBar } from 'expo-status-bar';
import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiRequestError, describeError } from '@/api/errors';
import { TextField, type TextInputHandle } from '@/components/ui/fields';
import { BrandGradient, Logo, Mascot } from '@/components/ui/brand';
import { Icon } from '@/components/ui/Icon';
import { AppText, Banner, Button } from '@/components/ui/primitives';
import { API_URL, APP_VARIANT } from '@/config/env';
import { SecretStoreError, useAuth } from '@/features/auth/AuthProvider';
import { getLastEmail } from '@/storage/secure';
import { useTheme } from '@/theme/ThemeProvider';
import { brand, radius, spacing } from '@/theme/tokens';

export default function LoginScreen() {
  const { state, signIn, retryStoreSecret, hasUnsavedSecret } = useAuth();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [secretPending, setSecretPending] = useState(false);
  const [inUse, setInUse] = useState(false);

  // Precargar el correo del último inicio de sesión (la contraseña nunca se guarda).
  useEffect(() => {
    let alive = true;
    void getLastEmail().then((v) => {
      if (alive && v) setEmail((cur) => cur || v);
    });
    return () => {
      alive = false;
    };
  }, []);
  const passwordRef = useRef<TextInputHandle>(null);

  const notice = state.status === 'signedOut' ? state.notice : null;

  const validate = () => {
    const errs: Record<string, string> = {};
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) errs.email = 'Escribe un correo válido.';
    if (password.length === 0) errs.password = 'Escribe tu contraseña.';
    setFieldErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const submit = async () => {
    if (busy || !validate()) return;
    setBusy(true);
    setError(null);
    try {
      await signIn(email, password);
    } catch (e) {
      if (e instanceof SecretStoreError) {
        setSecretPending(hasUnsavedSecret());
        setError(
          'Falta confirmar tu huella para poder marcar.',
        );
      } else if (e instanceof ApiRequestError && e.code === 'device_in_use') {
        setInUse(true);
      } else if (e instanceof ApiRequestError && e.code === 'validation_failed') {
        const errs: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.fieldErrors)) if (v[0]) errs[k] = v[0];
        setFieldErrors(errs);
        setError(e.message);
      } else {
        setError(describeError(e));
      }
    } finally {
      setBusy(false);
    }
  };

  const retrySecret = async () => {
    setBusy(true);
    try {
      await retryStoreSecret();
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  if (inUse) {
    return (
      <View style={[styles.blocked, { backgroundColor: colors.background, paddingTop: insets.top, paddingBottom: insets.bottom + spacing.xl }]}>
        <StatusBar style="light" />
        <View style={[styles.lock, { backgroundColor: colors.dangerBg }]}>
          <Icon name="lock" size={64} color={colors.danger} />
        </View>
        <AppText variant="title" style={{ textAlign: 'center' }}>
          Este teléfono pertenece a otro colaborador
        </AppText>
        <AppText variant="heading" color="textMuted" style={{ textAlign: 'center' }}>
          Acude con Sistemas o RH
        </AppText>
        <Button title="Volver" variant="secondary" size="lg" onPress={() => setInUse(false)} style={{ alignSelf: 'stretch', marginTop: spacing.xl }} />
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: colors.background }}>
      <StatusBar style="light" />
      <KeyboardAwareScrollView contentContainerStyle={{ flexGrow: 1 }} keyboardShouldPersistTaps="handled" bottomOffset={110}>
          <View style={[styles.hero, { paddingTop: insets.top + spacing.xxl, backgroundColor: brand.deep }]}>
            <View style={styles.heroRow}>
              <View style={{ flex: 1, gap: spacing.md }}>
                <Logo width={168} />
                <AppText variant="heading" style={{ color: '#FFFFFF' }}>
                  Asistencias
                </AppText>
              </View>
              <Mascot pose="saludo" height={150} />
            </View>
            <BrandGradient style={styles.band} />
          </View>

          <View style={[styles.form, { backgroundColor: colors.background }]}>
            <View style={{ gap: spacing.xs }}>
              <AppText variant="title" accessibilityRole="header">
                Iniciar sesión
              </AppText>
              <AppText variant="body" color="textMuted">
                Con el correo de tu empresa
              </AppText>
            </View>

            {notice ? <Banner tone="warning" title={notice} /> : null}
            {error ? (
              <Banner
                tone="danger"
                title={secretPending ? 'Falta un paso' : 'No se pudo entrar'}
                message={error}
                action={secretPending ? { label: 'Confirmar huella', onPress: retrySecret } : undefined}
              />
            ) : null}

            <TextField
              label="Correo electrónico"
              icon="mail"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoComplete="email"
              autoCorrect={false}
              keyboardType="email-address"
              textContentType="username"
              returnKeyType="next"
              onSubmitEditing={() => passwordRef.current?.focus()}
              error={fieldErrors.email}
              editable={!busy}
              placeholder="nombre@empresa.com"
            />
            <TextField
              ref={passwordRef}
              label="Contraseña"
              icon="lock"
              value={password}
              onChangeText={setPassword}
              secureToggle
              autoCapitalize="none"
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="go"
              onSubmitEditing={submit}
              error={fieldErrors.password}
              editable={!busy}
              placeholder="Tu contraseña"
            />

            <Button title="Entrar" onPress={submit} loading={busy} size="lg" style={{ marginTop: spacing.sm }} />

            {APP_VARIANT !== 'production' ? (
              <AppText variant="caption" color="textSubtle" style={{ textAlign: 'center' }}>
                Servidor: {API_URL}
              </AppText>
            ) : null}
            <View style={{ height: insets.bottom }} />
          </View>
      </KeyboardAwareScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  blocked: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.lg, paddingHorizontal: spacing.xxl },
  lock: { width: 128, height: 128, borderRadius: 64, alignItems: 'center', justifyContent: 'center', marginBottom: spacing.md },
  hero: { paddingHorizontal: spacing.xxl, paddingBottom: spacing.huge },
  heroRow: { flexDirection: 'row', alignItems: 'flex-end', gap: spacing.md },
  band: { position: 'absolute', left: 0, right: 0, bottom: spacing.xxl, height: 4 },
  form: {
    flex: 1,
    marginTop: -spacing.xxl,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    paddingHorizontal: spacing.xxl,
    paddingTop: spacing.xxl,
    paddingBottom: spacing.xxl,
    gap: spacing.lg,
  },
});
