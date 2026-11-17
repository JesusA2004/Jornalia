<?php

namespace App\Jobs;

use App\Domain\Attendance\WorkdayCalculator;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcula la proyección diaria de un colaborador. Idempotente y único por
 * colaborador/fecha: reintentos o duplicados producen el mismo resultado.
 */
final class RecomputeWorkday implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(
        public readonly int $organizationId,
        public readonly int $employeeId,
        public readonly string $date,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->employeeId}:{$this->date}";
    }

    public function handle(WorkdayCalculator $calculator, CurrentOrganization $current): void
    {
        $organization = Organization::query()->findOrFail($this->organizationId);

        $current->runAs($organization, function () use ($calculator) {
            $employee = Employee::query()->findOrFail($this->employeeId);
            $calculator->compute($employee, $this->date);
        });
    }
}
