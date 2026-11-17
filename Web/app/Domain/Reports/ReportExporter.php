<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\IntegrityVerifier;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IntegrityAnchor;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Properties as XlsxProperties;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Genera el archivo de un ReportExport (xlsx, csv o pdf) a partir de
 * ReportQuery, con encabezado de trazabilidad: empresa, periodo, parámetros,
 * responsable, fecha de generación, totales, observaciones, identificador
 * (uuid) y huella sha256 del contenido de datos.
 */
final class ReportExporter
{
    /** Límite de filas para PDF (documento legible); Excel/CSV no tienen límite. */
    public const PDF_MAX_ROWS = 3000;

    public function __construct(
        private readonly ReportQuery $queries,
        private readonly IntegrityVerifier $verifier,
    ) {}

    public function generate(ReportExport $export, User $user, Organization $organization): ReportExport
    {
        $definition = ReportCatalog::find($export->report) ?? throw new \InvalidArgumentException('Reporte desconocido.');
        $params = ReportParameters::fromArray((array) $export->parameters, (string) ($organization->timezone ?: 'UTC'));
        ['query' => $query, 'map' => $map] = $this->queries->build($export->report, $params, $user);

        $export->forceFill(['status' => 'processing'])->save();

        $columns = $definition['columns'];
        $sums = [];
        $dataHash = hash_init('sha256');
        $count = 0;
        $rows = [];

        $dir = "reports/{$organization->id}";
        $path = "{$dir}/{$export->uuid}.{$export->format}";
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);
        $absolute = $disk->path($path);

        $writer = null;
        $csv = null;
        if ($export->format === 'xlsx') {
            $writer = new XlsxWriter(new XlsxOptions(properties: new XlsxProperties(
                title: $definition['title'],
                subject: (string) $export->uuid,
                application: 'MR LANA Asistencias',
                creator: (string) $organization->name,
                lastModifiedBy: (string) $user->name,
            )));
            $writer->openToFile($absolute);
            $writer->getCurrentSheet()->setName('Datos');
            $header = new Style(fontBold: true, fontColor: Color::WHITE, backgroundColor: '1F2937');
            $writer->addRow(Row::fromValuesWithStyle(array_map(fn ($c) => $c['label'], $columns), $header));
        } elseif ($export->format === 'csv') {
            $csv = fopen($absolute, 'wb');
            fwrite($csv, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
            fputcsv($csv, array_map(fn ($c) => $c['label'], $columns), escape: '\\');
        }

        foreach ($query->lazy(500) as $raw) {
            $row = $map($raw);
            $cells = [];
            foreach ($columns as $c) {
                $value = $row[$c['key']] ?? null;
                if (! empty($c['sum'])) {
                    $sums[$c['key']] = ($sums[$c['key']] ?? 0) + (int) $value;
                }
                $cells[] = $this->format($value, $c['type'], $params->timezone, $export->format === 'xlsx');
            }
            hash_update($dataHash, json_encode($cells, JSON_UNESCAPED_UNICODE)."\n");
            $count++;

            if ($writer) {
                $writer->addRow(Row::fromValues($cells));
            } elseif ($csv) {
                fputcsv($csv, array_map(fn ($v) => (string) $v, $cells), escape: '\\');
            } elseif ($count <= self::PDF_MAX_ROWS) {
                $rows[] = $cells;
            }
        }

        $dataSha = hash_final($dataHash);
        $totals = [];
        foreach ($columns as $c) {
            if (! empty($c['sum'])) {
                $totals[] = ['label' => $c['label'], 'value' => $this->format($sums[$c['key']] ?? 0, $c['type'], $params->timezone, false)];
            }
        }
        $integrity = $export->report === 'inspeccion_laboral' ? $this->integrity($organization->id) : null;
        $meta = $this->meta($export, $definition, $params, $user, $organization, $count, $totals, $dataSha, $integrity);

        if ($writer) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('Información');
            $bold = new Style(fontBold: true);
            foreach ($meta['lines'] as [$label, $value]) {
                $writer->addRow(Row::fromValuesWithStyles([$label, $value], [0 => $bold]));
            }
            if ($integrity) {
                $writer->addRow(Row::fromValues(['', '']));
                $writer->addRow(Row::fromValuesWithStyle(['Verificación de integridad', ''], $bold));
                foreach ($integrity['chains'] as $chain) {
                    $writer->addRow(Row::fromValues([$chain['label'], ($chain['valid'] ? 'Íntegra' : 'Con hallazgos')." · {$chain['checked']} registros · secuencia {$chain['head_sequence']} · anclas {$chain['anchors_checked']}"]));
                    $writer->addRow(Row::fromValues(['Hash de cabeza', $chain['head_hash']]));
                    foreach (array_slice($chain['errors'], 0, 50) as $e) {
                        $writer->addRow(Row::fromValues(['Hallazgo', "#{$e['sequence']}: {$e['error']}"]));
                    }
                }
            }
            $writer->close();
        } elseif ($csv) {
            fclose($csv);
        } else {
            $pdf = Pdf::loadView('reports.document', [
                'meta' => $meta,
                'definition' => $definition,
                'columns' => $columns,
                'rows' => $rows,
                'truncated' => $count > self::PDF_MAX_ROWS,
                'maxRows' => self::PDF_MAX_ROWS,
                'integrity' => $integrity,
                'logo' => $this->logo($organization),
            ])->setPaper('letter', $definition['landscape'] ? 'landscape' : 'portrait');
            $disk->put($path, $pdf->output());
        }

        $export->forceFill([
            'status' => 'completed',
            'path' => $path,
            'sha256' => hash_file('sha256', $absolute),
            'row_count' => $count,
            'completed_at' => now(),
            'parameters' => array_merge((array) $export->parameters, ['data_sha256' => $dataSha]),
        ])->save();

        return $export;
    }

    /**
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

    public function format(mixed $value, string $type, string $tz, bool $native): mixed
    {
        if ($value === null || $value === '') {
            return $native && in_array($type, ['number', 'minutes'], true) ? 0 : '';
        }

        return match ($type) {
            'minutes' => sprintf('%s%d:%02d', (int) $value < 0 ? '-' : '', intdiv(abs((int) $value), 60), abs((int) $value) % 60),
            'number' => $native ? (int) $value : (string) $value,
            'percent' => number_format((float) $value, 1, '.', '').' %',
            'date' => CarbonImmutable::parse((string) $value)->format('d/m/Y'),
            'datetime' => CarbonImmutable::parse((string) $value)->setTimezone($tz)->format('d/m/Y H:i:s'),
            'time' => CarbonImmutable::parse((string) $value)->setTimezone($tz)->format('H:i'),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array{label:string,value:mixed}>  $totals
     * @param  array<string, mixed>|null  $integrity
     * @return array<string, mixed>
     */
    private function meta(ReportExport $export, array $definition, ReportParameters $params, User $user, Organization $organization, int $count, array $totals, string $dataSha, ?array $integrity): array
    {
        $filters = $this->describeFilters($params);
        $generated = now()->setTimezone($params->timezone);

        $lines = [
            ['Empresa', (string) $organization->name],
            ['Reporte', $definition['title']],
            ['Periodo', $params->from->format('d/m/Y').' – '.$params->to->format('d/m/Y')],
            ['Parámetros', $filters ?: 'Sin filtros adicionales'],
            ['Responsable', $user->name.' <'.$user->email.'>'],
            ['Generado', $generated->format('d/m/Y H:i:s').' ('.$params->timezone.')'],
            ['Registros', (string) $count],
            ...array_map(fn ($t) => ['Total '.mb_strtolower($t['label']), (string) $t['value']], $totals),
            ['Observaciones', $params->observations ?? '—'],
            ['Identificador', (string) $export->uuid],
            ['SHA-256 de los datos', $dataSha],
        ];
        if ($integrity) {
            $lines[] = ['Integridad verificada', $integrity['verified_at']];
        }

        return [
            'organization' => (string) $organization->name,
            'title' => $definition['title'],
            'period' => $params->from->format('d/m/Y').' – '.$params->to->format('d/m/Y'),
            'filters' => $filters,
            'responsible' => $user->name,
            'generated_at' => $generated->format('d/m/Y H:i:s'),
            'timezone' => $params->timezone,
            'count' => $count,
            'totals' => $totals,
            'observations' => $params->observations,
            'uuid' => (string) $export->uuid,
            'data_sha256' => $dataSha,
            'lines' => $lines,
        ];
    }

    private function describeFilters(ReportParameters $p): string
    {
        $parts = [];
        if ($p->branchId) {
            $parts[] = 'Sucursal: '.(Branch::query()->whereKey($p->branchId)->value('name') ?? "#{$p->branchId}");
        }
        if ($p->departmentId) {
            $parts[] = 'Departamento: '.(Department::query()->whereKey($p->departmentId)->value('name') ?? "#{$p->departmentId}");
        }
        if ($p->employeeId) {
            $e = Employee::query()->find($p->employeeId);
            $parts[] = 'Colaborador: '.($e ? $e->fullName().' ('.$e->employee_number.')' : "#{$p->employeeId}");
        }
        if ($p->status) {
            $parts[] = 'Estado: '.$p->status;
        }

        return implode(' · ', $parts);
    }

    /**
     * Logotipo de la organización como data URI (sólo si existe); si no, se usa el nombre.
     */
    private function logo(Organization $organization): ?string
    {
        $path = $organization->brand_logo_path;
        if (! $path) {
            return null;
        }
        foreach (['public', 'local'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';
                if (! str_starts_with((string) $mime, 'image/')) {
                    return null;
                }

                return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk($disk)->get($path));
            }
        }

        return null;
    }
}
