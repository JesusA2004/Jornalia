import { router } from 'expo-router';
import { useState } from 'react';
import { Alert } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { DateTimeField, TextField } from '@/components/ui/fields';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card } from '@/components/ui/primitives';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { addDays, formatCivilDateLong, toCivilDate } from '@/utils/format';

export default function NewVacationScreen() {
  const balance = useQuery(() => api.vacations.balance(), [], { cacheKey: 'vacations.balance' });
  const tomorrow = addDays(toCivilDate(new Date()), 1);
  const [start, setStart] = useState(tomorrow);
  const [end, setEnd] = useState(tomorrow);
  const [comment, setComment] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (busy) return;
    if (end < start) {
      setErrors({ ends_on: 'La fecha final no puede ser anterior a la inicial.' });
      return;
    }
    setErrors({});
    setBusy(true);
    setServerError(null);
    try {
      const r = await api.vacations.create({ starts_on: start, ends_on: end, comment: comment.trim() || null });
      Alert.alert('Solicitud enviada', `Solicitaste ${r.days} ${r.days === 1 ? 'día' : 'días'}. Tu supervisor la revisará.`);
      router.back();
    } catch (e) {
      if (e instanceof ApiRequestError && e.code === 'validation_failed') {
        const fe: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.fieldErrors)) if (v[0]) fe[k] = v[0];
        setErrors(fe);
      }
      setServerError(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen keyboard edges={['bottom']}>
      {balance.data ? (
        <Card>
          <AppText variant="bodyStrong">
            Disponibles: {balance.data.available_days} {balance.data.available_days === 1 ? 'día' : 'días'}
          </AppText>
          <AppText variant="caption" color="textMuted">
            Los días hábiles se calculan en el servidor según tu horario, descansos y días festivos.
          </AppText>
        </Card>
      ) : null}
      {serverError ? <Banner tone="danger" title="No se pudo enviar" message={serverError} /> : null}
      <DateTimeField
        label="Primer día"
        value={new Date(`${start}T12:00:00`)}
        onChange={(d) => {
          const s = toCivilDate(d);
          setStart(s);
          if (end < s) setEnd(s);
        }}
        display={() => formatCivilDateLong(start)}
        minimumDate={new Date()}
        error={errors.starts_on}
      />
      <DateTimeField
        label="Último día"
        value={new Date(`${end}T12:00:00`)}
        onChange={(d) => setEnd(toCivilDate(d))}
        display={() => formatCivilDateLong(end)}
        minimumDate={new Date(`${start}T00:00:00`)}
        error={errors.ends_on}
      />
      <TextField label="Comentario (opcional)" value={comment} onChangeText={setComment} multiline maxLength={500} error={errors.comment} />
      <Button title="Enviar solicitud" onPress={() => void submit()} loading={busy} size="lg" />
    </Screen>
  );
}
