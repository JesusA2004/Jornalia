<?php

namespace App\Http\Controllers\Web\Biometrics;

use App\Domain\Biometrics\EnrollmentOutcome;
use App\Domain\Biometrics\FaceEnrollmentService;
use App\Domain\Biometrics\FaceServiceException;
use App\Domain\Biometrics\FaceVerificationProvider;
use App\Http\Controllers\Web\Admin\AdminController;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registro facial del colaborador por RH (identidad validada en persona y
 * consentimiento expreso). Sin borrado: revocación y cancelación ARCO.
 */
class FaceEnrollmentController extends AdminController
{
    public function __construct(
        private readonly FaceEnrollmentService $enrollments,
        private readonly FaceVerificationProvider $provider,
    ) {}

    public function show(Employee $employee): Response
    {
        $this->authorize('update', $employee);
        $document = $this->enrollments->consentDocument();

        $history = FaceEnrollment::query()
            ->where('employee_id', $employee->id)
            ->with(['identityVerifiedBy:id,name', 'revokedBy:id,name', 'consentDocument:id,title,version'])
            ->latest('id')
            ->get()
            ->map(fn (FaceEnrollment $e) => [
                'id' => $e->id,
                'status' => $e->status,
                'active' => $e->status === FaceEnrollment::STATUS_ACTIVE && $e->template_erased_at === null,
                'provider' => $e->provider,
                'template_version' => $e->template_version,
                'consent_at' => $e->consent_at?->toIso8601String(),
                'consent_document' => $e->consentDocument ? "{$e->consentDocument->title} v{$e->consentDocument->version}" : null,
                'consent_document_sha256' => $e->consent_document_sha256,
                'identity_verified_by' => $e->identityVerifiedBy?->name,
                'revoked_at' => $e->revoked_at?->toIso8601String(),
                'revoked_by' => $e->revokedBy?->name,
                'revocation_reason' => $e->revocation_reason,
                'template_erased_at' => $e->template_erased_at?->toIso8601String(),
                'liveness_score' => $e->quality['liveness_score'] ?? null,
            ])
            ->values();

        return Inertia::render('biometria/Rostro', [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->fullName(),
                'employee_number' => $employee->employee_number,
            ],
            'service' => [
                'available' => $this->provider->available(),
                'provider' => $this->provider->name(),
            ],
            'consentDocument' => $document ? [
                'id' => $document->id,
                'title' => $document->title,
                'version' => $document->version,
                'sha256' => $document->sha256,
            ] : null,
            'enrollments' => $history,
            'limits' => [
                'min_frames' => (int) config('biometrics.enrollment.min_frames', 3),
                'max_frames' => (int) config('biometrics.enrollment.max_frames', 10),
            ],
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);
        $min = (int) config('biometrics.enrollment.min_frames', 3);
        $max = (int) config('biometrics.enrollment.max_frames', 10);

        $request->validate([
            'frames' => ['required', 'array', "min:{$min}", "max:{$max}"],
            'frames.*' => ['required', 'file', 'mimetypes:image/jpeg', 'max:1500'],
            'identity_verified' => ['accepted'],
            'consent' => ['accepted'],
        ], [
            'identity_verified.accepted' => 'Confirma que validaste la identidad en persona.',
            'consent.accepted' => 'Se requiere el consentimiento expreso por escrito.',
        ], ['frames' => 'cuadros']);

        $frames = [];
        foreach ((array) $request->file('frames', []) as $file) {
            if ($file instanceof UploadedFile) {
                $frames[] = (string) $file->getContent();
                $path = $file->getRealPath();
                if (is_string($path) && is_file($path)) {
                    @unlink($path); // nunca se conserva la imagen
                }
            }
        }

        try {
            $result = $this->enrollments->enroll($this->actor($request), $employee, $frames);
        } catch (FaceServiceException) {
            throw ValidationException::withMessages(['frames' => 'El motor facial no está disponible. Intenta más tarde.']);
        } finally {
            unset($frames);
        }

        if ($result instanceof EnrollmentOutcome) {
            throw ValidationException::withMessages(['frames' => $result->message()]);
        }

        $this->toast('Rostro registrado.');

        return back();
    }

    public function revoke(Request $request, Employee $employee, FaceEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeEnrollment($employee, $enrollment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:250']], [], ['reason' => 'motivo']);

        if ($enrollment->status !== FaceEnrollment::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['reason' => 'El registro ya está revocado.']);
        }
        $this->enrollments->revoke($this->actor($request), $enrollment, $data['reason']);
        $this->toast('Registro facial revocado.');

        return back();
    }

    public function erase(Request $request, Employee $employee, FaceEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeEnrollment($employee, $enrollment);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:250'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirma la cancelación.'], ['reason' => 'folio o motivo de la solicitud']);

        if ($enrollment->template_erased_at !== null) {
            throw ValidationException::withMessages(['reason' => 'La plantilla ya fue cancelada.']);
        }
        $this->enrollments->erase($this->actor($request), $enrollment, $data['reason']);
        $this->toast('Plantilla facial cancelada.');

        return back();
    }

    private function authorizeEnrollment(Employee $employee, FaceEnrollment $enrollment): void
    {
        $this->authorize('update', $employee);
        $this->ensureOwned($enrollment);
        abort_unless($enrollment->employee_id === $employee->id, 404);
    }
}
