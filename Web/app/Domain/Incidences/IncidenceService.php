<?php

namespace App\Domain\Incidences;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Audit\AuditLogger;
use App\Enums\ApprovalStatus;
use App\Jobs\RecomputeWorkday;
use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro y resolución de incidencias. Las incidencias no alteran marcajes:
 * sólo influyen en el estado de la proyección diaria (p. ej. permiso o incapacidad).
 */
final class IncidenceService
{
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly ApprovalWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{incidence_type_id:int, starts_on:string, ends_on:string, reason:?string, minutes?:?int}  $data
     * @param  bool  $onBehalf  registrada por un responsable (permiso incidences.create)
     */
    public function submit(User $requester, Employee $employee, array $data, ?UploadedFile $evidence = null, bool $onBehalf = false): Incidence
    {
        $type = IncidenceType::query()->where('active', true)->findOrFail($data['incidence_type_id']);
        if (! $onBehalf && ! $type->employee_requestable) {
            throw ValidationException::withMessages(['incidence_type_id' => 'Este tipo de incidencia no puede solicitarse por el colaborador.']);
        }

        $from = CarbonImmutable::parse($data['starts_on']);
        $to = CarbonImmutable::parse($data['ends_on']);
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha final debe ser posterior o igual a la inicial.']);
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_on' => 'El periodo excede un año.']);
        }
        if (($type->valid_from && $from->lt($type->valid_from)) || ($type->valid_to && $to->gt($type->valid_to))) {
            throw ValidationException::withMessages(['incidence_type_id' => 'El tipo de incidencia no está vigente en esas fechas.']);
        }
        if ($type->requires_evidence && $evidence === null) {
            throw ValidationException::withMessages(['evidence' => 'Este tipo de incidencia requiere adjuntar evidencia.']);
        }

        $overlap = Incidence::query()->where('employee_id', $employee->id)
            ->where('incidence_type_id', $type->id)
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Ya existe una incidencia de este tipo en esas fechas.']);
        }

        return DB::transaction(function () use ($requester, $employee, $type, $from, $to, $data, $evidence, $onBehalf) {
            $incidence = Incidence::query()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'incidence_type_id' => $type->id,
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'minutes' => $data['minutes'] ?? null,
                'reason' => $data['reason'] ?? null,
                'source' => $onBehalf ? 'manager' : 'employee',
                'status' => $type->requires_approval ? ApprovalStatus::Pending : ApprovalStatus::Approved,
                'requested_by' => $requester->id,
            ]);

            if ($evidence !== null) {
                $this->attach($incidence, $evidence, $requester);
            }

            $this->audit->log('incidence.submitted', $incidence, "Incidencia registrada: {$type->name}");

            if (! $type->requires_approval) {
                $this->recompute($incidence);
            }

            return $incidence;
        });
    }

    public function decide(User $reviewer, Incidence $incidence, bool $approve, ?string $comment): Incidence
    {
        $final = $this->workflow->decide($reviewer, $incidence, 'incidence', $approve, $comment);
        $incidence->refresh();
        if ($final) {
            if ($incidence->status === ApprovalStatus::Approved) {
                $this->recompute($incidence);
            }
            $incidence->employee?->user?->notify(new RequestStatusChanged('incidence', $incidence->id, $incidence->status, $incidence->review_comment));
        }

        return $incidence;
    }

    public function cancel(User $user, Incidence $incidence): Incidence
    {
        if ($incidence->status !== ApprovalStatus::Pending || $incidence->requested_by !== $user->id) {
            throw ValidationException::withMessages(['status' => 'Sólo puedes cancelar tus incidencias pendientes.']);
        }
        $incidence->update(['status' => ApprovalStatus::Cancelled]);
        $this->audit->log('incidence.cancelled', $incidence, 'Incidencia cancelada por el solicitante');

        return $incidence;
    }

    public function attach(Incidence $incidence, UploadedFile $file, User $user): Attachment
    {
        $path = (string) $file->store("evidence/{$incidence->organization_id}/incidences", 'local');

        return Attachment::query()->create([
            'organization_id' => $incidence->organization_id,
            'attachable_type' => $incidence->getMorphClass(),
            'attachable_id' => $incidence->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'uploaded_by' => $user->id,
        ]);
    }

    private function recompute(Incidence $incidence): void
    {
        foreach (CarbonPeriod::create($incidence->starts_on, $incidence->ends_on) as $day) {
            RecomputeWorkday::dispatch($incidence->organization_id, $incidence->employee_id, $day->toDateString())->afterCommit();
        }
    }
}
