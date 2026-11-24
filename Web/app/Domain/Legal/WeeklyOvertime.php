<?php

namespace App\Domain\Legal;

/**
 * Resultado semanal de tiempo extraordinario de un colaborador (lunes a domingo).
 * Lo consumen Panel, Horas extra y la exportación a nómina (NOI).
 */
final readonly class WeeklyOvertime
{
    /**
     * @param  list<WeeklyOvertimeDay>  $days
     */
    public function __construct(
        public int $employeeId,
        public string $weekStart,
        public string $weekEnd,
        public string $legalVersionCode,
        public bool $legalVerified,
        public string $legalLabel,
        public ?bool $minor,
        public array $days,
    ) {}

    /**
     * Suma semanal de un campo en minutos enteros (p. ej. `doubleMinutes`).
     */
    public function total(string $field): int
    {
        return array_sum(array_map(fn (WeeklyOvertimeDay $d) => (int) $d->{$field}, $this->days));
    }

    /**
     * Días con tiempo extra registrado.
     */
    public function daysWithOvertime(): int
    {
        return count(array_filter($this->days, fn (WeeklyOvertimeDay $d) => $d->recordedMinutes > 0));
    }

    /**
     * @return array{worked_minutes:int, ordinary_minutes:int, recorded_minutes:int, double_minutes:int, triple_minutes:int, over_limit_minutes:int, authorized_minutes:int, justified_minutes:int, pending_minutes:int, overtime_days:int}
     */
    public function totals(): array
    {
        return [
            'worked_minutes' => $this->total('workedMinutes'),
            'ordinary_minutes' => $this->total('ordinaryMinutes'),
            'recorded_minutes' => $this->total('recordedMinutes'),
            'double_minutes' => $this->total('doubleMinutes'),
            'triple_minutes' => $this->total('tripleMinutes'),
            'over_limit_minutes' => $this->total('overLimitMinutes'),
            'authorized_minutes' => $this->total('authorizedMinutes'),
            'justified_minutes' => $this->total('justifiedMinutes'),
            'pending_minutes' => $this->total('pendingMinutes'),
            'overtime_days' => $this->daysWithOvertime(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'week_start' => $this->weekStart,
            'week_end' => $this->weekEnd,
            'legal_version_code' => $this->legalVersionCode,
            'legal_verified' => $this->legalVerified,
            'legal_label' => $this->legalLabel,
            'minor' => $this->minor,
            'days' => array_map(fn (WeeklyOvertimeDay $d) => $d->toArray(), $this->days),
            'totals' => $this->totals(),
        ];
    }
}
