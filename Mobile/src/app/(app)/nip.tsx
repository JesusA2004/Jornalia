import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { HelpButton } from '@/components/help';
import { Screen } from '@/components/ui/Screen';
import { Icon } from '@/components/ui/Icon';
import { PinInput } from '@/components/ui/PinInput';
import { AppText, Banner, Button } from '@/components/ui/primitives';
import { useAuth, useSignedIn } from '@/features/auth/AuthProvider';
import { PIN_LENGTH, PIN_PROBLEM_LABEL, validateNewPin } from '@/features/signature/pin';
import { setPinFlag, usePinFlag } from '@/features/signature/pinFlag';
import { api } from '@/services/container';
import { useTheme } from '@/theme/ThemeProvider';
import { spacing } from '@/theme/tokens';

type Step = 'current' | 'new' | 'confirm';

/** Alta o cambio del NIP de firma electrónica. */
export default function SignaturePinScreen() {
  const { profile } = useSignedIn();
  const { refreshProfile } = useAuth();
  const { colors } = useTheme();
  const localFlag = usePinFlag(profile.user.id);
  const [serverSaysPin, setServerSaysPin] = useState(false);
  const hasPin = profile.signature_pin_set === true || localFlag === true || serverSaysPin;
  const [chosenStep, setStep] = useState<Step | null>(null);
  const step: Step = chosenStep ?? (hasPin ? 'current' : 'new');
  const [current, setCurrent] = useState('');
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const next = () => {
    setError(null);
    if (step === 'current') {
      if (current.length !== PIN_LENGTH) return setError(PIN_PROBLEM_LABEL.length);
      setStep('new');
      return;
    }
    if (step === 'new') {
      const p = validateNewPin(pin);
      if (p) return setError(PIN_PROBLEM_LABEL[p]);
      setStep('confirm');
      return;
    }
    void save();
  };

  const save = async () => {
    const p = validateNewPin(pin, confirm);
    if (p) {
      setError(PIN_PROBLEM_LABEL[p]);
      if (p === 'mismatch') {
        setConfirm('');
      }
      return;
    }
    setBusy(true);
    try {
      await api.profile.setSignaturePin(hasPin ? { pin, current_pin: current } : { pin });
      await setPinFlag(profile.user.id, true);
      await refreshProfile();
      router.back();
    } catch (e) {
      if (e instanceof ApiRequestError && e.code === 'validation_failed' && e.fieldErrors.current_pin) {
        // Ya existía un NIP (p. ej. definido en otro teléfono): se pide el actual.
        setServerSaysPin(true);
        setStep('current');
        setCurrent('');
        setError(e.fieldErrors.current_pin[0] ?? 'NIP actual incorrecto.');
      } else {
        setError(describeError(e));
      }
      setPin('');
      setConfirm('');
      if (!(e instanceof ApiRequestError && e.fieldErrors.current_pin)) setStep(hasPin ? 'current' : 'new');
    } finally {
      setBusy(false);
    }
  };

  const value = step === 'current' ? current : step === 'new' ? pin : confirm;
  const setValue = step === 'current' ? setCurrent : step === 'new' ? setPin : setConfirm;
  const label = step === 'current' ? 'NIP actual' : step === 'new' ? 'NIP nuevo' : 'Repite el NIP';
  const stepIndex = (hasPin ? ['current', 'new', 'confirm'] : ['new', 'confirm']).indexOf(step);
  const totalSteps = hasPin ? 3 : 2;

  return (
    <Screen keyboard edges={['bottom']}>
      <Stack.Screen options={{ title: hasPin ? 'Cambiar NIP' : 'Crear NIP', headerRight: () => <HelpButton topic="nip" /> }} />
      <View style={{ alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.md }}>
        <View style={{ width: 72, height: 72, borderRadius: 36, backgroundColor: colors.goldSoft, alignItems: 'center', justifyContent: 'center' }}>
          <Icon name="lock" size={34} color={colors.goldStrong} />
        </View>
        <AppText variant="caption" color="textMuted">
          Paso {stepIndex + 1} de {totalSteps}
        </AppText>
      </View>
      <PinInput key={step} label={label} value={value} onChange={setValue} autoFocus error={error} />
      {step === 'new' ? <Banner tone="info" icon="signature" title="Con este NIP firmas tus documentos y correcciones." /> : null}
      <Button
        title={step === 'confirm' ? 'Guardar NIP' : 'Continuar'}
        icon={step === 'confirm' ? 'check' : 'arrowRight'}
        size="lg"
        loading={busy}
        disabled={value.length !== PIN_LENGTH}
        onPress={next}
      />
    </Screen>
  );
}
