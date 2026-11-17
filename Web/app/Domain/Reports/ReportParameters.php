<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/**
 * Parámetros validados de un reporte (fechas civiles en la zona de la organización).
 */
final readonly class ReportParameters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public ?int $branchId = null,
        public ?int $departmentId = null,
        public ?int $employeeId = null,
        public ?string $status = null,
        public ?string $observations = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $timezone): self
    {
        $from = CarbonImmutable::parse((string) $data['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $data['to'])->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        // Límite defensivo: un reporte abarca como máximo poco más de un año.
        if ($from->diffInDays($to) > 400) {
            $from = $to->subDays(400);
        }

        return new self(
            from: $from,
            to: $to,
            timezone: $timezone,
            branchId: ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
            departmentId: ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            employeeId: ! empty($data['employee_id']) ? (int) $data['employee_id'] : null,
            status: ! empty($data['status']) ? (string) $data['status'] : null,
            observations: ! empty($data['observations']) ? (string) $data['observations'] : null,
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
            'employee_id' => $this->employeeId,
            'status' => $this->status,
            'observations' => $this->observations,
        ], fn ($v) => $v !== null);
    }

    public function fromUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from->toDateString(), $this->timezone)->startOfDay()->utc();
    }

    public function toUtcExclusive(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to->toDateString(), $this->timezone)->addDay()->startOfDay()->utc();
    }
}
