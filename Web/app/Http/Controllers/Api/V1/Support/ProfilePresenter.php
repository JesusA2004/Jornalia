<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Domain\Scheduling\ScheduleResolver;
use App\Http\Resources\V1\AcknowledgementDocumentResource;
use App\Http\Resources\V1\ApiFormat;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Contrato `Profile`. No expone identificadores oficiales (CURP, RFC, NSS).
 */
final class ProfilePresenter
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user, Organization $organization): array
    {
        $employee = $this->context->employeeOrNull($user);

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'timezone' => (string) $organization->timezone],
            'employee' => $employee ? $this->employee($employee) : null,
            'pending_acknowledgements' => $employee ? $this->pendingAcknowledgements($employee) : [],
            // NIP de firma electrónica configurado (A2).
            'signature_pin_set' => filled($user->signature_pin_hash),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employee(Employee $employee): array
    {
        $today = CarbonImmutable::today();
        $employment = $employee->employmentOn($today) ?? $employee->employments()->orderByDesc('starts_on')->first();
        $assignment = $employment?->assignmentOn($today) ?? $employment?->assignments()->orderByDesc('effective_from')->first();
        $assignment?->loadMissing(['position', 'department', 'branch.workCenter', 'workCenter', 'supervisor']);

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->fullName(),
            'first_name' => $employee->first_name,
            'position' => $assignment?->position?->name,
            'department' => $assignment?->department?->name,
            'branch' => $assignment?->branch?->name,
            'work_center' => $assignment?->workCenter->name ?? $assignment?->branch?->workCenter?->name,
            'hire_date' => ApiFormat::date($employment?->starts_on),
            'supervisor' => $assignment?->supervisor?->fullName(),
            'timezone' => $this->schedules->timezoneFor($employee, $assignment),
        ];
    }

    /**
     * Documentos publicados que requieren acuse y que el colaborador no ha aceptado.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingAcknowledgements(Employee $employee): array
    {
        $documents = PolicyDocument::query()
            ->where('requires_acknowledgement', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('published_at')
            ->get();

        return array_values(AcknowledgementDocumentResource::collection($documents)->resolve());
    }
}
