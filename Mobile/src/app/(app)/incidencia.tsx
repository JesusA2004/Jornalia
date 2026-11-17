import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, View } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { EvidencePicker } from '@/components/requests';
import { ChoiceChips, DateTimeField, TextField } from '@/components/ui/fields';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, ErrorState, LoadingState } from '@/components/ui/primitives';
import { evidenceStore, useEvidence } from '@/features/requests/evidenceStore';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { spacing } from '@/theme/tokens';
import { formatCivilDateLong, toCivilDate } from '@/utils/format';

export default function NewIncidenceScreen() {
  const types = useQuery(() => api.incidences.types(), [], { cacheKey: 'incidence-types' });
  const requestable = (types.data ?? []).filter((t) => t.employee_requestable);
  const [typeId, setTypeId] = useState<number | null>(null);
  const [start, setStart] = useState<string>(toCivilDate(new Date()));
  const [end, setEnd] = useState<string>(toCivilDate(new Date()));
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const evidence = useEvidence();
  const selected = requestable.find((t) => t.id === typeId) ?? null;

  useEffect(() => {
    evidenceStore.set(null);
    return () => evidenceStore.set(null);
  }, []);

  const validate = () => {
    const e: Record<string, string> = {};
    if (!typeId) e.incidence_type_id = 'Selecciona el tipo de incidencia.';
    if (end < start) e.ends_on = 'La fecha final no puede ser anterior a la inicial.';
    if (reason.trim().length < 5) e.reason = 'Describe el motivo.';
    if (selected?.requires_evidence && !evidence) e.evidence = 'Este tipo de incidencia requiere evidencia.';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const submit = async () => {
    if (busy || !validate() || !typeId) return;
    setBusy(true);
    setServerError(null);
    try {
      await api.incidences.create({ incidence_type_id: typeId, starts_on: start, ends_on: end, reason: reason.trim() }, evidence);
      evidenceStore.set(null);
      Alert.alert('Incidencia registrada', selected?.requires_approval ? 'Quedó en revisión de tu supervisor.' : 'Se registró correctamente.');
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

  if (types.loading) return <LoadingState label="Cargando tipos de incidencia…" />;
  if (!types.data) return <ErrorState message={types.error ?? 'Sin datos'} onRetry={() => void types.refresh()} />;

  return (
    <Screen keyboard edges={['bottom']}>
      {serverError ? <Banner tone="danger" title="No se pudo enviar" message={serverError} /> : null}
      <View style={{ gap: spacing.xs }}>
        <AppText variant="captionStrong" color="textMuted">
          Tipo de incidencia
        </AppText>
        {requestable.length ? (
          <ChoiceChips options={requestable.map((t) => ({ value: t.id, label: t.name }))} value={typeId} onChange={setTypeId} />
        ) : (
          <AppText variant="caption" color="textMuted">
            Tu empresa no tiene tipos de incidencia que puedas solicitar desde la app.
          </AppText>
        )}
        {errors.incidence_type_id ? (
          <AppText variant="caption" color="danger">
            {errors.incidence_type_id}
          </AppText>
        ) : null}
        {selected ? (
          <AppText variant="caption" color="textMuted">
            {selected.requires_approval ? 'Requiere aprobación.' : 'No requiere aprobación.'}
            {selected.requires_evidence ? ' Requiere evidencia.' : ''}
          </AppText>
        ) : null}
      </View>
      <DateTimeField
        label="Desde"
        value={new Date(`${start}T12:00:00`)}
        onChange={(d) => {
          const s = toCivilDate(d);
          setStart(s);
          if (end < s) setEnd(s);
        }}
        display={() => formatCivilDateLong(start)}
        error={errors.starts_on}
      />
      <DateTimeField
        label="Hasta"
        value={new Date(`${end}T12:00:00`)}
        onChange={(d) => setEnd(toCivilDate(d))}
        display={() => formatCivilDateLong(end)}
        minimumDate={new Date(`${start}T00:00:00`)}
        error={errors.ends_on}
      />
      <TextField label="Motivo" value={reason} onChangeText={setReason} multiline maxLength={1000} error={errors.reason} placeholder="Describe brevemente la incidencia" />
      <EvidencePicker required={selected?.requires_evidence} />
      {errors.evidence ? (
        <AppText variant="caption" color="danger">
          {errors.evidence}
        </AppText>
      ) : null}
      <Button title="Enviar incidencia" onPress={() => void submit()} loading={busy} size="lg" disabled={!requestable.length} />
    </Screen>
  );
}
