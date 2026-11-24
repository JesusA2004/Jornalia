<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Integrations\People\PeopleStructureSource;
use App\Domain\Integrations\People\PeopleStructureSynchronizer;
use App\Models\IntegrationRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estado de la integración con Mr. Lana People y sincronización manual
 * autorizada (`integrations.sync`). Sin credenciales: «Sin configurar».
 */
class IntegrationController extends AdminController
{
    /** Orígenes de `integration_runs` que pertenecen a People. */
    private const SOURCES = [PeopleStructureSynchronizer::RUN_SOURCE, 'people_api', 'csv'];

    public function index(Request $request, PeopleStructureSource $source): Response
    {
        $runs = IntegrationRun::query()->with('startedBy:id,name')->whereIn('source', self::SOURCES)
            ->latest('started_at')->limit(20)->get()
            ->map(fn (IntegrationRun $r) => [
                'id' => $r->id,
                'source' => $r->source,
                'kind' => $r->kind,
                'status' => $r->status,
                'stats' => $r->stats ?? [],
                'issues' => array_slice((array) ($r->conflicts ?? []), 0, 50),
                'issues_count' => count((array) ($r->conflicts ?? [])),
                'started_by' => $r->startedBy?->name,
                'started_at' => $r->started_at->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
            ]);

        $lastSuccess = IntegrationRun::query()->where('source', PeopleStructureSynchronizer::RUN_SOURCE)
            ->whereIn('status', ['completed', 'partial'])->latest('started_at')->value('finished_at');

        return Inertia::render('integraciones/Index', [
            'people' => [
                'configured' => $source->isConfigured(),
                'last_success_at' => $lastSuccess ? \Illuminate\Support\Carbon::parse($lastSuccess)->toIso8601String() : null,
            ],
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
}
