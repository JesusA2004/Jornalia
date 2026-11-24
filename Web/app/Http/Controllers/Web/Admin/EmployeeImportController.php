<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Integrations\People\CsvPeopleSource;
use App\Domain\Integrations\People\HttpPeopleSource;
use App\Domain\Integrations\People\PeopleSynchronizer;
use App\Domain\Workforce\EmployeeImportValidator;
use App\Domain\Workforce\ValidatedImportSource;
use App\Models\Employee;
use App\Models\IntegrationRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importación de personal desde CSV y sincronización con People.
 */
class EmployeeImportController extends AdminController
{
    public function __construct(private readonly PeopleSynchronizer $synchronizer) {}

    public function create(Request $request, HttpPeopleSource $people): Response
    {
        $this->authorize('import', Employee::class);

        $runs = IntegrationRun::query()->with('startedBy:id,name')->orderByDesc('started_at')->limit(15)->get();
        $selected = $request->integer('run') ? IntegrationRun::query()->find($request->integer('run')) : $runs->first();

        return Inertia::render('colaboradores/Import', [
            'runs' => $runs->map(fn (IntegrationRun $r) => $this->runData($r, false))->values(),
            'run' => $selected ? $this->runData($selected, true) : null,
            'people' => ['available' => $people->isAvailable()],
            'columns' => collect(CsvPeopleSource::ALIASES)->map(fn ($aliases, $key) => [
                'key' => $key,
                'header' => $aliases[0],
                'required' => in_array($key, ['employee_number', 'first_name', 'last_name', 'starts_on', 'birth_date', 'branch_code', 'position_code', 'schedule_code'], true),
            ])->values(),
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->authorize('import', Employee::class);
        $headers = array_map(fn ($a) => $a[0], CsvPeopleSource::ALIASES);

        return response()->streamDownload(function () use ($headers) {
            echo "\xEF\xBB\xBF".implode(',', $headers)."\n";
        }, 'plantilla_colaboradores.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, EmployeeImportValidator $validator): RedirectResponse
    {
        $this->authorize('import', Employee::class);
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'dry_run' => ['sometimes', 'boolean'],
        ], ['file.mimes' => 'El archivo debe ser CSV.'], ['file' => 'archivo']);

        // Validación por fila (fecha de nacimiento, sucursal, puesto y horario): las filas
        // con errores no se importan y se reportan en la corrida.
        $source = new ValidatedImportSource(new CsvPeopleSource($request->file('file')->getRealPath()), $validator);
        $run = $this->synchronizer->run($this->actor($request), $source, $request->boolean('dry_run'));
        $this->mergeRejected($run, $source->rejected());
        $this->toastFor($run);

        return to_route('colaboradores.import', ['run' => $run->id]);
    }

    public function sync(Request $request, HttpPeopleSource $people): RedirectResponse
    {
        $this->authorize('import', Employee::class);
        if (! $people->isAvailable()) {
            $this->toast('La integración con People no está configurada.', 'warning');

            return back();
        }

        $run = $this->synchronizer->run($this->actor($request), $people, $request->boolean('dry_run'));
        $this->toastFor($run);

        return to_route('colaboradores.import', ['run' => $run->id]);
    }

    /**
     * Agrega a la corrida las filas rechazadas por la validación previa.
     *
     * @param  list<array<string, mixed>>  $rejected
     */
    private function mergeRejected(IntegrationRun $run, array $rejected): void
    {
        if ($rejected === [] || $run->status === 'failed') {
            return;
        }
        $stats = $run->stats ?? [];
        $stats['total'] = (int) ($stats['total'] ?? 0) + count($rejected);
        $stats['conflicts'] = (int) ($stats['conflicts'] ?? 0) + count($rejected);
        $conflicts = array_merge($rejected, array_values($run->conflicts ?? []));
        usort($conflicts, fn ($a, $b) => ($a['line'] ?? 0) <=> ($b['line'] ?? 0));

        $run->forceFill([
            'stats' => $stats,
            'conflicts' => array_slice($conflicts, 0, 500),
            'status' => $run->status === 'simulated' ? 'simulated' : 'partial',
        ])->save();
    }

    private function toastFor(IntegrationRun $run): void
    {
        $s = $run->stats ?? [];
        match ($run->status) {
            'failed' => $this->toast('La importación falló: '.($run->conflicts[0]['message'] ?? 'error desconocido'), 'error'),
            'simulated' => $this->toast("Simulación: se crearían {$s['created']} y se actualizarían {$s['updated']}; {$s['conflicts']} conflictos.", 'info'),
            'partial' => $this->toast("Importación con conflictos: {$s['created']} creados, {$s['updated']} actualizados, {$s['conflicts']} por revisar.", 'warning'),
            default => $this->toast("Importación completa: {$s['created']} creados, {$s['updated']} actualizados."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runData(IntegrationRun $r, bool $withConflicts): array
    {
        return [
            'id' => $r->id,
            'source' => $r->source,
            'kind' => $r->kind,
            'status' => $r->status,
            'stats' => $r->stats ?? [],
            'started_at' => $r->started_at->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'started_by' => $r->startedBy?->name,
            'conflicts' => $withConflicts ? array_values($r->conflicts ?? []) : [],
            'conflicts_count' => count($r->conflicts ?? []),
        ];
    }
}
