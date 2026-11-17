<?php

namespace App\Domain\Biometrics;

use App\Models\FaceChallenge;

/**
 * Motor de verificación facial 1:1 con prueba de vida (pasiva + reto activo).
 *
 * Implementación actual: `OpenSourceFaceProvider` (microservicio interno con
 * YuNet + SFace + MiniFASNet, ver docs/seguridad/BIOMETRIA.md). Si no está
 * configurado o no responde, `available()` es false y la modalidad no se ofrece.
 *
 * Las imágenes viajan en memoria; el proveedor nunca devuelve ni persiste
 * imágenes. La plantilla es un dato biométrico sensible (se guarda cifrada).
 */
interface FaceVerificationProvider
{
    public function available(): bool;

    /** Identificador estable del motor (p. ej. `opencv-open-source`). */
    public function name(): ?string;

    /** Versión de la plantilla que produce `enroll()` (para re-enrolar si cambia el modelo). */
    public function templateVersion(): ?string;

    /**
     * Pasos de reto activo que el motor sabe verificar.
     *
     * @return list<ChallengeStep>
     */
    public function challengeSteps(): array;

    /**
     * Genera la plantilla a partir de varios cuadros JPEG (bytes crudos) de la
     * persona cuya identidad validó RH en persona.
     *
     * @param  list<string>  $jpegFrames
     *
     * @throws FaceServiceException si el motor no responde
     */
    public function enroll(array $jpegFrames): EnrollmentOutcome;

    /**
     * Compara la secuencia contra la plantilla y evalúa la prueba de vida.
     *
     * @param  list<string>  $jpegFrames
     * @param  list<int>  $offsetsMs  ms desde la emisión del reto, crecientes
     *
     * @throws FaceServiceException si el motor no responde
     */
    public function verify(string $template, array $jpegFrames, array $offsetsMs, FaceChallenge $challenge, bool $mirrored): VerificationOutcome;
}
