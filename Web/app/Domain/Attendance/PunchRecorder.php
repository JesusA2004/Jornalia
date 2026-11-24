<?php

namespace App\Domain\Attendance;

use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Signatures\PunchDocumentsGate;
use App\Domain\Signatures\RegistrationDocuments;
use App\Enums\AuthMethod;
use App\Enums\DeviceStatus;
use App\Enums\LocationMode;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Events\PunchRecorded;
use App\Models\AttendancePolicy;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmploymentAssignment;
use App\Models\FaceVerification;
use App\Models\Punch;
use App\Models\PunchChallenge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Único punto de escritura de marcajes. Valida dispositivo, firma, desafío,
 * política y secuencia; evalúa anomalías; y persiste el evento encadenado.
 *
 * Política de rechazo vs. marca:
 *  - En línea, las violaciones de política se RECHAZAN (el usuario lo sabe al instante
 *    y nada se pierde).
 *  - Sin conexión, el evento ya ocurrió: se ACEPTA y se marca `flagged` con anomalías
 *    para revisión. La evidencia nunca se descarta.
 *  - Una firma inválida siempre se rechaza (no hay prueba de origen).
 *  - Convenio de registro o aviso de privacidad pendientes: en línea se rechaza
 *    (`documents_pending`); sin conexión se acepta y se alerta a RH (PunchDocumentsGate).
 */
final class PunchRecorder
{
    /** Anomalías que obligan a revisión humana. */
    private const REVIEW_ANOMALIES = ['clock_skew', 'late_sync', 'mocked_location', 'outside_geofence', 'out_of_sequence', 'offline_not_allowed'];

    public function __construct(
        private readonly HashChain $chain,
        private readonly DeviceSignature $signature,
        private readonly EffectiveTimeline $timeline,
        private readonly PolicyResolver $policies,
        private readonly ScheduleResolver $schedules,
        private readonly ClockSkewMonitor $clockSkew,
        private readonly PunchDocumentsGate $documents,
    ) {}

    /**
     * @return array{punch: Punch, duplicate: bool}
     */
    public function record(User $user, Employee $employee, PunchInput $input, ?Device $device): array
    {
        $existing = Punch::query()
            ->where('employee_id', $employee->id)
            ->where('client_uuid', $input->clientUuid)
            ->first();
        if ($existing !== null) {
            return ['punch' => $existing, 'duplicate' => true];
        }

        $now = CarbonImmutable::now('UTC');
        // Relación laboral y asignación por FECHA CIVIL del centro de trabajo (regla 4), no
        // por la fecha UTC: a las 19:00 de Ciudad de México ya es «mañana» en UTC y la baja
        // de hoy o un movimiento de mañana se aplicarían antes de tiempo. En línea manda la
        // hora del servidor; la del dispositivo sólo cuenta en capturas sin conexión que no
        // sean posteriores a la recepción (no puede elegir otra asignación ni otra política).
        $civilTz = $this->schedules->timezoneFor($employee);
        $today = $now->setTimezone($civilTz)->toDateString();
        $declaredDay = $input->capturedOffline && $input->deviceTime !== null && $input->deviceTime->lte($now)
            ? $input->deviceTime->setTimezone($civilTz)->toDateString()
            : null;
        $employment = $employee->employmentOn($declaredDay ?? $today) ?? $employee->employmentOn($today);
        if ($employment === null) {
            throw AttendanceException::noEmployment();
        }
        $assignment = $employment->assignmentOn($declaredDay ?? $today);
        $policy = $this->policies->for($employee, $assignment);

        $this->assertAuthentication($user, $employee, $input, $device, $policy);

        // Convenio de registro y aviso de privacidad vigentes (PunchDocumentsGate). En
        // línea se rechaza; sin conexión, el marcaje capturado antes de llegar al servidor
        // es evidencia de la jornada trabajada: se registra y se alerta a RH.
        $documents = $this->documents->check($employee);
        $blocking = $documents['pending'];
        if ($input->kioskId !== null) {
            // El kiosco de sucursal no tiene dónde mostrar ni aceptar el aviso de privacidad
            // (es para personal sin teléfono): ahí sólo bloquea el convenio, que puede
            // firmarse en papel. Si falta el aviso, el marcaje se registra y RH recibe la
            // alerta `punch_without_agreement` para regularizarlo (decisión pendiente de RH
            // sobre la aceptación del aviso en papel).
            $blocking = array_values(array_diff($blocking, [RegistrationDocuments::PRIVACY]));
        }
        if ($blocking !== [] && ! $this->capturedBeforeReception($input, $now, $policy)) {
            throw AttendanceException::documentsPending($blocking);
        }

        $anomalies = [];
        $eventTime = $now;
        if ($input->capturedOffline) {
            if (! $policy->allow_offline) {
                $anomalies[] = 'offline_not_allowed';
            }
            if ($input->deviceTime !== null && $input->deviceTime->lte($now->addSeconds((int) $policy->max_clock_skew_seconds))) {
                $eventTime = $input->deviceTime->min($now);
                if ($input->deviceTime->diffInHours($now) > (int) $policy->offline_max_hours) {
                    $anomalies[] = 'late_sync';
                }
            } else {
                $anomalies[] = 'clock_skew';
            }
        } elseif ($input->deviceTime !== null && abs($now->diffInSeconds($input->deviceTime)) > (int) $policy->max_clock_skew_seconds) {
            $anomalies[] = 'clock_skew';
        }

        [$geo, $locationAnomalies] = $this->evaluateLocation($input, $policy, $assignment);
        $anomalies = array_merge($anomalies, $locationAnomalies);

        $expected = $this->timeline->nextExpected($employee->id, $eventTime);
        if (! $this->timeline->isAllowed($input->type, $expected)) {
            if (! $input->capturedOffline) {
                throw AttendanceException::invalidSequence($expected->label());
            }
            $anomalies[] = 'out_of_sequence';
        }

        $tz = $this->schedules->timezoneFor($employee, $assignment);
        $localDate = $eventTime->setTimezone($tz)->toDateString();
        $anomalies = array_merge($anomalies, $this->scheduleAnomalies($employee, $localDate, $eventTime, $assignment));

        $anomalies = array_values(array_unique($anomalies));
        $status = array_intersect($anomalies, self::REVIEW_ANOMALIES) !== [] ? PunchStatus::Flagged : PunchStatus::Accepted;

        try {
            $punch = $this->chain->transaction(function () use ($employee, $employment, $device, $user, $input, $now, $tz, $localDate, $anomalies, $status, $geo, $policy) {
                $link = $this->chain->next($employee->organization_id, Punch::class);
                $faceVerificationId = $this->consumeFaceVerification($employee, $input, $policy, $now);

                $punch = new Punch([
                    'uuid' => (string) Str::uuid7(),
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'employment_id' => $employment->id,
                    'device_id' => $device?->id,
                    'kiosk_id' => $input->kioskId,
                    'recorded_by' => $user->id,
                    'client_uuid' => $input->clientUuid,
                    'type' => $input->type,
                    // El kiosco conserva su origen aun sin conexión (captured_offline lo distingue).
                    'source' => $input->capturedOffline && $input->source !== PunchSource::Kiosk ? PunchSource::MobileOffline : $input->source,
                    'server_time' => $now,
                    'device_time' => $input->deviceTime?->utc(),
                    // Hora oficial = recepción en servidor; se guardan también la del
                    // dispositivo y la de sincronización (sólo al INSERT: tabla inmutable).
                    'received_at' => $now,
                    'synced_at' => $input->syncedAt?->utc() ?? ($input->capturedOffline ? null : $input->deviceTime?->utc()),
                    'clock_skew_seconds' => $input->deviceTime ? (int) $input->deviceTime->diffInSeconds($now, false) : null,
                    'timezone' => $input->timezone !== '' ? $input->timezone : $tz,
                    'local_date' => $localDate,
                    'auth_method' => $input->authMethod,
                    'signature_valid' => $device !== null,
                    'face_verification_id' => $faceVerificationId,
                    'captured_offline' => $input->capturedOffline,
                    'status' => $status,
                    'anomalies' => $anomalies,
                    'latitude' => $input->latitude,
                    'longitude' => $input->longitude,
                    'accuracy_meters' => $input->accuracyMeters,
                    'location_captured_at' => $input->locationCapturedAt?->utc(),
                    'location_mocked' => $input->locationMocked,
                    'geofence_id' => $geo['geofence_id'],
                    'distance_meters' => $geo['distance'],
                    'ip_address' => $input->ipAddress,
                    'user_agent' => $input->userAgent !== null ? Str::limit($input->userAgent, 250, '') : null,
                    'sequence' => $link['sequence'],
                    'previous_hash' => $link['previous_hash'],
                    'created_at' => $now,
                ]);
                $punch->hash = $this->chain->hash($link['previous_hash'], HashChain::punchPayload($punch));
                $punch->save();

                return $punch;
            });
        } catch (UniqueConstraintViolationException) {
            // Carrera entre dos envíos del mismo client_uuid: idempotente.
            $punch = Punch::query()->where('employee_id', $employee->id)->where('client_uuid', $input->clientUuid)->firstOrFail();

            return ['punch' => $punch, 'duplicate' => true];
        }

        $device?->forceFill(['last_seen_at' => $now])->saveQuietly();

        // Diferencia > 10 min entre hora del dispositivo y del servidor ⇒ alerta en Cumplimiento.
        $this->clockSkew->evaluate($punch);

        try {
            $this->documents->afterRecorded($punch, $documents);
        } catch (Throwable $e) {
            // El marcaje ya quedó registrado: una falla de la alerta no debe perderlo.
            report($e);
        }

        PunchRecorded::dispatch($punch);

        return ['punch' => $punch, 'duplicate' => false];
    }

    /**
     * Marcaje sin conexión cuya hora del dispositivo es anterior a la recepción en el
     * servidor (con la tolerancia de reloj de la política): el hecho ya ocurrió.
     */
    private function capturedBeforeReception(PunchInput $input, CarbonImmutable $now, AttendancePolicy $policy): bool
    {
        return $input->capturedOffline
            && $input->deviceTime !== null
            && $input->deviceTime->lte($now->addSeconds((int) $policy->max_clock_skew_seconds));
    }

    private function assertAuthentication(User $user, Employee $employee, PunchInput $input, ?Device $device, AttendancePolicy $policy): void
    {
        $allowed = (array) $policy->allowed_auth_methods;

        if ($input->authMethod === AuthMethod::KioskPin) {
            // Kiosco de sucursal: KioskService ya autenticó el kiosco (token) y al colaborador
            // (número de empleado + NIP). Aquí sólo se aplica la verificación facial si la política la exige.
            if ($input->source !== PunchSource::Kiosk || $input->kioskId === null) {
                throw AttendanceException::policyViolation('Método de autenticación no permitido fuera de un kiosco.');
            }
            $this->assertFaceVerification($employee, $input, $policy);

            return;
        }

        if ($input->authMethod === AuthMethod::WebSession) {
            if (! $policy->web_punch_allowed) {
                throw AttendanceException::policyViolation('Tu política de asistencia no permite marcar desde el sitio web.');
            }

            return;
        }

        if (! in_array($input->authMethod->value, $allowed, true)) {
            throw AttendanceException::policyViolation('Método de autenticación no permitido por tu política de asistencia.');
        }

        if ($device === null || $device->user_id !== $user->id) {
            throw AttendanceException::deviceUnknown();
        }
        match ($device->status) {
            DeviceStatus::Pending => throw AttendanceException::devicePending(),
            DeviceStatus::Revoked => throw AttendanceException::deviceRevoked(),
            DeviceStatus::Approved => null,
        };

        if ($policy->location_mode === LocationMode::DeviceOnly && ! $device->is_corporate) {
            throw AttendanceException::policyViolation('Tu política exige marcar desde un dispositivo corporativo.');
        }

        $canonical = DeviceSignature::canonical(
            $input->clientUuid,
            $input->type->value,
            (string) $input->deviceTimeRaw,
            $input->nonce,
            (string) $input->deviceUuid,
        );
        if ($input->signature === null || ! $this->signature->verify($device, $canonical, $input->signature)) {
            throw AttendanceException::invalidSignature();
        }

        if (! $input->capturedOffline) {
            $this->consumeChallenge($device, (string) $input->nonce);
        }

        $this->assertFaceVerification($employee, $input, $policy);
    }

    private function assertFaceVerification(Employee $employee, PunchInput $input, AttendancePolicy $policy): void
    {
        if ($policy->face_verification_required) {
            $ok = $input->faceVerificationId !== null && FaceVerification::query()
                ->where('uuid', $input->faceVerificationId)
                ->where('employee_id', $employee->id)
                ->where('result', 'match')
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->exists();
            if (! $ok) {
                throw AttendanceException::policyViolation('Se requiere una verificación facial vigente para marcar.');
            }
        }
    }

    /**
     * Consume la verificación facial dentro de la transacción del marcaje (un solo uso real).
     * Sólo se consume la del propio colaborador, coincidente y vigente. Si la política la
     * exige y ya no está disponible (p. ej. dos marcajes concurrentes con la misma
     * verificación), el marcaje se rechaza y la transacción se revierte.
     */
    private function consumeFaceVerification(Employee $employee, PunchInput $input, AttendancePolicy $policy, CarbonImmutable $now): ?string
    {
        if ($input->faceVerificationId === null) {
            return null;
        }

        $consumed = FaceVerification::query()
            ->where('uuid', $input->faceVerificationId)
            ->where('employee_id', $employee->id)
            ->where('result', 'match')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now]);

        if ($consumed === 0 && $policy->face_verification_required) {
            throw AttendanceException::policyViolation('Se requiere una verificación facial vigente para marcar.');
        }

        return $consumed > 0 ? $input->faceVerificationId : null;
    }

    private function consumeChallenge(Device $device, string $nonce): void
    {
        DB::transaction(function () use ($device, $nonce) {
            $challenge = PunchChallenge::query()
                ->where('device_id', $device->id)
                ->where('nonce', $nonce)
                ->lockForUpdate()
                ->first();

            if ($challenge === null || $challenge->expires_at->isPast()) {
                throw AttendanceException::challengeExpired();
            }
            if ($challenge->used_at !== null) {
                throw AttendanceException::challengeUsed();
            }
            $challenge->forceFill(['used_at' => now()])->save();
        });
    }

    /**
     * @return array{0: array{geofence_id: int|null, distance: int|null}, 1: list<string>}
     */
    private function evaluateLocation(PunchInput $input, AttendancePolicy $policy, ?EmploymentAssignment $assignment): array
    {
        $geo = ['geofence_id' => null, 'distance' => null];
        $anomalies = [];
        $mode = $policy->location_mode;

        if (! $input->hasLocation()) {
            // Kiosco: equipo fijo en la sucursal autorizado por RH; su ubicación es la de la sucursal.
            if ($input->source === PunchSource::Kiosk) {
                return [$geo, []];
            }
            if (in_array($mode, [LocationMode::Required, LocationMode::Geofence], true)) {
                if (! $input->capturedOffline) {
                    throw AttendanceException::locationRequired();
                }
                $anomalies[] = 'outside_geofence';
            }
            if ($mode !== LocationMode::Free) {
                $anomalies[] = 'no_location';
            }

            return [$geo, $anomalies];
        }

        if ($input->locationMocked === true) {
            $anomalies[] = 'mocked_location';
        }
        if ($input->accuracyMeters !== null && $input->accuracyMeters > (int) $policy->min_location_accuracy_meters) {
            $anomalies[] = 'low_accuracy';
        }

        $fences = $this->policies->geofences($policy, $assignment);
        $nearest = null;
        foreach ($fences as $fence) {
            $d = PolicyResolver::distanceMeters((float) $input->latitude, (float) $input->longitude, (float) $fence->latitude, (float) $fence->longitude);
            if ($nearest === null || $d - $fence->radius_meters < $nearest['distance'] - $nearest['radius']) {
                $nearest = ['id' => $fence->id, 'distance' => $d, 'radius' => (int) $fence->radius_meters];
            }
        }
        if ($nearest !== null) {
            $geo = ['geofence_id' => $nearest['id'], 'distance' => $nearest['distance']];
        }

        if ($mode === LocationMode::Geofence) {
            $inside = $nearest !== null && $nearest['distance'] <= $nearest['radius'] + min(100, (int) ($input->accuracyMeters ?? 0));
            if (! $inside) {
                if (! $input->capturedOffline) {
                    throw AttendanceException::outsideGeofence();
                }
                $anomalies[] = 'outside_geofence';
            }
        }

        return [$geo, $anomalies];
    }

    /**
     * @return list<string>
     */
    private function scheduleAnomalies(Employee $employee, string $localDate, CarbonImmutable $time, ?EmploymentAssignment $assignment): array
    {
        $anomalies = [];
        if ($this->schedules->holidayOn($employee, $localDate, $assignment) !== null) {
            $anomalies[] = 'holiday';
        }
        $shift = $this->schedules->shiftFor($employee, $localDate, $assignment);
        if ($shift === null) {
            if ($assignment?->schedule_id !== null) {
                $anomalies[] = 'rest_day';
            }
        } elseif ($time->lt($shift->startsAt->subHours(3)) || $time->gt($shift->endsAt->addHours(6))) {
            $anomalies[] = 'outside_schedule';
        }

        return $anomalies;
    }
}
