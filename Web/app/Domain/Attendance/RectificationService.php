<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Rectifications\RectificationSettings;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Security\AccessScope;
use App\Domain\Signatures\ElectronicSignatureService;
use App\Domain\Signatures\SignaturePinService;
use App\Enums\AdjustmentAction;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Enums\RectificationStage;
use App\Jobs\RecomputeWorkday;
use App\Models\ApprovalDecision;
use App\Models\Attachment;
use App\Models\AttendanceAdjustment;
use App\Models\Device;
use App\Models\ElectronicSignature;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\Organization;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use App\Notifications\RectificationStageChanged;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Rectificaciones con firma del colaborador (PDF pantalla 05). Flujo lineal:
 *
 *   1-2. Solicitud con evidencia obligatoria          → por_autorizar
 *   3.   Autorización del jefe inmediato (approve)    → por_firmar (con plazo)
 *   4.   Firma del colaborador en la app (NIP/biom.)  → por_validar
 *   5.   Validación RH (validate) y aplicación        → aplicada
 *
 * Cualquier etapa abierta puede terminar en «rechazada» (rechazo, firma
 * declinada, plazo vencido o cancelación). El marcaje original NUNCA se toca:
 * la aplicación genera `attendance_adjustments` encadenados y se recalculan las
 * proyecciones. Nadie —ni super administrador— aplica un ajuste sin la firma
 * del colaborador, y nadie autoriza ni valida su propio registro ni su propia
 * solicitud.
 */
final class RectificationService
{
    public const MAX_EVIDENCE_FILES = 5;

    public const MAX_EVIDENCE_KB = 10240;

    /** @var list<string> */
    public const EVIDENCE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];

    public function __construct(
        private readonly HashChain $chain,
        private readonly ScheduleResolver $schedules,
        private readonly AuditLogger $audit,
        private readonly AccessScope $scope,
        private readonly ElectronicSignatureService $signatures,
        private readonly SignaturePinService $pins,
        private readonly RectificationSettings $settings,
        private readonly CurrentOrganization $current,
    ) {}

    // ── 1-2. Solicitud ──────────────────────────────────────────────────────

    /**
     * @param  array{date:string, punch_uuid:?string, kind:string, proposed_type:?string, proposed_time:?string, reason:string}  $data
     * @param  UploadedFile|list<UploadedFile>|null  $evidence  obligatoria (foto, documento o constancia)
     * @param  string  $origin  employee | manager | incidence
     */
    public function submit(User $requester, Employee $employee, array $data, UploadedFile|array|null $evidence = null, string $origin = 'employee', ?int $incidenceId = null): RectificationRequest
    {
        $files = $this->evidenceFiles($evidence);

        // A nombre de otro: rectifications.create (o incidences.create si nace de una omisión de checada).
        $onBehalfPermission = $origin === 'incidence' ? 'incidences.create' : 'rectifications.create';
        if ($requester->id !== $employee->user_id
            && ! ($requester->can($onBehalfPermission) && $this->scope->canAccessEmployee($requester, $employee))) {
            throw new AuthorizationException('No puedes solicitar rectificaciones para este colaborador.');
        }

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

        // Clave única mientras la solicitud esté abierta: impide duplicados del mismo marcaje/fecha.
        $openKey = implode('|', [$employee->id, $date->toDateString(), $punch?->uuid ?? ($data['proposed_type'] ?? $kind->value)]);

        try {
            $request = DB::transaction(function () use ($requester, $employee, $data, $files, $kind, $date, $punch, $openKey, $origin, $incidenceId) {
                $request = RectificationRequest::query()->create([
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'requested_by' => $requester->id,
                    'origin' => $origin,
                    'incidence_id' => $incidenceId,
                    'date' => $date->toDateString(),
                    'punch_uuid' => $punch?->uuid,
                    'kind' => $kind,
                    'proposed_type' => $data['proposed_type'] ?? null,
                    'proposed_time' => ! empty($data['proposed_time']) ? CarbonImmutable::parse($data['proposed_time'])->utc() : null,
                    'reason' => $data['reason'],
                    'status' => ApprovalStatus::Pending,
                    'stage' => RectificationStage::ToAuthorize,
                    'open_key' => $openKey,
                    'is_late' => $this->settings->isAfterPayrollClose($date),
                ]);

                foreach ($files as $file) {
                    $this->attach($request, $file, $requester);
                }

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['date' => 'Ya hay una solicitud abierta para ese día y marcaje.']);
        }

        $this->audit->log('rectification.submitted', $request, 'Solicitud de rectificación registrada', ['origin' => $origin, 'evidence' => count($files)]);
        $this->notifyStaff($request, 'rectifications.approve', 'submitted', 'approver');

        return $request;
    }

    // ── 3. Autorización del jefe inmediato ─────────────────────────────────

    public function canAuthorize(User $user, RectificationRequest $r): bool
    {
        return $r->stage === RectificationStage::ToAuthorize
            && $this->notSelf($user, $r)
            && $user->can('rectifications.approve')
            && $this->scope->canAccessEmployee($user, $r->employee_id);
    }

    public function authorize(User $approver, RectificationRequest $r, bool $approve, ?string $comment): RectificationRequest
    {
        if (! $approve && blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'Indica el motivo del rechazo.']);
        }

        $result = DB::transaction(function () use ($approver, $r, $approve, $comment) {
            $locked = $this->lock($r, RectificationStage::ToAuthorize);
            if (! $this->canAuthorize($approver, $locked)) {
                throw new AuthorizationException('No puedes autorizar esta solicitud.');
            }
            if ($approve && $locked->attachments()->count() === 0) {
                throw ValidationException::withMessages(['evidence' => 'La solicitud no tiene evidencia; no puede autorizarse.']);
            }

            $this->decision($locked, $approver, 1, $approve, $comment);

            if ($approve) {
                /** @var Organization $org */
                $org = Organization::query()->findOrFail($locked->organization_id);
                $locked->forceFill([
                    'stage' => RectificationStage::ToSign,
                    'authorized_by' => $approver->id,
                    'authorized_at' => now(),
                    'authorization_comment' => $comment,
                    'signature_due_at' => $this->settings->signatureDueAt($org, now()),
                ])->save();
            } else {
                $this->closeRejected($locked, $approver, RectificationStage::ToAuthorize->value, (string) $comment);
            }

            return $locked;
        });

        $this->audit->log($approve ? 'rectification.authorized' : 'rectification.rejected', $result,
            $approve ? 'Rectificación autorizada por el jefe inmediato; pendiente de firma' : 'Rectificación rechazada en autorización',
            ['comment' => $comment]);

        $approve
            ? $this->notifyEmployee($result, 'to_sign', 'Plazo: '.$result->signature_due_at?->setTimezone($this->timezone($result))->format('d/m/Y H:i').'.')
            : $this->notifyEmployee($result, 'rejected', $comment);

        return $result;
    }

    // ── 4. Firma del colaborador ───────────────────────────────────────────

    /**
     * Texto exacto que firma el colaborador (determinista: lo muestra la app y
     * con él calcula `signature_payload_sha256`).
     */
    public function statement(RectificationRequest $r): string
    {
        $o = $this->originalSummary($r);
        $p = $this->proposalSummary($r);

        return "Acepto la rectificación #{$r->id} del {$r->date->format('d/m/Y')}. Registro original: {$o}. Propuesta: {$p}. Motivo: {$r->reason}";
    }

    public function payloadSha256(RectificationRequest $r): string
    {
        return ElectronicSignatureService::payloadHashFor(ElectronicSignature::PURPOSE_RECTIFICATION, $r, (int) $r->employee_id, $this->statement($r));
    }

    /**
     * @throws ValidationException|AuthorizationException
     */
    public function sign(User $user, RectificationRequest $r, string $method, ?string $pin, ?Device $device, ?Request $request = null, ?string $deviceSignature = null): RectificationRequest
    {
        $this->ensureOwn($user, $r);
        $this->expireIfOverdue($r);

        // El NIP se verifica fuera de la transacción para que los intentos fallidos cuenten.
        if ($method === ElectronicSignature::METHOD_PIN && ($pin === null || $pin === '' || ! $this->pins->verify($user, $pin))) {
            throw ValidationException::withMessages(['pin' => 'NIP incorrecto.']);
        }

        $result = DB::transaction(function () use ($user, $r, $method, $pin, $device, $request, $deviceSignature) {
            $locked = $this->lock($r, RectificationStage::ToSign);
            if ($locked->signature_due_at !== null && $locked->signature_due_at->isPast()) {
                throw ValidationException::withMessages(['stage' => 'Venció el plazo de firma.']);
            }
            $signature = $this->signatures->sign($user, $locked, ElectronicSignature::PURPOSE_RECTIFICATION, $method, $pin, $device, $this->statement($locked), $request, $deviceSignature);

            $locked->forceFill([
                'stage' => RectificationStage::ToValidate,
                'electronic_signature_id' => $signature->id,
                'signed_at' => $signature->signed_at,
            ])->save();

            return $locked;
        });

        $this->audit->log('rectification.signed', $result, 'Rectificación firmada por el colaborador');
        $this->notifyStaff($result, 'rectifications.validate', 'signed', 'validator');

        return $result;
    }

    public function decline(User $user, RectificationRequest $r, string $reason): RectificationRequest
    {
        $this->ensureOwn($user, $r);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo.']);
        }
        $this->expireIfOverdue($r);

        $result = DB::transaction(function () use ($user, $r, $reason) {
            $locked = $this->lock($r, RectificationStage::ToSign);
            $this->closeRejected($locked, $user, 'declinada', $reason);

            return $locked;
        });

        $this->audit->log('rectification.declined', $result, 'El colaborador no aceptó firmar la rectificación', ['reason' => $reason]);
        $this->notifyUser($result->authorized_by, $result, 'declined', 'approver', $reason);

        return $result;
    }

    // ── 5. Validación RH y aplicación ──────────────────────────────────────

    public function canValidate(User $user, RectificationRequest $r): bool
    {
        return $r->stage === RectificationStage::ToValidate
            && $this->notSelf($user, $r)
            && $user->can('rectifications.validate')
            && $this->scope->canAccessEmployee($user, $r->employee_id);
    }

    /**
     * Extemporánea: la fecha ya pertenece a una nómina cerrada.
     */
    public function isLate(RectificationRequest $r): bool
    {
        return (bool) $r->is_late || $this->settings->isAfterPayrollClose($r->date);
    }

    public function validate(User $hr, RectificationRequest $r, bool $approve, ?string $comment): RectificationRequest
    {
        if (! $approve && blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'Indica el motivo del rechazo.']);
        }

        $result = DB::transaction(function () use ($hr, $r, $approve, $comment) {
            $locked = $this->lock($r, RectificationStage::ToValidate);
            if (! $this->canValidate($hr, $locked)) {
                throw new AuthorizationException('No puedes validar esta solicitud.');
            }

            $late = $this->isLate($locked);
            if ($approve) {
                // Sin firma válida del colaborador NO se aplica (regla dura, también para super admin).
                $signature = $locked->electronic_signature_id ? ElectronicSignature::query()->find($locked->electronic_signature_id) : null;
                if ($signature === null
                    || (int) $signature->employee_id !== (int) $locked->employee_id
                    || $signature->signable_type !== $locked->getMorphClass()
                    || (int) $signature->signable_id !== (int) $locked->id
                    || ! $this->signatures->verifySeal($signature)) {
                    throw ValidationException::withMessages(['signature' => 'La solicitud no tiene una firma válida del colaborador.']);
                }
                if ($late && ! $hr->can('rectifications.apply_late')) {
                    throw new AuthorizationException('Rectificación extemporánea: sólo RH con permiso para aplicar después del cierre de nómina.');
                }
            }

            $this->decision($locked, $hr, 2, $approve, $comment);

            if ($approve) {
                $this->applyAdjustment($locked, $hr);
                $locked->forceFill([
                    'stage' => RectificationStage::Applied,
                    'status' => ApprovalStatus::Approved,
                    'validated_by' => $hr->id,
                    'validated_at' => now(),
                    'validation_comment' => $comment,
                    'applied_at' => now(),
                    'reviewed_by' => $hr->id,
                    'reviewed_at' => now(),
                    'review_comment' => $comment,
                    'is_late' => $late,
                    'open_key' => null,
                ])->save();
                $this->syncIncidence($locked, ApprovalStatus::Approved, $hr, $comment);
            } else {
                $this->closeRejected($locked, $hr, RectificationStage::ToValidate->value, (string) $comment);
            }

            return $locked;
        });

        $this->audit->log($approve ? 'rectification.applied' : 'rectification.rejected', $result,
            $approve ? 'Rectificación validada por RH y aplicada'.($result->is_late ? ' (extemporánea)' : '') : 'Rectificación rechazada en validación RH',
            ['comment' => $comment, 'late' => $result->is_late]);

        if ($approve) {
            $this->recompute($result);
            $this->notifyEmployee($result, 'applied');
        } else {
            $this->notifyEmployee($result, 'rejected', $comment);
        }

        return $result;
    }

    // ── Cancelación y vencimiento ──────────────────────────────────────────

    public function cancel(User $user, RectificationRequest $r): void
    {
        DB::transaction(function () use ($user, $r) {
            $locked = RectificationRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($locked->stage !== RectificationStage::ToAuthorize || $locked->requested_by !== $user->id) {
                throw ValidationException::withMessages(['status' => 'Sólo puedes cancelar tus solicitudes por autorizar.']);
            }
            $locked->forceFill([
                'stage' => RectificationStage::Rejected,
                'status' => ApprovalStatus::Cancelled,
                'rejection_stage' => 'cancelada',
                'rejection_reason' => 'Cancelada por el solicitante',
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'open_key' => null,
            ])->save();
            $this->syncIncidence($locked, ApprovalStatus::Cancelled, $user, 'Rectificación cancelada');
            $r->setRawAttributes($locked->getAttributes(), true);
        });
        $this->audit->log('rectification.cancelled', $r, 'Solicitud de rectificación cancelada por el solicitante');
    }

    /**
     * Rechaza automáticamente las solicitudes cuyo plazo de firma venció. El día
     * queda como omisión (no se genera ningún ajuste ni marcaje).
     *
     * @return int solicitudes vencidas
     */
    public function expireOverdue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;
        $ids = RectificationRequest::query()->withoutGlobalScopes()
            ->where('stage', RectificationStage::ToSign)
            ->where('signature_due_at', '<', $now)
            ->pluck('organization_id', 'id');

        foreach ($ids as $id => $organizationId) {
            $org = Organization::query()->find((int) $organizationId);
            if ($org === null) {
                continue;
            }
            $this->current->runAs($org, function () use ($id, $now, &$count) {
                $r = RectificationRequest::query()->find($id);
                if ($r !== null && $this->expire($r, $now)) {
                    $count++;
                }
            });
        }

        return $count;
    }

    private function expireIfOverdue(RectificationRequest $r): void
    {
        if ($r->stage === RectificationStage::ToSign && $r->signature_due_at !== null && $r->signature_due_at->isPast()) {
            $this->expire($r, now());
            throw ValidationException::withMessages(['stage' => 'Venció el plazo de firma; la solicitud se rechazó.']);
        }
    }

    private function expire(RectificationRequest $r, CarbonInterface $now): bool
    {
        $expired = DB::transaction(function () use ($r, $now) {
            $locked = RectificationRequest::query()->whereKey($r->id)->lockForUpdate()->first();
            if ($locked === null || $locked->stage !== RectificationStage::ToSign || $locked->signature_due_at === null || $locked->signature_due_at->gte($now)) {
                return null;
            }
            $this->closeRejected($locked, null, 'vencida', 'Plazo de firma vencido; el día queda como omisión.');

            return $locked;
        });

        if ($expired === null) {
            return false;
        }

        $this->audit->log('rectification.expired', $expired, 'Rectificación rechazada automáticamente por plazo de firma vencido');
        $this->recompute($expired);
        $this->notifyEmployee($expired, 'expired');
        $this->notifyUser($expired->authorized_by, $expired, 'expired', 'approver');

        return true;
    }

    // ── Presentación (registro original contra propuesta) ─────────────────

    public function originalSummary(RectificationRequest $r): string
    {
        $tz = $this->timezone($r);
        $punch = $r->punch_uuid ? Punch::query()->where('uuid', $r->punch_uuid)->first() : null;
        if ($punch !== null) {
            return $punch->type->label().' '.EffectiveTimeline::punchTime($punch)->setTimezone($tz)->format('H:i');
        }
        if ($r->proposed_type !== null) {
            return 'Sin '.mb_strtolower($r->proposed_type->label());
        }

        return 'Sin marcaje';
    }

    public function proposalSummary(RectificationRequest $r): string
    {
        $tz = $this->timezone($r);
        $time = $r->proposed_time ? CarbonImmutable::instance($r->proposed_time)->setTimezone($tz)->format('H:i') : null;
        $type = $r->proposed_type?->label();

        return match (true) {
            $type !== null && $time !== null => "{$type} {$time}",
            $time !== null => "Hora {$time}",
            $type !== null => $type,
            default => 'Aclaración sin cambio de hora',
        };
    }

    // ── Internos ───────────────────────────────────────────────────────────

    /**
     * Bloquea la fila y exige la etapa esperada (evita decisiones duplicadas o
     * saltarse etapas con llamadas directas).
     */
    private function lock(RectificationRequest $r, RectificationStage $expected): RectificationRequest
    {
        /** @var RectificationRequest $locked */
        $locked = RectificationRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
        if ($locked->stage !== $expected) {
            throw ValidationException::withMessages(['stage' => 'La solicitud ya no está en la etapa «'.$expected->label().'» (actual: '.$locked->stage->label().').']);
        }
        $r->setRawAttributes($locked->getAttributes(), true);

        return $locked;
    }

    private function notSelf(User $user, RectificationRequest $r): bool
    {
        $employeeUserId = Employee::query()->whereKey($r->employee_id)->value('user_id');

        return $employeeUserId !== null && (int) $employeeUserId !== $user->id && (int) $r->requested_by !== $user->id;
    }

    private function ensureOwn(User $user, RectificationRequest $r): void
    {
        $employeeUserId = Employee::query()->whereKey($r->employee_id)->value('user_id');
        if ($employeeUserId === null || (int) $employeeUserId !== $user->id) {
            throw new AuthorizationException('Sólo el colaborador puede firmar su rectificación.');
        }
    }

    private function closeRejected(RectificationRequest $r, ?User $by, string $stage, string $reason): void
    {
        $r->forceFill([
            'stage' => RectificationStage::Rejected,
            'status' => ApprovalStatus::Rejected,
            'rejection_stage' => $stage,
            'rejection_reason' => $reason,
            'rejected_by' => $by?->id,
            'rejected_at' => now(),
            'reviewed_by' => $by?->id,
            'reviewed_at' => now(),
            'review_comment' => $reason,
            'open_key' => null,
        ])->save();
        $this->syncIncidence($r, ApprovalStatus::Rejected, $by, $reason);
    }

    private function decision(RectificationRequest $r, User $user, int $level, bool $approve, ?string $comment): void
    {
        ApprovalDecision::query()->create([
            'organization_id' => $r->organization_id,
            'approvable_type' => $r->getMorphClass(),
            'approvable_id' => $r->id,
            'level' => $level,
            'decision' => $approve ? 'approved' : 'rejected',
            'decided_by' => $user->id,
            'comment' => $comment,
            'decided_at' => now(),
        ]);
    }

    /**
     * La incidencia de omisión de checada sigue el resultado de su rectificación.
     */
    private function syncIncidence(RectificationRequest $r, ApprovalStatus $status, ?User $by, ?string $comment): void
    {
        if ($r->incidence_id === null) {
            return;
        }
        $incidence = Incidence::query()->find($r->incidence_id);
        if ($incidence === null || ! in_array($incidence->status, [ApprovalStatus::Pending], true)) {
            return;
        }
        $incidence->forceFill([
            'status' => $status,
            'reviewed_by' => $by?->id,
            'reviewed_at' => now(),
            'review_comment' => $comment,
        ])->save();
    }

    private function applyAdjustment(RectificationRequest $r, User $validator): void
    {
        $punch = $r->punch_uuid ? Punch::query()->where('uuid', $r->punch_uuid)->first() : null;

        [$action, $type, $time] = match (true) {
            $r->kind === RectificationKind::MissingPunch => [AdjustmentAction::Add, $r->proposed_type, $r->proposed_time],
            $r->kind === RectificationKind::WrongType && $punch !== null => [AdjustmentAction::Replace, $r->proposed_type, EffectiveTimeline::punchTime($punch)],
            $r->kind === RectificationKind::TimeClarification && $punch !== null => [AdjustmentAction::Replace, $punch->type, $r->proposed_time],
            $punch !== null && $r->proposed_time !== null => [AdjustmentAction::Replace, $r->proposed_type ?? $punch->type, $r->proposed_time],
            $punch === null && $r->proposed_time !== null && $r->proposed_type !== null => [AdjustmentAction::Add, $r->proposed_type, $r->proposed_time],
            default => [null, null, null],
        };

        if ($action === null) {
            return; // Aclaración sin efecto en el cálculo: queda la constancia firmada y validada.
        }

        $employee = Employee::query()->findOrFail($r->employee_id);
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
            'approved_by' => $validator->id,
            'reason' => $r->reason,
            'sequence' => $link['sequence'],
            'previous_hash' => $link['previous_hash'],
            'created_at' => now('UTC'),
        ]);
        $adj->hash = $this->chain->hash($link['previous_hash'], HashChain::adjustmentPayload($adj));
        $adj->save();
    }

    private function recompute(RectificationRequest $r): void
    {
        RecomputeWorkday::dispatch($r->organization_id, $r->employee_id, $r->date->toDateString())->afterCommit();
        RecomputeWorkday::dispatch($r->organization_id, $r->employee_id, $r->date->subDay()->toDateString())->afterCommit();
    }

    private function timezone(RectificationRequest $r): string
    {
        $employee = Employee::query()->find($r->employee_id);

        return $employee ? $this->schedules->timezoneFor($employee) : 'America/Mexico_City';
    }

    /**
     * @param  UploadedFile|list<UploadedFile>|null  $evidence
     * @return list<UploadedFile>
     */
    private function evidenceFiles(UploadedFile|array|null $evidence): array
    {
        $files = array_values(array_filter(is_array($evidence) ? $evidence : [$evidence], fn ($f) => $f instanceof UploadedFile));
        if ($files === []) {
            throw ValidationException::withMessages(['evidence' => 'Adjunta la evidencia (foto, documento o constancia).']);
        }
        if (count($files) > self::MAX_EVIDENCE_FILES) {
            throw ValidationException::withMessages(['evidence' => 'Máximo '.self::MAX_EVIDENCE_FILES.' archivos de evidencia.']);
        }
        foreach ($files as $file) {
            if (! $file->isValid() || ! in_array((string) $file->getMimeType(), self::EVIDENCE_MIMES, true)) {
                throw ValidationException::withMessages(['evidence' => 'La evidencia debe ser imagen (JPG, PNG, WEBP, HEIC) o PDF.']);
            }
            if ((int) $file->getSize() > self::MAX_EVIDENCE_KB * 1024) {
                throw ValidationException::withMessages(['evidence' => 'Cada archivo de evidencia debe pesar máximo 10 MB.']);
            }
        }

        return $files;
    }

    public function attach(RectificationRequest $request, UploadedFile $file, User $user): Attachment
    {
        $path = (string) $file->store("evidence/{$request->organization_id}/rectifications", 'local');

        return Attachment::query()->create([
            'organization_id' => $request->organization_id,
            'attachable_type' => $request->getMorphClass(),
            'attachable_id' => $request->id,
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

    // ── Notificaciones ─────────────────────────────────────────────────────

    private function notifyEmployee(RectificationRequest $r, string $event, ?string $detail = null): void
    {
        $userId = Employee::query()->whereKey($r->employee_id)->value('user_id');
        $this->notifyUser($userId !== null ? (int) $userId : null, $r, $event, 'employee', $detail);
    }

    private function notifyUser(?int $userId, RectificationRequest $r, string $event, string $audience, ?string $detail = null): void
    {
        $user = $userId ? User::query()->find($userId) : null;
        $user?->notify((new RectificationStageChanged($r->id, $event, $audience, $detail))->forOrganization($r->organization_id));
    }

    /**
     * Avisa a quienes pueden atender la siguiente etapa dentro de su alcance.
     */
    private function notifyStaff(RectificationRequest $r, string $permission, string $event, string $audience): void
    {
        foreach ($this->staffWith($permission, $r) as $user) {
            $user->notify((new RectificationStageChanged($r->id, $event, $audience))->forOrganization($r->organization_id));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function staffWith(string $permission, RectificationRequest $r): Collection
    {
        try {
            $candidates = User::permission($permission)
                ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $r->organization_id)->where('organization_user.status', 'active'))
                ->limit(50)->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }

        return $candidates->filter(fn (User $u) => $this->notSelf($u, $r) && $this->scope->canAccessEmployee($u, $r->employee_id))->values();
    }
}
