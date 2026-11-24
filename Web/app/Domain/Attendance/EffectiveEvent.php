<?php

namespace App\Domain\Attendance;

use App\Enums\PunchType;
use App\Models\AttendanceAdjustment;
use App\Models\Punch;
use Carbon\CarbonImmutable;

/**
 * Evento reconocido para cálculo: el marcaje original o el efecto de una
 * rectificación aprobada. El original nunca se pierde: `punch` lo conserva.
 */
final readonly class EffectiveEvent
{
    /** Marcaje del colaborador reconocido tal cual. */
    public const ORIGINAL = 'original';

    /** Marcaje original reemplazado por una rectificación firmada y validada. */
    public const RECTIFIED = 'rectified';

    /** Evento faltante agregado por una rectificación firmada y validada. */
    public const ADDED = 'added';

    /** @var array<string, string> */
    public const ORIGIN_LABELS = [
        self::ORIGINAL => 'Marcaje original',
        self::RECTIFIED => 'Rectificado',
        self::ADDED => 'Agregado por rectificación',
    ];

    public function __construct(
        public PunchType $type,
        public CarbonImmutable $time,
        public string $origin,
        public ?Punch $punch,
        public ?AttendanceAdjustment $adjustment,
    ) {}

    public function isRectified(): bool
    {
        return $this->origin !== self::ORIGINAL;
    }

    public function originLabel(): string
    {
        return self::ORIGIN_LABELS[$this->origin] ?? $this->origin;
    }
}
