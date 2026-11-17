<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Biometrics\ChallengeStep;
use App\Domain\Biometrics\FaceVerificationProvider;
use App\Domain\Biometrics\FaceVerificationService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\FaceVerifyRequest;
use App\Http\Resources\V1\ApiFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificación facial 1:1 con prueba de vida (contrato: "Verificación facial"
 * en packages/contracts/src/api.ts).
 */
final class FaceVerificationController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly FaceVerificationProvider $provider,
        private readonly FaceVerificationService $faces,
    ) {}

    /**
     * GET /face-verification/status → FaceVerificationStatus.
     */
    public function status(Request $request): JsonResponse
    {
        $employee = $this->context->employeeOrNull($this->user($request));
        $available = $this->provider->available();

        return $this->data([
            'available' => $available,
            'provider' => $available ? $this->provider->name() : null,
            'enrolled' => $employee !== null && $this->faces->activeEnrollment($employee) !== null,
            'consent_required' => true,
        ]);
    }

    /**
     * POST /face-verification/challenge → FaceChallenge.
     */
    public function challenge(Request $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $challenge = $this->faces->issueChallenge($employee);

        return $this->data([
            'id' => $challenge->uuid,
            'steps' => array_map(fn (string $code) => ['code' => $code, 'label' => ChallengeStep::from($code)->label()], $challenge->steps),
            'expires_at' => ApiFormat::dateTime($challenge->expires_at),
        ], 201);
    }

    /**
     * POST /face-verification/verify (multipart) → FaceVerificationResult.
     */
    public function verify(FaceVerifyRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $verification = $this->faces->verify(
            $employee,
            (string) $request->input('challenge_id'),
            $request->jpegFrames(),
            $request->offsets(),
            $request->boolean('mirrored'),
        );

        return $this->data([
            'id' => $verification->uuid,
            'result' => $verification->result,
            'expires_at' => ApiFormat::dateTime($verification->expires_at),
        ]);
    }
}
