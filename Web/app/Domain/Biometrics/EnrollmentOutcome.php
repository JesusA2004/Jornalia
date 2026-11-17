<?php

namespace App\Domain\Biometrics;

/**
 * Resultado de enrolamiento. `template` sólo existe si `ok`.
 */
final readonly class EnrollmentOutcome
{
    /**
     * @param  'enrolled'|'no_face'|'multiple_faces'|'poor_quality'|'liveness_failed'|'inconsistent'|'invalid_image'  $result
     * @param  array<string, mixed>  $quality
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $ok,
        public string $result,
        public ?string $template = null,
        public array $quality = [],
        public array $reasons = [],
    ) {}

    public function message(): string
    {
        return match ($this->result) {
            'enrolled' => 'Rostro registrado.',
            'no_face' => 'No se detectó un rostro en todos los cuadros.',
            'multiple_faces' => 'Debe aparecer una sola persona.',
            'poor_quality' => 'Calidad insuficiente: '.implode(', ', array_map(self::reasonLabel(...), $this->reasons ?: ['general'])).'.',
            'liveness_failed' => 'No se superó la prueba de vida. Repite frente a la persona, sin pantallas ni fotos.',
            'inconsistent' => 'Los cuadros no parecen de la misma persona.',
            default => 'Imagen no válida.',
        };
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'face_too_small' => 'acércate a la cámara',
            'too_dark' => 'falta luz',
            'too_bright' => 'demasiada luz',
            'blurry' => 'imagen borrosa',
            'not_frontal' => 'mira de frente',
            default => 'repite la captura',
        };
    }
}
