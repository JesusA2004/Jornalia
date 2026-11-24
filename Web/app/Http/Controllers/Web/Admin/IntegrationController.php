<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Integrations\People\PeopleIncidenceReceiver;
use App\Domain\Integrations\People\PeopleIncidenceSource;
use App\Domain\Integrations\People\PeopleStructureSource;
use App\Domain\Integrations\People\PeopleStructureSynchronizer;
use App\Models\IntegrationRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estado de la integración con Mr. Lana People y sincronización manual
 * autorizada (`integrations.sync`). Sin credenciales: «Sin configurar».
 *
 * Muestra las ejecuciones de estructura y bajas, colaboradores y la recepción
 * de incidencias, permisos y vacaciones aprobados en People.
 */
class IntegrationController extends AdminController
{
    /** Orígenes de `integration_runs` que pertenecen a People. */
    private const SOURCES = [
        PeopleStructureSynchronizer::RUN_SOURCE,
        PeopleIncidenceReceiver::RUN_SOURCE,
        'people_api',
        'csv',
    ];

    public function index(Request $request, PeopleStructureSource $source, PeopleIncidenceSource $incidenceSource, PeopleIncidenceReceiver $incidences): Response
    {
        $runs = IntegrationRun::query()->with('startedBy:id,name')->whereIn('source', self::SOURCES)
            ->latest('started_at')->limit(20)->get()
            ->map(fn (IntegrationRun $r) => [
                'id' => $r->id,
                'source' => $r->source,
                'kind' => $r->kind,
                'status' => $r->status,
                'stats' => $r->stats ?? [],
                'issues' => array_slice(self::issues($r), 0, 50),
                'issues_count' => count(self::issues($r)),
                'started_by' => $r->startedBy?->name,
                'started_at' => $r->started_at->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
            ]);

        $lastSuccess = IntegrationRun::query()->where('source', PeopleStructureSynchronizer::RUN_SOURCE)
            ->whereIn('status', ['completed', 'partial'])->latest('started_at')->value('finished_at');

        return Inertia::render('integraciones/Index', [
            'people' => [
                'configured' => $source->isConfigured(),
                'last_success_at' => $lastSuccess ? Carbon::parse($lastSuccess)->toIso8601String() : null,
            ],
            'people_incidences' => $incidences->status($incidenceSource),
            'runs' => $runs,
            'can' => ['sync' => $request->user()?->can('integrations.sync') ?? false],
        ]);
    }

    public function sync(Request $request, PeopleStructureSource $source, PeopleStructureSynchronizer $synchronizer): RedirectResponse
    {
        $dryRun = $request->boolean('dry_run');
        $run = $synchronizer->run($this->actor($request), $source, $dryRun);

        $stats = $run->stats ?? [];
        $message = match ($run->status) {
            'failed' => $source->isConfigured() ? 'No se pudo sincronizar con People. Revisa el detalle.' : 'People sin configurar.',
            'simulated' => 'Simulación lista: no se guardó ningún cambio.',
            'partial' => sprintf('Sincronizado con %d error(es). Revisa el detalle.', $stats['errors'] ?? 0),
            default => 'Sincronización completa.',
        };
        $this->toast($message, in_array($run->status, ['failed', 'partial'], true) ? 'warning' : 'success');

        return back();
    }

    /**
     * Hallazgos de la ejecución con un formato común. La recepción de
     * incidencias guarda `{external_id, error}`; la estructura, `{type, message}`.
     *
     * @return list<array<string, mixed>>
     */
    private static function issues(IntegrationRun $run): array
    {
        $issues = [];
        foreach ((array) ($run->conflicts ?? []) as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            if (! isset($issue['message']) && isset($issue['error'])) {
                $issue = [
                    'entity' => 'incidence',
                    'external_id' => $issue['external_id'] ?? null,
                    'type' => 'error',
                    'message' => (string) $issue['error'],
                ];
            }
            $issues[] = $issue;
        }

        return $issues;
    }
}
