<?php

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Enums\AlertSeverity;
use App\Enums\EmployeeStatus;
use App\Models\ComplianceAlert;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Models\UserAccessReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ciclo de vida del acceso de usuarios (PDF pantalla 18):
 *  - Acceso de autoridad laboral de sólo lectura con vigencia y revocación.
 *  - Revisión trimestral de usuarios activos con hallazgos por usuario.
 *  - Desactivación de accesos (membresía inactiva + cierre de sesiones API), sin borrar nada.
 */
final class UserAccessService
{
    /** organization_settings.key con {user_id: {expires_on, granted_by, granted_at, revoked_at?}}. */
    public const AUTHORITY_SETTING = 'authority_access';

    /** Vigencia máxima de un acceso de autoridad laboral. */
    public const AUTHORITY_MAX_DAYS = 365;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TwoFactorPolicy $twoFactor,
    ) {}

    /**
     * Desactiva la pertenencia del usuario a la organización y cierra sus sesiones de API.
     */
    public function deactivate(User $user, int $organizationId, string $reason, ?User $by = null): void
    {
        DB::table('organization_user')->where('organization_id', $organizationId)->where('user_id', $user->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);
        $user->tokens()->delete();
        $this->audit->log('user.deactivated', $user, 'Acceso desactivado en la organización: '.$user->email, ['reason' => $reason, 'by' => $by?->id], $organizationId);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function authorityGrants(int $organizationId): array
    {
        $value = OrganizationSetting::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)->where('key', self::AUTHORITY_SETTING)->value('value');
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? $value : [];
    }

    public function grantAuthority(User $user, int $organizationId, string $expiresOn, User $by): void
    {
        $setting = OrganizationSetting::query()->withoutGlobalScopes()->firstOrNew(['organization_id' => $organizationId, 'key' => self::AUTHORITY_SETTING]);
        $value = (array) ($setting->value ?? []);
        $before = $value[(string) $user->id] ?? null;
        $value[(string) $user->id] = ['expires_on' => $expiresOn, 'granted_by' => $by->id, 'granted_at' => now()->toIso8601String(), 'revoked_at' => null];
        $setting->forceFill(['organization_id' => $organizationId, 'value' => $value, 'updated_by' => $by->id])->save();

        $this->audit->log('user.authority_access_granted', $user, 'Acceso de autoridad laboral otorgado: '.$user->email, [
            'before' => $before, 'after' => $value[(string) $user->id],
        ], $organizationId);
    }

    /**
     * Revoca de inmediato un acceso de autoridad laboral (antes de su vigencia).
     */
    public function revokeAuthority(User $user, int $organizationId, User $by, string $reason): void
    {
        $setting = OrganizationSetting::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->where('key', self::AUTHORITY_SETTING)->first();
        $value = (array) ($setting?->value ?? []);
        $before = $value[(string) $user->id] ?? null;
        if ($setting !== null && $before !== null) {
            $value[(string) $user->id] = [...$before, 'revoked_at' => now()->toIso8601String(), 'revoked_by' => $by->id];
            $setting->forceFill(['value' => $value, 'updated_by' => $by->id])->save();
        }
        $this->deactivate($user, $organizationId, $reason, $by);
        $this->audit->log('user.authority_access_revoked', $user, 'Acceso de autoridad laboral revocado: '.$user->email, [
            'before' => $before, 'after' => $value[(string) $user->id] ?? null, 'reason' => $reason,
        ], $organizationId);
    }

    /**
     * Desactiva los accesos de autoridad laboral vencidos (proceso diario).
     */
    public function expireAuthorities(Organization $organization): int
    {
        $today = now($organization->timezone)->toDateString();
        $count = 0;
        foreach ($this->authorityGrants($organization->id) as $userId => $grant) {
            $expires = $grant['expires_on'] ?? null;
            if ($expires === null || (string) $expires >= $today) {
                continue;
            }
            $active = DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', (int) $userId)->where('status', 'active')->exists();
            $user = User::query()->find((int) $userId);
            if ($active && $user !== null) {
                $this->deactivate($user, $organization->id, 'Vigencia de autoridad laboral vencida ('.$expires.')');
                $count++;
            }
        }

        return $count;
    }

    /**
     * Abre (idempotente) la revisión del trimestre en curso y genera la alerta en Cumplimiento.
     */
    public function openQuarterlyReview(Organization $organization, ?CarbonImmutable $now = null): UserAccessReview
    {
        $now ??= CarbonImmutable::now($organization->timezone);
        $period = $now->year.'-T'.$now->quarter;

        $review = UserAccessReview::query()->withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $organization->id, 'period' => $period],
            ['due_on' => $now->lastOfQuarter()->toDateString(), 'status' => 'open'],
        );

        if ($review->wasRecentlyCreated) {
            ComplianceAlert::query()->withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'employee_id' => null,
                'date' => $now->toDateString(),
                'rule_code' => 'user_access_review',
                'severity' => AlertSeverity::Info,
                'message' => "Revisión trimestral de usuarios activos {$period} pendiente.",
                'legal_basis' => 'Control interno de accesos (separación de funciones)',
                'data' => ['review_id' => $review->id, 'period' => $period],
                'status' => 'open',
            ]);
            $this->audit->log('user_review.opened', $review, "Revisión de usuarios {$period} abierta", [], $organization->id);
        }

        return $review;
    }

    /**
     * Usuarios activos de la organización con sus hallazgos para la revisión.
     *
     * @return list<array<string, mixed>>
     */
    public function reviewRows(Organization $organization): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organization->id);
        $inactiveDays = (int) config('security.inactive_after_days', 90);
        $authority = $this->authorityGrants($organization->id);
        $today = now($organization->timezone)->toDateString();

        try {
            $users = User::query()
                ->join('organization_user as ou', fn ($j) => $j->on('ou.user_id', '=', 'users.id')->where('ou.organization_id', $organization->id))
                ->where('ou.status', 'active')
                ->select('users.*', 'ou.access_all_branches')
                ->with(['roles' => fn ($q) => $q->where('roles.organization_id', $organization->id), 'branches' => fn ($q) => $q->where('branch_user.organization_id', $organization->id)])
                ->orderBy('users.name')
                ->get();
            $employees = Employee::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
                ->whereIn('user_id', $users->pluck('id'))->get(['id', 'user_id', 'status'])->keyBy('user_id');

            $rows = [];
            foreach ($users as $user) {
                $user->unsetRelation('permissions');
                $roles = $user->roles->pluck('name')->all();
                $permissions = $user->getAllPermissions()->pluck('name')->all();
                $flags = [];

                if ($user->last_login_at === null || $user->last_login_at->lt(now()->subDays($inactiveDays))) {
                    $flags[] = 'inactive';
                }
                $employee = $employees->get($user->id);
                if ($employee !== null && $employee->status === EmployeeStatus::Terminated) {
                    $flags[] = 'terminated';
                }
                $grant = $authority[(string) $user->id] ?? null;
                if (in_array(PermissionCatalog::ROLE_AUTHORITY, $roles, true) && ($grant === null || ($grant['expires_on'] ?? null) === null || (string) $grant['expires_on'] < $today)) {
                    $flags[] = 'authority_expired';
                }
                if (PermissionCatalog::segregationConflicts($permissions) !== []) {
                    $flags[] = 'segregation';
                }
                if ($this->twoFactor->enforced() && PermissionCatalog::requiresTwoFactor($permissions) && ! $this->twoFactor->isConfigured($user)) {
                    $flags[] = 'two_factor_missing';
                }
                if (array_intersect($roles, PermissionCatalog::BRANCH_SCOPED_ROLES) !== [] && ((bool) $user->getAttribute('access_all_branches') || $user->branches->isEmpty())) {
                    $flags[] = 'scope_missing';
                }

                $rows[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $roles,
                    'branches' => $user->branches->pluck('name')->values()->all(),
                    'access_all_branches' => (bool) $user->getAttribute('access_all_branches'),
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'flags' => $flags,
                ];
            }

            return $rows;
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    public function decide(UserAccessReview $review, User $subject, string $decision, User $by, ?string $note): void
    {
        $decisions = $review->decisions ?? [];
        $decisions[(string) $subject->id] = ['decision' => $decision, 'by' => $by->id, 'at' => now()->toIso8601String(), 'note' => $note];
        $review->forceFill(['decisions' => $decisions])->save();

        if ($decision === 'deactivate') {
            $this->deactivate($subject, $review->organization_id, 'Revisión trimestral '.$review->period.($note ? ': '.$note : ''), $by);
        }
        $this->audit->log('user_review.decided', $review, "Revisión {$review->period}: {$subject->email} → {$decision}", [
            'user_id' => $subject->id, 'decision' => $decision, 'note' => $note,
        ], $review->organization_id);
    }

    public function complete(UserAccessReview $review, User $by, Organization $organization): void
    {
        $rows = $this->reviewRows($organization);
        $review->forceFill([
            'status' => 'completed',
            'completed_by' => $by->id,
            'completed_at' => now(),
            'summary' => [
                'active_users' => count($rows),
                'flagged' => count(array_filter($rows, fn ($r) => $r['flags'] !== [])),
                'deactivated' => count(array_filter($review->decisions ?? [], fn ($d) => $d['decision'] === 'deactivate')),
            ],
        ])->save();

        ComplianceAlert::query()->withoutGlobalScopes()
            ->where('organization_id', $review->organization_id)->where('rule_code', 'user_access_review')->where('status', 'open')
            ->whereJsonContains('data->review_id', $review->id)
            ->update(['status' => 'resolved', 'resolved_by' => $by->id, 'resolved_at' => now(), 'resolution_note' => 'Revisión completada']);

        $this->audit->log('user_review.completed', $review, "Revisión de usuarios {$review->period} completada", (array) $review->summary, $review->organization_id);
    }
}
