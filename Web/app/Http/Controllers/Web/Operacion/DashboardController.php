<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Dashboard\DashboardMetrics;
use App\Domain\Dashboard\PersonalSummary;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel ejecutivo. Con `dashboard.view` muestra indicadores del alcance del
 * usuario; sin ese permiso, sólo el resumen personal del colaborador.
 */
final class DashboardController extends Controller
{
    public function __invoke(ListFiltersRequest $request, DashboardMetrics $metrics, PersonalSummary $personal, OperacionScope $scope): Response|RedirectResponse
    {
        $user = $request->user();
        $canView = $user->can('dashboard.view');
        $preset = in_array($request->query('period'), ['today', '7d', '30d', 'custom'], true) ? (string) $request->query('period') : '7d';

        $today = $scope->today();
        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            '30d' => [$today->subDays(29), $today],
            'custom' => $scope->period($request->validated('from'), $request->validated('to'), 7, 366),
            default => [$today->subDays(6), $today],
        };

        $branchId = $request->validated('branch_id') ? (int) $request->validated('branch_id') : null;
        $employee = Employee::query()->where('user_id', $user->id)->first();

        // Sin panel ni expediente (p. ej. autoridad laboral): su inicio útil es Asistencias.
        if (! $canView && $employee === null && $user->can('punches.view')) {
            return redirect()->route('asistencias.index');
        }

        return Inertia::render('Dashboard', [
            'mode' => $canView ? 'executive' : 'personal',
            'filters' => [
                'period' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch_id' => $branchId ? (string) $branchId : null,
            ],
            'branches' => $canView ? $scope->branchOptions($user) : [],
            'metrics' => $canView ? $metrics->build($user, $from, $to, $branchId) : null,
            'personal' => $employee ? $personal->build($employee) : null,
            'timezone' => $scope->timezone(),
        ]);
    }
}
