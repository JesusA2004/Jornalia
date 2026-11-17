<?php

namespace App\Domain\Scheduling;

use App\Enums\ShiftKind;
use Carbon\CarbonImmutable;

/**
 * Turno programado concreto para una fecha civil. Horas en UTC.
 */
final readonly class ScheduledShift
{
    public function __construct(
        public string $date,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ShiftKind $kind,
        public int $breakMinutes,
        public int $toleranceMinutes,
        public ?int $scheduleId,
        public string $scheduleName,
        public bool $fromException = false,
    ) {}

    public function spanMinutes(): int
    {
        return (int) $this->startsAt->diffInMinutes($this->endsAt);
    }

    /** Minutos de trabajo programado (lapso menos descanso programado). */
    public function scheduledMinutes(): int
    {
        return max(0, $this->spanMinutes() - $this->breakMinutes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'starts_at' => $this->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $this->endsAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'kind' => $this->kind->value,
            'break_minutes' => $this->breakMinutes,
            'tolerance_minutes' => $this->toleranceMinutes,
            'schedule_name' => $this->scheduleName,
        ];
    }
}
