<?php

namespace App\Domain\Approvals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\AccessScope;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Autorizaciones multinivel configurables por organización y tipo de solicitud.
 *
 * `approval_flows.levels` = [{level:1, permission:"vacations.approve", label:"Supervisor"}, …].
 * Sin flujo configurado: un nivel con el permiso `<subject>s.approve`.
 * Nadie puede aprobar sus propias solicitudes.
 */
final class ApprovalWorkflow
{
    public const SUBJECT_PERMISSIONS = [
        'rectification' => 'rectifications.approve',
        'incidence' => 'incidences.approve',
        'vacation' => 'vacations.approve',
        'overtime' => 'overtime.approve',
    ];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{level:int, permission:string, label:string}>
     */
    public function levels(string $subject): array
    {
        $flow = ApprovalFlow::query()->where('subject', $subject)->first();
        $levels = $flow ? (array) $flow->levels : [];

        if ($levels === []) {
            return [['level' => 1, 'permission' => self::SUBJECT_PERMISSIONS[$subject], 'label' => 'Responsable']];
        }

        return array_values(array_map(fn ($l) => [
            'level' => (int) $l['level'],
            'permission' => (string) ($l['permission'] ?? self::SUBJECT_PERMISSIONS[$subject]),
            'label' => (string) ($l['label'] ?? 'Nivel '.$l['level']),
        ], $levels));
    }

    public function canDecide(User $user, Model $subjectModel, string $subject): bool
    {
        /** @var Employee $employee */
        $employee = $subjectModel->getRelationValue('employee') ?? Employee::query()->find($subjectModel->getAttribute('employee_id'));
        if ($employee === null || $employee->user_id === $user->id) {
            return false;
        }
        if ($subjectModel->getAttribute('status') !== ApprovalStatus::Pending) {
            return false;
        }

        $level = $this->currentLevel($subjectModel, $subject);

        return $level !== null
            && ($user->is_super_admin || $user->can($level['permission']))
            && $this->scope->canAccessEmployee($user, $employee);
    }

    /**
     * Registra la decisión. Devuelve true si la solicitud quedó resuelta en definitiva.
     *
     * @param  callable(Model): void|null  $onFinalApproval  efectos de la aprobación final (dentro de la transacción)
     */
    public function decide(User $user, Model $subjectModel, string $subject, bool $approve, ?string $comment, ?callable $onFinalApproval = null): bool
    {
        if (! $this->canDecide($user, $subjectModel, $subject)) {
            throw new AuthorizationException('No tienes autorización para resolver esta solicitud en su nivel actual.');
        }
        if (! $approve && blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'Indica el motivo del rechazo.']);
        }

        return DB::transaction(function () use ($user, $subjectModel, $subject, $approve, $comment, $onFinalApproval) {
            $subjectModel->newQuery()->whereKey($subjectModel->getKey())->lockForUpdate()->first();
            $levels = $this->levels($subject);
            $current = (int) ($subjectModel->getAttribute('current_level') ?? 1);

            ApprovalDecision::query()->create([
                'organization_id' => $subjectModel->getAttribute('organization_id'),
                'approvable_type' => $subjectModel->getMorphClass(),
                'approvable_id' => $subjectModel->getKey(),
                'level' => $current,
                'decision' => $approve ? 'approved' : 'rejected',
                'decided_by' => $user->id,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            $final = ! $approve || $current >= count($levels);
            if ($final) {
                $subjectModel->forceFill([
                    'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'review_comment' => $comment,
                ])->save();
                if ($approve && $onFinalApproval !== null) {
                    $onFinalApproval($subjectModel);
                }
            } else {
                $subjectModel->forceFill(['current_level' => $current + 1])->save();
            }

            $this->audit->log(
                $approve ? "{$subject}.approved" : "{$subject}.rejected",
                $subjectModel,
                ($approve ? 'Aprobó' : 'Rechazó')." solicitud ({$subject}) nivel {$current}".($final ? ' — resolución final' : ''),
                ['level' => $current, 'comment' => $comment],
            );

            return $final;
        });
    }

    /**
     * @return array{level:int, permission:string, label:string}|null
     */
    public function currentLevel(Model $subjectModel, string $subject): ?array
    {
        $current = (int) ($subjectModel->getAttribute('current_level') ?? 1);
        foreach ($this->levels($subject) as $level) {
            if ($level['level'] === $current) {
                return $level;
            }
        }

        return null;
    }
}
