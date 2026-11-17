<?php

namespace App\Domain\Biometrics;

use App\Models\FaceChallenge;

/**
 * Motor no configurado: la verificación facial NO está disponible. No simula resultados.
 */
final class UnavailableFaceProvider implements FaceVerificationProvider
{
    public function available(): bool
    {
        return false;
    }

    public function name(): ?string
    {
        return null;
    }

    public function templateVersion(): ?string
    {
        return null;
    }

    public function challengeSteps(): array
    {
        return [];
    }

    public function enroll(array $jpegFrames): EnrollmentOutcome
    {
        throw new FaceServiceException('Verificación facial no configurada.');
    }

    public function verify(string $template, array $jpegFrames, array $offsetsMs, FaceChallenge $challenge, bool $mirrored): VerificationOutcome
    {
        throw new FaceServiceException('Verificación facial no configurada.');
    }
}
