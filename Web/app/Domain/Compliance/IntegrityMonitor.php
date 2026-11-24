<?php

namespace App\Domain\Compliance;

use App\Domain\Attendance\IntegrityAnchorService;
use App\Domain\Attendance\IntegrityVerifier;
use App\Domain\Audit\AuditLogger;
use App\Enums\AlertSeverity;
use App\Models\ComplianceAlert;
use App\Models\Organization;
use App\Notifications\IntegrityAlert;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificación y anclaje externo de la integridad del registro, automáticos
 * diarios (scheduler) o manuales (botón). Si la verificación detecta
 * alteraciones, o el anclaje externo falla, genera una alerta crítica en
 * Cumplimiento y avisa a RH y Sistemas (PDF pantallas 14–16, punto 2).
 */
final class IntegrityMonitor
{
    public const CHAINS = ['punches' => 'Marcajes originales', 'adjustments' => 'Ajustes por rectificación'];

    /** RH (atiende alertas) y Sistemas (verifica integridad). */
    public const ALERT_PERMISSIONS = ['compliance.resolve', 'integrity.verify'];

    public function __construct(
        private readonly IntegrityVerifier $verifier,
        private readonly IntegrityAnchorService $anchors,
        private readonly AuditLogger $audit,
        private readonly StaffNotifier $notifier,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * Anclaje + verificación (rutina diaria).
     *
     * @return array{verification: array<string, mixed>, anchor: array<string, mixed>}
     */
    public function daily(Organization $organization): array
    {
        return [
            'anchor' => $this->anchor($organization, automatic: true),
            'verification' => $this->verify($organization, automatic: true),
        ];
    }

    /**
     * @return array{valid:bool, automatic:bool, chains:list<array<string,mixed>>, findings:int}
     */
    public function verify(Organization $organization, bool $automatic): array
    {
        return $this->current->runAs($organization, function () use ($organization, $automatic) {
            $chains = [];
            foreach (self::CHAINS as $chain => $label) {
                $result = $this->verifier->verify($organization->id, $chain);
                $result['errors_total'] = count($result['errors']);
                $result['errors'] = array_slice($result['errors'], 0, 200);
                $result['label'] = $label;
                $chains[] = $result;
            }
            $valid = collect($chains)->every(fn ($c) => $c['valid']);
            $findings = (int) collect($chains)->sum('errors_total');

            $this->audit->log('integrity.verified', null, ($automatic ? 'Verificación automática' : 'Verificación manual').($valid ? ' sin hallazgos' : ' con hallazgos'), [
                'valid' => $valid,
                'automatic' => $automatic,
                'chains' => $chains,
            ], $organization->id);

            if (! $valid) {
                // Se avisa una vez por día y tipo de falla (la alerta se deduplica).
                $this->raise($organization, 'integrity_failed', "Verificación de integridad con {$findings} inconsistencia(s).", ['findings' => $findings])
                    && $this->notifier->notify($organization, self::ALERT_PERMISSIONS, new IntegrityAlert(IntegrityAlert::VERIFICATION_FAILED, $findings));
            }

            return ['valid' => $valid, 'automatic' => $automatic, 'chains' => $chains, 'findings' => $findings];
        });
    }

    /**
     * @return array{ok:bool, created:int, error:string|null}
     */
    public function anchor(Organization $organization, bool $automatic): array
    {
        return $this->current->runAs($organization, function () use ($organization, $automatic) {
            try {
                $created = count($this->anchors->anchor($organization->id));
            } catch (Throwable $e) {
                Log::error('integrity_anchor_failed', ['organization_id' => $organization->id, 'error' => $e->getMessage()]);
                $detail = mb_substr($e->getMessage(), 0, 160);
                $this->audit->log('integrity.anchor_failed', null, 'Falló el anclaje externo de la cadena', ['automatic' => $automatic, 'error' => $detail], $organization->id);
                $this->raise($organization, 'integrity_anchor_failed', 'No se pudo anclar la cadena de integridad fuera de la base de datos.', ['error' => $detail])
                    && $this->notifier->notify($organization, self::ALERT_PERMISSIONS, new IntegrityAlert(IntegrityAlert::ANCHOR_FAILED, 0, $detail));

                return ['ok' => false, 'created' => 0, 'error' => $detail];
            }

            $this->audit->log('integrity.anchored', null, ($automatic ? 'Anclaje automático' : 'Anclaje manual')." de cadenas ({$created})", [
                'anchors' => $created,
                'automatic' => $automatic,
                'disk' => (string) config('integrity.anchor_disk', 'anchors'),
            ], $organization->id);

            return ['ok' => true, 'created' => $created, 'error' => null];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return bool true si la alerta es nueva en el día
     */
    private function raise(Organization $organization, string $rule, string $message, array $data): bool
    {
        $today = CarbonImmutable::now($organization->timezone ?: 'America/Mexico_City')->toDateString();
        $exists = ComplianceAlert::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNull('employee_id')
            ->whereDate('date', $today)
            ->where('rule_code', $rule)
            ->exists();
        if ($exists) {
            return false;
        }

        ComplianceAlert::query()->create([
            'organization_id' => $organization->id,
            'employee_id' => null,
            'date' => $today,
            'rule_code' => $rule,
            'severity' => AlertSeverity::Critical,
            'message' => $message,
            'legal_basis' => 'LFT art. 132 fr. XXXIV (registro inalterable)',
            'data' => $data,
            'status' => 'open',
        ]);

        return true;
    }
}
