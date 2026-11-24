<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/**
 * Parámetros validados de un reporte (fechas civiles en la zona de la organización).
 */
final readonly class ReportParameters
{
    public const PERIOD_UNITS = ['week', 'fortnight', 'month'];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public ?int $branchId = null,
        public ?int $departmentId = null,
        public ?int $employeeId = null,
        public ?string $status = null,
        public ?string $observations = null,
        public ?int $positionId = null,
        public ?string $region = null,
        public string $periodUnit = 'week',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $timezone, ?int $maxDays = null): self
    {
        $from = CarbonImmutable::parse((string) $data['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $data['to'])->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        // Límite defensivo: un reporte abarca como máximo poco más de un año
        // (o lo que fije la definición para reportes día por día).
        $limit = min(400, $maxDays ?? 400);
        if ($from->diffInDays($to) > $limit) {
            $from = $to->subDays($limit);
        }
        $unit = (string) ($data['period_unit'] ?? 'week');

        return new self(
            from: $from,
            to: $to,
            timezone: $timezone,
            branchId: ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
            departmentId: ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            employeeId: ! empty($data['employee_id']) ? (int) $data['employee_id'] : null,
            status: ! empty($data['status']) ? (string) $data['status'] : null,
            observations: ! empty($data['observations']) ? (string) $data['observations'] : null,
            positionId: ! empty($data['position_id']) ? (int) $data['position_id'] : null,
            region: ! empty($data['region']) ? (string) $data['region'] : null,
            periodUnit: in_array($unit, self::PERIOD_UNITS, true) ? $unit : 'week',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'branch_id' => $this->branchId,
            'department_id' => $this->departmentId,
            'position_id' => $this->positionId,
            'region' => $this->region,
            'employee_id' => $this->employeeId,
            'status' => $this->status,
            'period_unit' => $this->periodUnit !== 'week' ? $this->periodUnit : null,
            'observations' => $this->observations,
        ], fn ($v) => $v !== null);
    }

    /**
     * Inicio del periodo en UTC: medianoche del primer día en la zona del centro de trabajo.
     */
    public function fromUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from->toDateString(), $this->timezone)->startOfDay()->utc();
    }

    /**
     * Fin exclusivo del periodo en UTC: medianoche del día siguiente al último, en la zona del centro de trabajo.
     */
    public function toUtcExclusive(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to->toDateString(), $this->timezone)->addDay()->startOfDay()->utc();
    }

    /** Número de días civiles del periodo (inclusive). */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }
}
