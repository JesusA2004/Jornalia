<?php

namespace App\Domain\Biometrics;

use App\Domain\Audit\AuditLogger;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\PolicyDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrolamiento facial por RH tras validar identidad en persona, con
 * consentimiento expreso por escrito (dato personal sensible, LFPDPPP).
 *
 * Nada se borra físicamente:
 * - Revocar = status `revoked` (la plantilla permanece cifrada; deja de usarse).
 * - Derecho de cancelación (ARCO) = única excepción: la plantilla se vacía
 *   (template = null) y queda constancia auditada. El registro se conserva.
 */
final class FaceEnrollmentService
{
    public function __construct(
        private readonly FaceVerificationProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function consentDocument(): ?PolicyDocument
    {
        return PolicyDocument::query()
            ->where('code', (string) config('biometrics.enrollment.consent_document_code', 'BIOMETRIA'))
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->first();
    }

    /**
     * @param  list<string>  $jpegFrames
     */
    public function enroll(User $actor, Employee $employee, array $jpegFrames): EnrollmentOutcome|FaceEnrollment
    {
        if (! $this->provider->available()) {
            throw new FaceServiceException('El motor facial no está disponible.');
        }

        $outcome = $this->provider->enroll($jpegFrames);
        if (! $outcome->ok || $outcome->template === null) {
            return $outcome;
        }

        $document = $this->consentDocument();

        return DB::transaction(function () use ($actor, $employee, $outcome, $document) {
            FaceEnrollment::query()
                ->where('employee_id', $employee->id)
                ->where('status', FaceEnrollment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->each(fn (FaceEnrollment $old) => $this->markRevoked($old, $actor, 'Sustituido por un nuevo registro.'));

            $enrollment = FaceEnrollment::query()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'provider' => (string) $this->provider->name(),
                'external_subject_id' => (string) Str::uuid(),
                'template' => $outcome->template,
                'template_version' => $this->provider->templateVersion(),
                'quality' => $outcome->quality,
                'consent_at' => now(),
                'consent_document_id' => $document?->id,
                'consent_document_sha256' => $document?->sha256,
                'identity_verified_by' => $actor->id,
                'status' => FaceEnrollment::STATUS_ACTIVE,
            ]);

            $this->audit->log('biometrics.face.enrolled', $employee, 'Registro facial con consentimiento expreso.', [
                'enrollment_id' => $enrollment->id,
                'provider' => $enrollment->provider,
                'consent_document_id' => $document?->id,
                'consent_document_sha256' => $document?->sha256,
            ], $employee->organization_id);

            return $enrollment;
        });
    }

    public function revoke(User $actor, FaceEnrollment $enrollment, string $reason): void
    {
        DB::transaction(function () use ($actor, $enrollment, $reason) {
            $this->markRevoked($enrollment, $actor, $reason);
            $this->audit->log('biometrics.face.revoked', $enrollment->employee, 'Registro facial revocado.', [
                'enrollment_id' => $enrollment->id,
                'reason' => $reason,
            ], $enrollment->organization_id);
        });
    }

    /**
     * Derecho de cancelación del titular: excepción legal a "nada se borra".
     * Se vacía la plantilla biométrica; el registro y su bitácora se conservan.
     */
    public function erase(User $actor, FaceEnrollment $enrollment, string $reason): void
    {
        DB::transaction(function () use ($actor, $enrollment, $reason) {
            if ($enrollment->status !== FaceEnrollment::STATUS_REVOKED) {
                $this->markRevoked($enrollment, $actor, $reason);
            }
            $enrollment->forceFill(['template' => null, 'template_erased_at' => now()])->save();

            $this->audit->log('biometrics.face.template_erased', $enrollment->employee, 'Plantilla facial cancelada (derecho ARCO).', [
                'enrollment_id' => $enrollment->id,
                'reason' => $reason,
                'legal_basis' => 'LFPDPPP: derecho de cancelación de datos personales sensibles',
            ], $enrollment->organization_id);
        });
    }

    private function markRevoked(FaceEnrollment $enrollment, User $actor, string $reason): void
    {
        $enrollment->forceFill([
            'status' => FaceEnrollment::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revocation_reason' => Str::limit($reason, 250, ''),
        ])->save();
    }
}
