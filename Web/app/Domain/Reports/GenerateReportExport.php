<?php

namespace App\Domain\Reports;

use App\Domain\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Genera una exportación de reporte en segundo plano, con el alcance del
 * usuario que la solicitó y la organización en la que se pidió.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $exportId,
        public readonly int $organizationId,
        public readonly int $userId,
    ) {}

    public function handle(ReportExporter $exporter, CurrentOrganization $current, AuditLogger $audit): void
    {
        $organization = Organization::query()->findOrFail($this->organizationId);
        $user = User::query()->findOrFail($this->userId);

        $current->runAs($organization, function () use ($exporter, $organization, $user, $audit) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
            $export = ReportExport::query()->findOrFail($this->exportId);
            if ($export->status === 'completed') {
                return;
            }
            try {
                $exporter->generate($export, $user, $organization);
                $audit->log('report.generated', $export, "Reporte generado: {$export->report} ({$export->format}, {$export->row_count} filas)", ['sha256' => $export->sha256], $organization->id);
            } catch (Throwable $e) {
                report($e);
                $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
        });
    }
}
