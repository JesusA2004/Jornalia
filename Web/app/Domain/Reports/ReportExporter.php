<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\IntegrityVerifier;
use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Dataset\ReportHeaderFactory;
use App\Domain\Reports\Definition\ReportDefinition;
use App\Domain\Reports\Layouts\ReportLayoutRegistry;
use App\Domain\Reports\Rendering\CsvRenderer;
use App\Domain\Reports\Rendering\PdfRenderer;
use App\Domain\Reports\Rendering\ReportRenderer;
use App\Domain\Reports\Rendering\XlsxRenderer;
use App\Models\IntegrityAnchor;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Orquesta una exportación: arma el dataset (datos + encabezado institucional)
 * una sola vez y lo entrega al renderizador del formato pedido con el layout
 * configurado. Registra ruta, huella del archivo, huella de los datos y filas.
 */
final class ReportExporter
{
    public function __construct(
        private readonly ReportSources $sources,
        private readonly ReportHeaderFactory $headers,
        private readonly ReportLayoutRegistry $layouts,
        private readonly IntegrityVerifier $verifier,
    ) {}

    /**
     * Dataset de un reporte para un usuario (mismo alcance en vista previa y exportación).
     */
    public function dataset(ReportDefinition $definition, ReportParameters $params, User $user, Organization $organization, ?string $folio = null, bool $withIntegrity = true): ReportDataset
    {
        $notes = [];
        if ($definition->builder === 'noi') {
            $notes[] = 'Layout de movimientos para captura en NOI; las claves de concepto se configuran en config/reports.php o en el ajuste «reports.noi» de la organización. Este archivo no modifica NOI.';
        }
        if (in_array($definition->key, ['asistencia_detallada', 'asistencia_individual', 'descansos'], true)) {
            if ((int) config('reports.meal_pause', 0) === 0) {
                $notes[] = 'La organización no ha definido qué pausa corresponde a la comida: las pausas registradas se muestran como descanso.';
            }
        }

        return new ReportDataset(
            definition: $definition,
            header: $this->headers->make($definition, $params, $user, $organization, $folio),
            source: $this->sources->source($definition, $params, $user),
            integrity: $withIntegrity && ($definition->options['integrity'] ?? false) ? $this->integrity($organization->id) : null,
            notes: $notes,
        );
    }

    public function generate(ReportExport $export, User $user, Organization $organization): ReportExport
    {
        $definition = ReportCatalog::definition($export->report) ?? throw new InvalidArgumentException('Reporte desconocido.');
        $params = ReportParameters::fromArray((array) $export->parameters, (string) ($organization->timezone ?: 'UTC'), $definition->maxDays);
        $export->forceFill(['status' => 'processing'])->save();

        $dataset = $this->dataset($definition, $params, $user, $organization, $export->folio);
        $dir = "reports/{$organization->id}";
        $path = "{$dir}/{$export->uuid}.{$export->format}";
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);
        $absolute = $disk->path($path);

        $this->renderer((string) $export->format)->render($dataset, $this->layouts->for($definition), $absolute);

        $export->forceFill([
            'status' => 'completed',
            'path' => $path,
            'sha256' => hash_file('sha256', $absolute),
            'row_count' => $dataset->rowCount(),
            'completed_at' => now(),
            'parameters' => array_merge((array) $export->parameters, ['data_sha256' => $dataset->dataSha256()]),
        ])->save();

        return $export;
    }

    public function renderer(string $format): ReportRenderer
    {
        return match ($format) {
            'pdf' => app(PdfRenderer::class),
            'xlsx' => app(XlsxRenderer::class),
            'csv' => app(CsvRenderer::class),
            default => throw new InvalidArgumentException("Formato no soportado: {$format}"),
        };
    }

    /**
     * Verificación de las cadenas de integridad al momento de generar.
     *
     * @return array{chains: list<array<string, mixed>>, anchors: list<array<string, mixed>>, verified_at: string}
     */
    public function integrity(int $organizationId): array
    {
        $chains = [];
        foreach (['punches' => 'Marcajes originales', 'adjustments' => 'Ajustes por rectificación'] as $chain => $label) {
            $chains[] = ['label' => $label] + $this->verifier->verify($organizationId, $chain);
        }
        $anchors = IntegrityAnchor::query()->withoutGlobalScopes()->where('organization_id', $organizationId)
            ->orderByDesc('created_at')->limit(5)->get()
            ->map(fn ($a) => ['chain' => $a->chain, 'sequence' => (int) $a->sequence, 'hash' => $a->hash, 'method' => $a->method, 'created_at' => $a->created_at?->utc()->format('Y-m-d H:i:s').' UTC'])
            ->all();

        return ['chains' => $chains, 'anchors' => $anchors, 'verified_at' => now('UTC')->format('Y-m-d H:i:s').' UTC'];
    }
}
