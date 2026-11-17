<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reports\GenerateReportExport;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ExportReportRequest;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\ReportExport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes: catálogo, vista previa paginada y exportación real (xlsx/pdf/csv)
 * registrada en `report_exports` con su huella sha256.
 */
final class ReportController extends Controller
{
    /** Exportaciones hasta este tamaño se generan en la misma petición. */
    private const SYNC_MAX_ROWS = 2000;

    public function __construct(
        private readonly OperacionScope $scope,
        private readonly CurrentOrganization $current,
    ) {}

    public function index(ListFiltersRequest $request): Response
    {
        $user = $request->user();

        return Inertia::render('reportes/Index', [
            'catalog' => array_values(array_map(fn ($d) => [
                'key' => $d['key'],
                'title' => $d['title'],
                'description' => $d['description'],
                'category' => $d['category'],
                'category_label' => $d['category_label'],
                'columns' => count($d['columns']),
                'featured' => $d['key'] === 'inspeccion_laboral',
            ], ReportCatalog::all())),
            'exports' => $this->recentExports($request),
            'can' => ['export' => $user->can('reports.export')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function show(ListFiltersRequest $request, ReportQuery $queries, string $report): Response
    {
        $definition = ReportCatalog::find($report);
        abort_if($definition === null, 404);
        $user = $request->user();
        $filters = $request->filters();
        [$from, $to] = $this->scope->period($filters['from'] ?? null, $filters['to'] ?? null, 7, 400);

        $params = ReportParameters::fromArray([...$filters, 'from' => $from->toDateString(), 'to' => $to->toDateString()], $this->scope->timezone());
        $this->assertFiltersInScope($request, $params);
        ['query' => $query, 'map' => $map] = $queries->build($report, $params, $user);

        $page = (clone $query)->paginate($request->perPage(25))->withQueryString();
        $page->through(fn ($r) => $map($r));

        $totals = [];
        $sumColumns = array_values(array_filter($definition['columns'], fn ($c) => ! empty($c['sum'])));
        if ($sumColumns !== [] && $page->total() <= 20000) {
            foreach ((clone $query)->lazy(1000) as $raw) {
                $row = $map($raw);
                foreach ($sumColumns as $c) {
                    $totals[$c['key']] = ($totals[$c['key']] ?? 0) + (int) ($row[$c['key']] ?? 0);
                }
            }
        }

        return Inertia::render('reportes/Show', [
            'report' => $definition,
            'rows' => $page,
            'totals' => $totals,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'department_id' => isset($filters['department_id']) ? (string) $filters['department_id'] : null,
                'employee_id' => isset($filters['employee_id']) ? (string) $filters['employee_id'] : null,
                'status' => $filters['status'] ?? null,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'departments' => $this->scope->departmentOptions(),
                'employees' => $this->scope->employeeOptions($user),
                'statuses' => $definition['status_options'] ?? [],
            ],
            'exports' => $this->recentExports($request, $report),
            'can' => ['export' => $user->can('reports.export')],
            'timezone' => $this->scope->timezone(),
        ]);
    }

    public function export(ExportReportRequest $request, ReportQuery $queries, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $organization = $this->current->require();
        $params = ReportParameters::fromArray($data, $this->scope->timezone());
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
        $audit->log('report.requested', $export, 'Solicitó exportación de '.ReportCatalog::find($data['report'])['title'].' ('.strtoupper($data['format']).')', $params->toArray());

        $estimate = $queries->build($data['report'], $params, $user)['query']->getCountForPagination();
        $job = new GenerateReportExport($export->id, $organization->id, $user->id);
        if ($estimate <= self::SYNC_MAX_ROWS) {
            dispatch_sync($job);
        } else {
            dispatch($job);
        }

        $export->refresh();
        Inertia::flash('toast', match ($export->status) {
            'completed' => ['type' => 'success', 'message' => 'Reporte generado. Ya puedes descargarlo.'],
            'failed' => ['type' => 'error', 'message' => 'No fue posible generar el reporte: '.$export->error],
            default => ['type' => 'info', 'message' => "Generando reporte de {$estimate} registros; aparecerá en «Exportaciones recientes» al terminar."],
        });

        return back();
    }

    public function download(ListFiltersRequest $request, AuditLogger $audit, int $export): StreamedResponse
    {
        $user = $request->user();
        $model = ReportExport::query()->findOrFail($export);
        abort_unless($model->user_id === $user->id || $user->is_super_admin, 404);
        abort_unless($model->status === 'completed' && $model->path && Storage::disk('local')->exists($model->path), 404);

        $audit->log('report.downloaded', $model, 'Descargó reporte '.$model->report.' ('.$model->format.')', ['sha256' => $model->sha256]);
        $name = Str::slug(ReportCatalog::find($model->report)['title'] ?? $model->report).'-'.substr((string) $model->uuid, 0, 8).'.'.$model->format;

        return Storage::disk('local')->download($model->path, $name, [
            'Content-Type' => match ($model->format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf' => 'application/pdf',
                default => 'text/csv; charset=UTF-8',
            },
            'X-Report-Id' => (string) $model->uuid,
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
     * @return list<array<string, mixed>>
     */
    private function recentExports(ListFiltersRequest $request, ?string $report = null): array
    {
        return ReportExport::query()->where('user_id', $request->user()->id)
            ->when($report, fn ($q) => $q->where('report', $report))
            ->latest()->limit(10)->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'report' => $e->report,
                'title' => ReportCatalog::find($e->report)['title'] ?? $e->report,
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
