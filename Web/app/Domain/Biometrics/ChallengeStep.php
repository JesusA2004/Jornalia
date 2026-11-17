<?php

namespace App\Domain\Biometrics;

/**
 * Pasos del reto de prueba de vida activa (verificados con landmarks).
 */
enum ChallengeStep: string
{
    case TurnLeft = 'turn_left';
    case TurnRight = 'turn_right';
    case MoveCloser = 'move_closer';
    case MoveAway = 'move_away';

    public function isTurn(): bool
    {
        return $this === self::TurnLeft || $this === self::TurnRight;
    }

    public function label(): string
    {
        return match ($this) {
            self::TurnLeft => 'Gira la cabeza a tu izquierda',
            self::TurnRight => 'Gira la cabeza a tu derecha',
            self::MoveCloser => 'Acerca el teléfono',
            self::MoveAway => 'Aleja el teléfono',
        };
    }
}
