<?php

namespace App\Domain\Biometrics;

/**
 * Resultado de una verificación 1:1 con prueba de vida.
 */
final readonly class VerificationOutcome
{
    public const RESULTS = ['match', 'no_match', 'liveness_failed', 'inconclusive', 'no_face', 'multiple_faces', 'poor_quality', 'error'];

    public function __construct(
        public string $result,
        public ?float $score = null,
        public ?float $livenessScore = null,
        public bool $challengePassed = false,
        public ?string $reason = null,
    ) {}

    public function isMatch(): bool
    {
        return $this->result === 'match';
    }

    /** Fallo atribuible al intento (cuenta para el bloqueo); `error` del motor no. */
    public function countsAsFailure(): bool
    {
        return $this->result !== 'match' && $this->result !== 'error';
    }
}
