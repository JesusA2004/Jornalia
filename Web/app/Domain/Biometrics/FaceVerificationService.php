<?php

namespace App\Domain\Biometrics;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Audit\AuditLogger;
use App\Enums\AlertSeverity;
use App\Models\ComplianceAlert;
use App\Models\Employee;
use App\Models\FaceChallenge;
use App\Models\FaceEnrollment;
use App\Models\FaceVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verificación facial del colaborador desde la app: reto aleatorio de un solo
 * uso, verificación 1:1 con prueba de vida y bloqueo por intentos fallidos.
 * El id resultante (`face_verifications.uuid`) lo exige PunchRecorder si la
 * política lo requiere.
 */
final class FaceVerificationService
{
    public function __construct(
        private readonly FaceVerificationProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function activeEnrollment(Employee $employee): ?FaceEnrollment
    {
        return FaceEnrollment::query()->where('employee_id', $employee->id)->active()->latest('id')->first();
    }

    public function issueChallenge(Employee $employee): FaceChallenge
    {
        $this->ensureReady($employee);

        $steps = $this->provider->challengeSteps();
        $count = min((int) config('biometrics.challenge.steps', 2), count($steps));
        $chosen = [];
        // Siempre al menos un giro: acercar/alejar una foto impresa es posible;
        // un giro real cambia la geometría nariz/ojos y una foto plana no.
        $turns = array_values(array_filter($steps, fn (ChallengeStep $s) => $s->isTurn()));
        if ($turns !== [] && $count > 0) {
            $chosen[] = $turns[random_int(0, count($turns) - 1)];
        }
        while (count($chosen) < $count) {
            $step = $steps[random_int(0, count($steps) - 1)];
            if (! in_array($step, $chosen, true)) {
                $chosen[] = $step;
            }
        }
        if (random_int(0, 1) === 1) {
            $chosen = array_reverse($chosen);
        }

        return FaceChallenge::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'steps' => array_map(fn (ChallengeStep $s) => $s->value, $chosen),
            'expires_at' => now()->addSeconds((int) config('biometrics.challenge.ttl_seconds', 30)),
        ]);
    }

    /**
     * @param  list<string>  $jpegFrames
     * @param  list<int>  $offsetsMs
     */
    public function verify(Employee $employee, string $challengeUuid, array $jpegFrames, array $offsetsMs, bool $mirrored): FaceVerification
    {
        $enrollment = $this->ensureReady($employee);

        // Un solo uso: se consume ANTES de llamar al motor, pase lo que pase.
        $challenge = DB::transaction(function () use ($employee, $challengeUuid) {
            $challenge = FaceChallenge::query()
                ->where('uuid', $challengeUuid)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();
            if ($challenge === null || $challenge->used_at !== null) {
                throw new AttendanceException('face_challenge_invalid', 'El reto no es válido o ya se usó. Solicita uno nuevo.', 422);
            }
            if ($challenge->expires_at->isPast()) {
                throw new AttendanceException('face_challenge_expired', 'El reto expiró. Solicita uno nuevo.', 422);
            }
            $challenge->forceFill(['used_at' => now()])->save();

            return $challenge;
        });

        $ttlMs = (int) config('biometrics.challenge.ttl_seconds', 30) * 1000;
        foreach ($offsetsMs as $i => $offset) {
            if ($offset < 0 || $offset > $ttlMs || ($i > 0 && $offset <= $offsetsMs[$i - 1])) {
                return $this->record($employee, $challenge, new VerificationOutcome('liveness_failed', reason: 'invalid_timestamps'));
            }
        }

        try {
            $outcome = $this->provider->verify((string) $enrollment->template, $jpegFrames, $offsetsMs, $challenge, $mirrored);
        } catch (FaceServiceException) {
            $this->record($employee, $challenge, new VerificationOutcome('error', reason: 'service_unavailable'));

            throw new AttendanceException('face_unavailable', 'La verificación facial no está disponible en este momento.', 503);
        }

        return $this->record($employee, $challenge, $outcome);
    }

    public function recentFailures(Employee $employee): int
    {
        return FaceVerification::query()
            ->where('employee_id', $employee->id)
            ->where('created_at', '>=', now()->subMinutes((int) config('biometrics.lockout.window_minutes', 15)))
            ->whereNotIn('result', ['match', 'error'])
            ->count();
    }

    public function isLocked(Employee $employee): bool
    {
        return $this->recentFailures($employee) >= (int) config('biometrics.lockout.max_failures', 5);
    }

    private function ensureReady(Employee $employee): FaceEnrollment
    {
        if (! $this->provider->available()) {
            throw new AttendanceException('face_unavailable', 'La verificación facial no está disponible.', 503);
        }
        $enrollment = $this->activeEnrollment($employee);
        if ($enrollment === null) {
            throw new AttendanceException('face_not_enrolled', 'No tienes un rostro registrado. Acude con Recursos Humanos.', 409);
        }
        if ($this->isLocked($employee)) {
            throw new AttendanceException('face_locked', 'Demasiados intentos fallidos. Intenta más tarde o usa otro método.', 429);
        }

        return $enrollment;
    }

    private function record(Employee $employee, FaceChallenge $challenge, VerificationOutcome $outcome): FaceVerification
    {
        $verification = FaceVerification::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'face_challenge_id' => $challenge->id,
            'provider' => (string) $this->provider->name(),
            'result' => $outcome->result,
            // Sin imágenes ni plantillas: sólo métricas.
            'provider_metadata' => [
                'score' => $outcome->score,
                'liveness_score' => $outcome->livenessScore,
                'challenge_passed' => $outcome->challengePassed,
                'reason' => $outcome->reason,
                'steps' => $challenge->steps,
            ],
            'expires_at' => now()->addSeconds((int) config('biometrics.verification.ttl_seconds', 120)),
        ]);

        if ($outcome->countsAsFailure() && $this->recentFailures($employee) === (int) config('biometrics.lockout.max_failures', 5)) {
            $this->raiseLockoutAlert($employee);
        }

        return $verification;
    }

    private function raiseLockoutAlert(Employee $employee): void
    {
        $timezone = $employee->organization?->timezone ?? config('app.timezone');
        $date = now()->setTimezone($timezone)->toDateString();
        $minutes = (int) config('biometrics.lockout.window_minutes', 15);

        $exists = ComplianceAlert::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('rule_code', 'face_verification_lockout')
            ->exists();
        if (! $exists) {
            ComplianceAlert::query()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'date' => $date,
                'rule_code' => 'face_verification_lockout',
                'severity' => AlertSeverity::Warning,
                'message' => "Verificación facial bloqueada por intentos fallidos ({$minutes} min).",
                'data' => ['failures' => $this->recentFailures($employee)],
                'status' => 'open',
            ]);
        }

        $this->audit->log('biometrics.face.locked', $employee, 'Verificación facial bloqueada por intentos fallidos.', [
            'failures' => $this->recentFailures($employee),
            'window_minutes' => $minutes,
        ], $employee->organization_id);
    }
}
