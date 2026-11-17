<?php

namespace App\Domain\Attendance;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\AdjustmentAction;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Jobs\RecomputeWorkday;
use App\Models\Attachment;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Procedimiento de corrección/aclaración. El marcaje original nunca se toca:
 * la aprobación genera `attendance_adjustments` (append-only, encadenados) y
 * se recalculan las proyecciones.
 */
final class RectificationService
{
    public function __construct(
        private readonly ApprovalWorkflow $workflow,
        private readonly HashChain $chain,
        private readonly ScheduleResolver $schedules,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{date:string, punch_uuid:?string, kind:string, proposed_type:?string, proposed_time:?string, reason:string}  $data
     */
    public function submit(User $requester, Employee $employee, array $data, ?UploadedFile $evidence = null): RectificationRequest
    {
        $kind = RectificationKind::from($data['kind']);
        $date = CarbonImmutable::parse($data['date']);
        if ($date->isAfter(now()->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'No es posible rectificar una fecha futura.']);
        }

        $punch = null;
        if (! empty($data['punch_uuid'])) {
            $punch = Punch::query()->where('uuid', $data['punch_uuid'])->where('employee_id', $employee->id)->first()
                ?? throw ValidationException::withMessages(['punch_uuid' => 'El marcaje indicado no pertenece al colaborador.']);
        }

        match ($kind) {
            RectificationKind::MissingPunch => (empty($data['proposed_type']) || empty($data['proposed_time']))
                ? throw ValidationException::withMessages(['proposed_time' => 'Indica el tipo y la hora del marcaje omitido.']) : null,
            RectificationKind::WrongType => ($punch === null || empty($data['proposed_type']))
                ? throw ValidationException::withMessages(['proposed_type' => 'Selecciona el marcaje y el tipo correcto.']) : null,
            RectificationKind::TimeClarification => ($punch === null || empty($data['proposed_time']))
                ? throw ValidationException::withMessages(['proposed_time' => 'Selecciona el marcaje y la hora reconocida.']) : null,
            RectificationKind::Other => null,
        };

        if (! empty($data['proposed_time']) && CarbonImmutable::parse($data['proposed_time'])->isFuture()) {
            throw ValidationException::withMessages(['proposed_time' => 'La hora propuesta no puede ser futura.']);
        }

        $request = RectificationRequest::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'requested_by' => $requester->id,
            'date' => $date->toDateString(),
            'punch_uuid' => $punch?->uuid,
            'kind' => $kind,
            'proposed_type' => $data['proposed_type'] ?? null,
            'proposed_time' => ! empty($data['proposed_time']) ? CarbonImmutable::parse($data['proposed_time'])->utc() : null,
            'reason' => $data['reason'],
            'status' => ApprovalStatus::Pending,
        ]);

        if ($evidence !== null) {
            $this->attach($request, $evidence, $requester);
        }

        $this->audit->log('rectification.submitted', $request, 'Solicitud de rectificación registrada');

        return $request;
    }

    public function decide(User $reviewer, RectificationRequest $request, bool $approve, ?string $comment, bool $voidTarget = false): RectificationRequest
    {
        $final = $this->workflow->decide($reviewer, $request, 'rectification', $approve, $comment, function (RectificationRequest $r) use ($reviewer, $voidTarget) {
            $this->applyAdjustment($r, $reviewer, $voidTarget);
        });

        $request->refresh();
        if ($final) {
            RecomputeWorkday::dispatch($request->organization_id, $request->employee_id, $request->date->toDateString())->afterCommit();
            RecomputeWorkday::dispatch($request->organization_id, $request->employee_id, $request->date->subDay()->toDateString())->afterCommit();
            $request->employee?->user?->notify(new RequestStatusChanged('rectification', $request->id, $request->status, $request->review_comment));
        }

        return $request;
    }

    public function cancel(User $user, RectificationRequest $request): void
    {
        if ($request->status !== ApprovalStatus::Pending || $request->requested_by !== $user->id) {
            throw ValidationException::withMessages(['status' => 'Sólo puedes cancelar tus solicitudes pendientes.']);
        }
        $request->update(['status' => ApprovalStatus::Cancelled]);
        $this->audit->log('rectification.cancelled', $request, 'Solicitud de rectificación cancelada por el solicitante');
    }

    private function applyAdjustment(RectificationRequest $r, User $approver, bool $voidTarget): void
    {
        $punch = $r->punch_uuid ? Punch::query()->where('uuid', $r->punch_uuid)->first() : null;

        [$action, $type, $time] = match (true) {
            $voidTarget && $punch !== null => [AdjustmentAction::Void, null, null],
            $r->kind === RectificationKind::MissingPunch => [AdjustmentAction::Add, $r->proposed_type, $r->proposed_time],
            $r->kind === RectificationKind::WrongType && $punch !== null => [AdjustmentAction::Replace, $r->proposed_type, EffectiveTimeline::punchTime($punch)],
            $r->kind === RectificationKind::TimeClarification && $punch !== null => [AdjustmentAction::Replace, $punch->type, $r->proposed_time],
            $punch !== null && $r->proposed_time !== null => [AdjustmentAction::Replace, $r->proposed_type ?? $punch->type, $r->proposed_time],
            $punch === null && $r->proposed_time !== null && $r->proposed_type !== null => [AdjustmentAction::Add, $r->proposed_type, $r->proposed_time],
            default => [null, null, null],
        };

        if ($action === null) {
            return; // Aclaración sin efecto en el cálculo: queda la constancia aprobada.
        }

        $employee = $r->employee;
        $tz = $this->schedules->timezoneFor($employee);
        $localDate = $time ? CarbonImmutable::instance($time)->setTimezone($tz)->toDateString() : $r->date->toDateString();

        $link = $this->chain->next($r->organization_id, AttendanceAdjustment::class);
        $adj = new AttendanceAdjustment([
            'uuid' => (string) Str::uuid7(),
            'organization_id' => $r->organization_id,
            'employee_id' => $r->employee_id,
            'rectification_request_id' => $r->id,
            'action' => $action,
            'target_punch_uuid' => $punch?->uuid,
            'type' => $type instanceof PunchType ? $type : ($type !== null ? PunchType::from((string) $type) : null),
            'recognized_time' => $time ? CarbonImmutable::instance($time)->utc() : null,
            'local_date' => $localDate,
            'approved_by' => $approver->id,
            'reason' => $r->reason,
            'sequence' => $link['sequence'],
            'previous_hash' => $link['previous_hash'],
            'created_at' => now('UTC'),
        ]);
        $adj->hash = $this->chain->hash($link['previous_hash'], HashChain::adjustmentPayload($adj));
        $adj->save();
    }

    public function attach(RectificationRequest $request, UploadedFile $file, User $user): Attachment
    {
        $path = $file->store("evidence/{$request->organization_id}/rectifications", 'local');

        return Attachment::query()->create([
            'organization_id' => $request->organization_id,
            'attachable_type' => $request->getMorphClass(),
            'attachable_id' => $request->id,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', Storage::disk('local')->path((string) $path)),
            'uploaded_by' => $user->id,
        ]);
    }
}
