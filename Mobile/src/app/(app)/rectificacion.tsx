import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, View } from 'react-native';

import { ApiRequestError, describeError } from '@/api/errors';
import { EvidencePicker } from '@/components/requests';
import { ChoiceChips, DateTimeField, TextField } from '@/components/ui/fields';
import { Screen } from '@/components/ui/Screen';
import { AppText, Banner, Button, Card, LoadingState } from '@/components/ui/primitives';
import { useSignedIn } from '@/features/auth/AuthProvider';
import { evidenceStore, useEvidence } from '@/features/requests/evidenceStore';
import { useQuery } from '@/hooks/useQuery';
import { api } from '@/services/container';
import { spacing } from '@/theme/tokens';
import type { PunchType, Rectification } from '@/types/contracts';
import { civilDateInZone, formatCivilDateLong, formatTime, PUNCH_TYPE_LABEL, toCivilDate } from '@/utils/format';
import { wallTimeToUtcIso } from '@/utils/time';

const KINDS: { value: Rectification['kind']; label: string }[] = [
  { value: 'missing_punch', label: 'Olvidé marcar' },
  { value: 'wrong_type', label: 'Marqué otro tipo' },
  { value: 'time_clarification', label: 'Hora incorrecta' },
  { value: 'other', label: 'Otro' },
];
const TYPES = (Object.keys(PUNCH_TYPE_LABEL) as PunchType[]).map((value) => ({ value, label: PUNCH_TYPE_LABEL[value] }));

export default function NewRectificationScreen() {
  const params = useLocalSearchParams<{ date?: string }>();
  const { profile } = useSignedIn();
  const tz = profile.employee?.timezone ?? profile.organization.timezone;
  const todayCivil = civilDateInZone(new Date(), tz);
  const [date, setDate] = useState<string>(params.date && /^\d{4}-\d{2}-\d{2}$/.test(params.date) ? params.date : todayCivil);
  const [kind, setKind] = useState<Rectification['kind']>('missing_punch');
  const [punchUuid, setPunchUuid] = useState<string | null>(null);
  const [type, setType] = useState<PunchType | null>(null);
  const [time, setTime] = useState<{ h: number; m: number } | null>(null);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const evidence = useEvidence();

  useEffect(() => {
    evidenceStore.set(null);
    return () => evidenceStore.set(null);
  }, []);

  const day = useQuery(() => api.attendance.history({ from: date, to: date, page: 1 }).then((r) => r.data[0] ?? null), [date]);
  const punches = day.data?.punches ?? [];
  const needsPunch = kind === 'wrong_type' || kind === 'time_clarification';
  const needsType = kind === 'missing_punch' || kind === 'wrong_type';
  const needsTime = kind === 'missing_punch' || kind === 'time_clarification';

  const validate = () => {
    const e: Record<string, string> = {};
    if (needsPunch && !punchUuid) e.punch = 'Selecciona el marcaje que quieres aclarar.';
    if (needsType && !type) e.type = 'Selecciona el tipo de marcaje.';
    if (needsTime && !time) e.time = 'Indica la hora correcta.';
    if (reason.trim().length < 10) e.reason = 'Describe el motivo (mínimo 10 caracteres).';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const submit = async () => {
    if (busy || !validate()) return;
    setBusy(true);
    setServerError(null);
    try {
      await api.rectifications.create(
        {
          date,
          punch_uuid: needsPunch ? punchUuid : null,
          kind,
          proposed_type: needsType ? type : null,
          proposed_time: needsTime && time ? wallTimeToUtcIso(date, time.h, time.m, tz) : null,
          reason: reason.trim(),
        },
        evidence,
      );
      evidenceStore.set(null);
      Alert.alert('Enviada', 'Tu jefe la revisará.');
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
      <Banner tone="info" icon="lock" title="Tu marcaje original no cambia" />
      {serverError ? <Banner tone="danger" title="No se pudo enviar" message={serverError} /> : null}

      <DateTimeField
        label="Fecha de la jornada"
        value={new Date(`${date}T12:00:00`)}
        onChange={(d) => {
          setDate(toCivilDate(d));
          setPunchUuid(null);
        }}
        display={() => formatCivilDateLong(date)}
        maximumDate={new Date()}
        error={errors.date}
      />

      <View style={{ gap: spacing.xs }}>
        <AppText variant="captionStrong" color="textMuted">
          ¿Qué pasó?
        </AppText>
        <ChoiceChips options={KINDS} value={kind} onChange={setKind} />
      </View>

      {needsPunch ? (
        <View style={{ gap: spacing.xs }}>
          <AppText variant="captionStrong" color="textMuted">
            ¿Cuál marcaje?
          </AppText>
          {day.loading ? (
            <LoadingState label="Buscando marcajes del día…" />
          ) : punches.length === 0 ? (
            <Card>
              <AppText variant="caption" color="textMuted">
                {day.error ?? 'Ese día no tiene marcajes.'}
              </AppText>
            </Card>
          ) : (
            <ChoiceChips
              options={punches.map((p) => ({ value: p.uuid, label: `${PUNCH_TYPE_LABEL[p.type]} ${formatTime(p.server_time, tz)}` }))}
              value={punchUuid}
              onChange={setPunchUuid}
            />
          )}
          {errors.punch ? (
            <AppText variant="caption" color="danger">
              {errors.punch}
            </AppText>
          ) : null}
        </View>
      ) : null}

      {needsType ? (
        <View style={{ gap: spacing.xs }}>
          <AppText variant="captionStrong" color="textMuted">
            {kind === 'wrong_type' ? 'Tipo correcto' : '¿Cuál olvidaste?'}
          </AppText>
          <ChoiceChips options={TYPES} value={type} onChange={setType} />
          {errors.type || errors.proposed_type ? (
            <AppText variant="caption" color="danger">
              {errors.type ?? errors.proposed_type}
            </AppText>
          ) : null}
        </View>
      ) : null}

      {needsTime ? (
        <DateTimeField
          label="Hora correcta"
          mode="time"
          value={time ? new Date(2000, 0, 1, time.h, time.m) : null}
          onChange={(d) => setTime({ h: d.getHours(), m: d.getMinutes() })}
          display={() => (time ? `${String(time.h).padStart(2, '0')}:${String(time.m).padStart(2, '0')}` : '')}
          placeholder="Seleccionar hora"
          error={errors.time ?? errors.proposed_time}
        />
      ) : null}

      <TextField
        label="Motivo"
        value={reason}
        onChangeText={setReason}
        multiline
        maxLength={1000}
        placeholder="Cuéntanos brevemente"
        error={errors.reason}
        hint={`${reason.trim().length}/1000`}
      />

      <EvidencePicker />

      <Button title="Enviar rectificación" onPress={() => void submit()} loading={busy} size="lg" />
    </Screen>
  );
}
