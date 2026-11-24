<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reports\Dataset\DatasetRow;
use App\Domain\Reports\Definition\ReportDefinition;
use App\Domain\Reports\GenerateReportExport;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportSources;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ExportReportRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Http\Requests\Web\Operacion\ReportPreviewRequest;
use App\Models\Branch;
use App\Models\Position;
use App\Models\ReportExport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes: catálogo, vista previa (mismo dataset y alcance que la
 * exportación) y exportación PDF/XLSX/CSV registrada en `report_exports` y en
 * la bitácora. Volúmenes grandes se procesan en la cola.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly OperacionScope $scope,
        private readonly CurrentOrganization $current,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();

        return Inertia::render('reportes/Index', [
            'catalog' => array_values(array_map(fn (ReportDefinition $d) => [
                'key' => $d->key,
                'title' => $d->title,
                'description' => $d->description,
                'category' => $d->category,
                'category_label' => $d->categoryLabel,
                'columns' => count($d->columns),
                'featured' => $d->featured,
                'requires_employee' => $d->requiresEmployee,
            ], ReportCatalog::definitions())),
            'exports' => $this->recentExports($request),
            'can' => ['export' => $user->can('reports.export')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function show(ReportPreviewRequest $request, ReportExporter $exporter, string $report): Response
    {
        $definition = ReportCatalog::definition($report);
        abort_if($definition === null, 404);
        $user = $request->user();
        $filters = $request->filters();
        $defaultDays = $definition->key === 'asistencia_diaria' ? 1 : 7;
        [$from, $to] = $this->scope->period($filters['from'] ?? null, $filters['to'] ?? null, $defaultDays, $definition->maxDays ?? 400);

        $params = ReportParameters::fromArray([...$filters, 'from' => $from->toDateString(), 'to' => $to->toDateString()], $this->scope->timezone(), $definition->maxDays);
        $this->assertFiltersInScope($request, $params);

        $perPage = $request->perPage(25);
        $page = max(1, (int) $request->integer('page', 1));
        $needsEmployee = $definition->requiresEmployee && $params->employeeId === null;
        $items = [];
        $total = 0;
        $totals = [];
        $capped = false;

        if (! $needsEmployee) {
            $dataset = $exporter->dataset($definition, $params, $user, $this->current->require(), withIntegrity: false);
            $limit = (int) config('reports.preview_max_rows', 5000);
            $offset = ($page - 1) * $perPage;
            foreach ($dataset->rows() as $row) {
                if ($row->kind !== DatasetRow::ROW) {
                    continue;
                }
                if ($total >= $offset && $total < $offset + $perPage) {
                    $items[] = array_filter($row->values, fn ($v, $k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH);
                }
                $total++;
                if ($total >= $limit) {
                    $capped = true;
                    break;
                }
            }
            if (! $capped) {
                $totals = $dataset->totals();
            }
        }

        $rows = new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('reportes/Show', [
            'report' => $definition->toArray(),
            'rows' => $rows,
            'totals' => $totals,
            'capped' => $capped,
            'needs_employee' => $needsEmployee,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'position_id' => isset($filters['position_id']) ? (string) $filters['position_id'] : null,
                'region' => $filters['region'] ?? null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
                'period_unit' => $filters['period_unit'] ?? null,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'positions' => Position::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Position $p) => ['value' => (string) $p->id, 'label' => (string) $p->name])->values()->all(),
                'regions' => $this->regionOptions($request),
                'employees' => $this->scope->employeeOptions($user),
                'statuses' => $definition->statusOptions,
                'period_units' => [
                    ['value' => 'week', 'label' => 'Semanal'],
                    ['value' => 'fortnight', 'label' => 'Quincenal'],
                    ['value' => 'month', 'label' => 'Mensual'],
                ],
            ],
            'exports' => $this->recentExports($request, $report),
            'can' => ['export' => $user->can('reports.export')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function export(ExportReportRequest $request, ReportSources $sources, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $organization = $this->current->require();
        $definition = ReportCatalog::definition($data['report']);
        abort_if($definition === null, 404);
        $params = ReportParameters::fromArray($data, $this->scope->timezone(), $definition->maxDays);
        $this->assertFiltersInScope($request, $params);

        $export = ReportExport::query()->create([
            'uuid' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'report' => $data['report'],
            'format' => $data['format'],
            'parameters' => $params->toArray(),
            'status' => 'queued',
        ]);
        $export->forceFill(['folio' => sprintf('REP-%s-%06d', now()->format('Y'), $export->id)])->save();
        $audit->log('report.requested', $export, "Solicitó {$export->folio}: {$definition->title} (".strtoupper($data['format']).')', [
            'folio' => $export->folio, 'report' => $definition->key, 'format' => $data['format'], ...$params->toArray(),
        ]);

        $estimate = $sources->estimate($definition, $params, $user);
        $job = new GenerateReportExport($export->id, $organization->id, $user->id);
        if ($estimate <= (int) config('reports.sync_max_rows', 2000)) {
            dispatch_sync($job);
        } else {
            dispatch($job);
        }

        $export->refresh();
        Inertia::flash('toast', match ($export->status) {
            'completed' => ['type' => 'success', 'message' => "Reporte {$export->folio} listo para descargar."],
            'failed' => ['type' => 'error', 'message' => 'No fue posible generar el reporte: '.$export->error],
            default => ['type' => 'info', 'message' => "Generando {$export->folio} en segundo plano; aparecerá en «Exportaciones» al terminar."],
        });

        return back();
    }

    public function download(ListFiltersRequest $request, AuditLogger $audit, int $export): StreamedResponse
    {
        $user = $request->user();
        $model = ReportExport::query()->findOrFail($export);
        abort_unless($model->user_id === $user->id || $user->is_super_admin, 404);
        abort_unless($model->status === 'completed' && $model->path && Storage::disk('local')->exists($model->path), 404);

        $audit->log('report.downloaded', $model, "Descargó {$model->folio}: {$model->report} ({$model->format})", ['folio' => $model->folio, 'sha256' => $model->sha256]);
        $name = Str::slug(ReportCatalog::definition($model->report)?->title ?? $model->report).'-'.($model->folio ?? substr((string) $model->uuid, 0, 8)).'.'.$model->format;

        return Storage::disk('local')->download($model->path, $name, [
            'Content-Type' => match ($model->format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf' => 'application/pdf',
                default => 'text/csv; charset=UTF-8',
            },
            'X-Report-Id' => (string) $model->uuid,
            'X-Report-Folio' => (string) $model->folio,
            'X-Report-Sha256' => (string) $model->sha256,
        ]);
    }

    /**
     * Un colaborador indicado debe estar en el alcance del usuario.
     */
    private function assertFiltersInScope(ListFiltersRequest|ExportReportRequest $request, ReportParameters $params): void
    {
        if ($params->employeeId !== null) {
            abort_unless($this->scope->canAccessEmployee($request->user(), $params->employeeId), 404);
        }
    }

    /**
     * Regiones de las sucursales dentro del alcance del usuario.
     *
     * @return list<array{value:string,label:string}>
     */
    private function regionOptions(ListFiltersRequest $request): array
    {
        $ids = array_map(fn ($b) => (int) $b['value'], $this->scope->branchOptions($request->user()));

        return Branch::query()->whereKey($ids)->whereNotNull('region')->where('region', '!=', '')
            ->distinct()->orderBy('region')->pluck('region')
            ->map(fn ($r) => ['value' => (string) $r, 'label' => (string) $r])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentExports(ListFiltersRequest $request, ?string $report = null): array
    {
        return ReportExport::query()->where('user_id', $request->user()->id)
            ->when($report, fn ($q) => $q->where('report', $report))
            ->latest('id')->limit(10)->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'folio' => $e->folio,
                'report' => $e->report,
                'title' => ReportCatalog::definition($e->report)?->title ?? $e->report,
                'format' => $e->format,
                'status' => $e->status,
                'row_count' => $e->row_count,
                'sha256' => $e->sha256,
                'error' => $e->error,
                'parameters' => $e->parameters,
                'created_at' => OperacionScope::iso($e->created_at),
                'completed_at' => OperacionScope::iso($e->completed_at),
                'download_url' => $e->status === 'completed' ? route('reportes.download', $e->id) : null,
            ])->values()->all();
    }
}
