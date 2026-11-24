<?php

namespace App\Domain\Legal;

/**
 * Día de un cálculo semanal de tiempo extraordinario (minutos enteros).
 */
final readonly class WeeklyOvertimeDay
{
    public function __construct(
        public string $date,
        public int $workedMinutes,
        public int $ordinaryMinutes,
        public int $recordedMinutes,
        public int $doubleMinutes,
        public int $tripleMinutes,
        public int $overLimitMinutes,
        public int $authorizedMinutes,
        public int $justifiedMinutes,
        public int $pendingMinutes,
        public bool $minor,
        public ?string $status,
        public ?int $recordId,
    ) {}

    /**
     * @return array<string, int|string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'worked_minutes' => $this->workedMinutes,
            'ordinary_minutes' => $this->ordinaryMinutes,
            'recorded_minutes' => $this->recordedMinutes,
            'double_minutes' => $this->doubleMinutes,
            'triple_minutes' => $this->tripleMinutes,
            'over_limit_minutes' => $this->overLimitMinutes,
            'authorized_minutes' => $this->authorizedMinutes,
            'justified_minutes' => $this->justifiedMinutes,
            'pending_minutes' => $this->pendingMinutes,
            'minor' => $this->minor,
            'status' => $this->status,
            'record_id' => $this->recordId,
        ];
    }
}
