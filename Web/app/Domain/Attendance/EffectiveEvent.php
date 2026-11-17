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
    public function __construct(
        public PunchType $type,
        public CarbonImmutable $time,
        public string $origin,
        public ?Punch $punch,
        public ?AttendanceAdjustment $adjustment,
    ) {}

    public function isRectified(): bool
    {
        return $this->origin !== 'original';
    }
}
