<?php

namespace App\Domain\Compliance;

use App\Domain\Attendance\Omissions;
use App\Enums\AlertSeverity;
use App\Models\ComplianceAlert;
use App\Models\Organization;
use App\Models\WorkdaySummary;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;

/**
 * Alerta «Omisión sin rectificar por más de 2 días» (PDF pantallas 14–16, punto 4).
 * El sistema nunca completa el marcaje faltante: sólo avisa para que se
 * solicite la rectificación firmada. La alerta se resuelve sola cuando existe
 * una rectificación en curso o aplicada.
 */
final class OmissionMonitor
{
    public const RULE = 'unrectified_omission';

    /** Días naturales tras los cuales la omisión sin rectificar genera alerta. */
    public const GRACE_DAYS = 2;

    /** Ventana revisada hacia atrás (días). */
    public const LOOKBACK_DAYS = 60;

    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * @return array{opened:int, resolved:int}
     */
    public function evaluate(Organization $organization, ?CarbonImmutable $now = null): array
    {
        return $this->current->runAs($organization, function () use ($organization, $now) {
            $today = ($now ?? CarbonImmutable::now())->setTimezone($organization->timezone ?: 'America/Mexico_City')->startOfDay();
            $cutoff = $today->subDays(self::GRACE_DAYS + 1)->toDateString();
            $from = $today->subDays(self::LOOKBACK_DAYS)->toDateString();

            $pending = Omissions::unrectified(WorkdaySummary::query())
                ->whereBetween('date', [$from, $cutoff])
                ->get(['id', 'employee_id', 'date', 'anomalies']);

            $keys = [];
            $opened = 0;
            foreach ($pending as $day) {
                $date = $day->date->toDateString();
                $keys[$day->employee_id.'|'.$date] = true;
                $alert = ComplianceAlert::query()->where('employee_id', $day->employee_id)->whereDate('date', $date)->where('rule_code', self::RULE)->first();
                $days = (int) CarbonImmutable::parse($date)->diffInDays($today);
                if ($alert === null) {
                    ComplianceAlert::query()->create([
                        'organization_id' => $organization->id,
                        'employee_id' => $day->employee_id,
                        'date' => $date,
                        'rule_code' => self::RULE,
                        'severity' => AlertSeverity::Warning,
                        'message' => "Omisión de checada sin rectificar desde hace {$days} días.",
                        'legal_basis' => 'LFT arts. 132 fr. XXXIV y 784',
                        'data' => ['days' => $days, 'anomalies' => array_values((array) $day->anomalies)],
                        'status' => 'open',
                    ]);
                    $opened++;
                } elseif ($alert->status === 'resolved' && $alert->resolved_by === null) {
                    $alert->update(['status' => 'open', 'resolved_at' => null, 'resolution_note' => null]);
                    $opened++;
                }
            }

            // Se rectificó (o se solicitó la rectificación): la alerta automática se cierra sola.
            $resolved = 0;
            ComplianceAlert::query()->where('rule_code', self::RULE)->where('status', 'open')
                ->whereDate('date', '>=', $from)->get()
                ->each(function (ComplianceAlert $alert) use ($keys, &$resolved) {
                    if (! isset($keys[$alert->employee_id.'|'.$alert->date->toDateString()])) {
                        $alert->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => 'Rectificación solicitada o aplicada.']);
                        $resolved++;
                    }
                });

            return ['opened' => $opened, 'resolved' => $resolved];
        });
    }
}
