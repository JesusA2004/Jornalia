<?php

namespace App\Domain\Incidences;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Attendance\RectificationService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Security\AccessScope;
use App\Enums\ApprovalStatus;
use App\Enums\RectificationKind;
use App\Jobs\RecomputeWorkday;
use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro y resolución de incidencias (PDF pantalla 06). Las incidencias no
 * alteran marcajes: sólo influyen en la proyección diaria (permiso, incapacidad…).
 *
 *  - Catálogo cerrado (`IncidenceCatalog`); la falta injustificada no se captura.
 *  - Evidencia por tipo (certificado IMSS + folio, formato autorizado, acta, constancia).
 *  - Autorización por tipo: jefe inmediato y/o RH.
 *  - La omisión de checada genera una RECTIFICACIÓN para firma del colaborador;
 *    nunca un marcaje.
 *  - No se borran: sólo se cancelan con motivo (bitácora).
 */
final class IncidenceService
{
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly ApprovalWorkflow $workflow,
        private readonly AuditLogger $audit,
        private readonly AccessScope $scope,
        private readonly RectificationService $rectifications,
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * @param  array{incidence_type_id:int, starts_on:string, ends_on:string, reason:?string, minutes?:?int, folio?:?string, proposed_time?:?string}  $data
     * @param  UploadedFile|list<UploadedFile>|null  $evidence
     * @param  bool  $onBehalf  registrada por un responsable (permiso incidences.create)
     */
    public function submit(User $requester, Employee $employee, array $data, UploadedFile|array|null $evidence = null, bool $onBehalf = false): Incidence
    {
        $files = array_values(array_filter(is_array($evidence) ? $evidence : [$evidence], fn ($f) => $f instanceof UploadedFile));

        if ($onBehalf && ! ($requester->can('incidences.create') && $this->scope->canAccessEmployee($requester, $employee))) {
            throw new AuthorizationException('No puedes registrar incidencias para este colaborador.');
        }
        if (! $onBehalf && $requester->id !== $employee->user_id) {
            throw new AuthorizationException('Sólo puedes registrar tus propias incidencias.');
        }

        $type = IncidenceType::query()->where('active', true)->whereIn('code', IncidenceCatalog::codes())->find($data['incidence_type_id'])
            ?? throw ValidationException::withMessages(['incidence_type_id' => 'Selecciona un tipo de incidencia del catálogo.']);
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
        if ($type->requires_evidence && $files === []) {
            $label = IncidenceCatalog::EVIDENCE_KINDS[$type->evidence_kind ?? ''] ?? 'la evidencia';
            throw ValidationException::withMessages(['evidence' => "Adjunta {$label}."]);
        }
        $folio = isset($data['folio']) ? strtoupper(trim((string) $data['folio'])) : null;
        if ($type->requires_folio && ($folio === null || preg_match('/^[A-Z0-9\-]{4,40}$/', $folio) !== 1)) {
            throw ValidationException::withMessages(['folio' => 'Indica el folio IMSS de la incapacidad.']);
        }

        if ($type->isOmission()) {
            return $this->submitOmission($requester, $employee, $type, $from, $to, $data, $files, $onBehalf);
        }

        $overlap = Incidence::query()->where('employee_id', $employee->id)
            ->where('incidence_type_id', $type->id)
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Ya existe una incidencia de este tipo en esas fechas.']);
        }

        return DB::transaction(function () use ($requester, $employee, $type, $from, $to, $data, $files, $onBehalf, $folio) {
            $incidence = Incidence::query()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'incidence_type_id' => $type->id,
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'minutes' => $data['minutes'] ?? null,
                'reason' => $data['reason'] ?? null,
                'folio' => $folio,
                'source' => $onBehalf ? 'manager' : 'employee',
                'status' => $type->requires_approval ? ApprovalStatus::Pending : ApprovalStatus::Approved,
                'requested_by' => $requester->id,
            ]);

            foreach ($files as $file) {
                $this->attach($incidence, $file, $requester);
            }

            $this->audit->log('incidence.submitted', $incidence, "Incidencia registrada: {$type->name}", ['folio' => $folio]);

            if (! $type->requires_approval) {
                $this->recompute($incidence);
            }

            return $incidence;
        });
    }

    /**
     * Omisión de checada: queda la incidencia y se abre una rectificación
     * (marcaje omitido) que sigue el flujo completo con firma del colaborador.
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    private function submitOmission(User $requester, Employee $employee, IncidenceType $type, CarbonImmutable $from, CarbonImmutable $to, array $data, array $files, bool $onBehalf): Incidence
    {
        if (! $from->isSameDay($to)) {
            throw ValidationException::withMessages(['ends_on' => 'La omisión de checada es de un solo día.']);
        }
        $time = trim((string) ($data['proposed_time'] ?? ''));
        if ($time === '') {
            throw ValidationException::withMessages(['proposed_time' => 'Indica la hora real de la '.($type->omission_punch_type === 'clock_in' ? 'entrada' : 'salida').'.']);
        }
        $proposed = preg_match('/^\d{2}:\d{2}$/', $time) === 1
            ? CarbonImmutable::parse($from->toDateString().' '.$time, $this->schedules->timezoneFor($employee))->utc()
            : CarbonImmutable::parse($time)->utc();

        return DB::transaction(function () use ($requester, $employee, $type, $from, $data, $files, $onBehalf, $proposed) {
            $incidence = Incidence::query()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'incidence_type_id' => $type->id,
                'starts_on' => $from->toDateString(),
                'ends_on' => $from->toDateString(),
                'reason' => $data['reason'] ?? null,
                'source' => $onBehalf ? 'manager' : 'employee',
                'status' => ApprovalStatus::Pending,
                'requested_by' => $requester->id,
            ]);
            foreach ($files as $file) {
                $this->attach($incidence, $file, $requester);
            }

            $rectification = $this->rectifications->submit($requester, $employee, [
                'date' => $from->toDateString(),
                'punch_uuid' => null,
                'kind' => RectificationKind::MissingPunch->value,
                'proposed_type' => (string) $type->omission_punch_type,
                'proposed_time' => $proposed->toIso8601String(),
                'reason' => trim(($type->name).'. '.($data['reason'] ?? '')),
            ], $files, 'incidence', $incidence->id);

            $incidence->forceFill(['rectification_request_id' => $rectification->id])->save();
            $this->audit->log('incidence.submitted', $incidence, "Omisión de checada registrada; rectificación #{$rectification->id} para firma");

            return $incidence;
        });
    }

    public function canDecide(User $user, Incidence $incidence): bool
    {
        $type = $incidence->type;

        return $type !== null && ! $type->isOmission()
            && (int) $incidence->requested_by !== $user->id
            && $this->workflow->canDecide($user, $incidence, 'incidence', $type->approvalLevels());
    }

    public function decide(User $reviewer, Incidence $incidence, bool $approve, ?string $comment): Incidence
    {
        $type = $incidence->type;
        if ($type?->isOmission()) {
            throw ValidationException::withMessages(['decision' => 'La omisión de checada se resuelve en Rectificaciones con la firma del colaborador.']);
        }
        if ((int) $incidence->requested_by === $reviewer->id) {
            throw new AuthorizationException('Nadie autoriza su propia solicitud.');
        }

        $final = $this->workflow->decide($reviewer, $incidence, 'incidence', $approve, $comment, null, $type?->approvalLevels());
        $incidence->refresh();
        if ($final) {
            if ($incidence->status === ApprovalStatus::Approved) {
                $this->recompute($incidence);
            }
            $incidence->employee?->user?->notify(new RequestStatusChanged('incidence', $incidence->id, $incidence->status, $incidence->review_comment));
        }

        return $incidence;
    }

    public function canCancel(User $user, Incidence $incidence): bool
    {
        if (! in_array($incidence->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true) || $incidence->rectification_request_id !== null) {
            return false;
        }
        if ($incidence->status === ApprovalStatus::Pending && $incidence->requested_by === $user->id) {
            return true;
        }

        return $user->can('incidences.cancel') && $this->scope->canAccessEmployee($user, $incidence->employee_id);
    }

    /**
     * Cancela con motivo (no se borra). Si estaba aprobada, se recalculan los días.
     */
    public function cancel(User $user, Incidence $incidence, ?string $reason = null): Incidence
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la cancelación.']);
        }
        if ($incidence->rectification_request_id !== null) {
            throw ValidationException::withMessages(['reason' => 'Esta omisión se resuelve en Rectificaciones.']);
        }

        $wasApproved = DB::transaction(function () use ($user, $incidence, $reason) {
            /** @var Incidence $locked */
            $locked = Incidence::query()->whereKey($incidence->id)->lockForUpdate()->firstOrFail();
            if (! $this->canCancel($user, $locked)) {
                throw ValidationException::withMessages(['status' => 'No puedes cancelar esta incidencia.']);
            }
            $wasApproved = $locked->status === ApprovalStatus::Approved;
            $locked->forceFill([
                'status' => ApprovalStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $incidence->setRawAttributes($locked->getAttributes(), true);

            return $wasApproved;
        });

        $this->audit->log('incidence.cancelled', $incidence, 'Incidencia cancelada', ['reason' => $reason, 'was_approved' => $wasApproved]);
        if ($wasApproved) {
            $this->recompute($incidence);
        }

        return $incidence;
    }

    public function attach(Incidence $incidence, UploadedFile $file, User $user): Attachment
    {
        $path = (string) $file->store("evidence/{$incidence->organization_id}/incidences", 'local');

        return Attachment::query()->create([
            'organization_id' => $incidence->organization_id,
            'attachable_type' => $incidence->getMorphClass(),
            'attachable_id' => $incidence->id,
            'category' => 'evidence',
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'uploaded_by' => $user->id,
        ]);
    }

    public function recompute(Incidence $incidence): void
    {
        foreach (CarbonPeriod::create($incidence->starts_on, $incidence->ends_on) as $day) {
            RecomputeWorkday::dispatch($incidence->organization_id, $incidence->employee_id, $day->toDateString())->afterCommit();
        }
    }
}
