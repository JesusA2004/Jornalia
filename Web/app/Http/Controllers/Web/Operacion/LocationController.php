<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Location\LocationTracking;
use App\Enums\PunchType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\AttendancePresenter;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\Branch;
use App\Models\Geofence;
use App\Models\LocationPoint;
use App\Models\Punch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ubicación en tiempo real: última posición de cada colaborador visible y
 * recorrido de un día (puntos de ubicación + marcajes superpuestos).
 */
final class LocationController extends Controller
{
    /** Minutos sin reporte para considerar "sin señal". */
    public const STALE_MINUTES = 15;

    /** Ventana de posiciones "vivas" en el mapa. */
    public const LIVE_HOURS = 24;

    public function __construct(private readonly OperacionScope $scope) {}

    public function __invoke(ListFiltersRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $status = in_array($filters['status'] ?? null, ['on_shift', 'stale', 'off_shift'], true) ? $filters['status'] : null;
        $trackEmployee = isset($filters['employee_id']) ? (int) $filters['employee_id'] : null;
        $trackDate = is_string($request->query('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1
            ? (string) $request->query('date') : $this->scope->today()->toDateString();

        return Inertia::render('ubicacion/Index', [
            'markers' => fn () => $this->markers($request, $filters, $status),
            'track' => fn () => $trackEmployee ? $this->track($request, $trackEmployee, $trackDate) : null,
            'branches' => fn () => $this->branchPins($request),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'branch_id' => isset($filters['branch_id']) ? (string) $filters['branch_id'] : null,
                'status' => $status,
                'employee_id' => $trackEmployee ? (string) $trackEmployee : null,
                'date' => $trackDate,
            ],
            'options' => [
                'branches' => $this->scope->branchOptions($user),
                'employees' => $this->scope->employeeOptions($user),
                'statuses' => [
                    ['value' => 'on_shift', 'label' => 'En turno'],
                    ['value' => 'stale', 'label' => 'Sin señal'],
                    ['value' => 'off_shift', 'label' => 'Fuera de turno'],
                ],
            ],
            'stale_minutes' => self::STALE_MINUTES,
            // Reglas de uso (Configuración › Ubicación): retención y corte por salida omitida.
            'tracking' => app(LocationTracking::class)->settings(),
            'server_time' => OperacionScope::iso(now()),
            'timezone' => $this->scope->timezone(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function markers(ListFiltersRequest $request, array $filters, ?string $status): array
    {
        $user = $request->user();
        $since = now()->subHours(self::LIVE_HOURS);

        $employees = $this->scope->employees($user)->where('employees.status', 'active')
            ->whereNotNull('employees.last_location_at')->where('employees.last_location_at', '>=', $since);
        $this->scope->apply($employees, $user, array_intersect_key($filters, array_flip(['branch_id', 'search'])), 'employees.id');
        $ids = $employees->limit(2000)->pluck('employees.id');
        if ($ids->isEmpty()) {
            return [];
        }

        $latest = LocationPoint::query()->whereIn('employee_id', $ids)->where('captured_at', '>=', $since)
            ->toBase()->select('employee_id', DB::raw('MAX(captured_at) as at'))->groupBy('employee_id');
        $points = LocationPoint::query()
            ->joinSub($latest, 'lp', fn ($j) => $j->on('lp.employee_id', '=', 'location_points.employee_id')->on('lp.at', '=', 'location_points.captured_at'))
            ->get(['location_points.*'])->unique('employee_id');

        // Sesión abierta = último marcaje de las últimas 20 h distinto de salida.
        $lastPunch = Punch::query()->whereIn('employee_id', $points->pluck('employee_id'))
            ->where('server_time', '>=', now()->subHours(20))
            ->orderBy('server_time')->get(['employee_id', 'type', 'server_time'])
            ->groupBy('employee_id')->map(fn ($g) => $g->last());

        $cards = $this->scope->employeeCards($points->pluck('employee_id'));
        $staleBefore = now()->subMinutes(self::STALE_MINUTES);

        return $points->map(function (LocationPoint $p) use ($cards, $lastPunch, $staleBefore) {
            $punch = $lastPunch[$p->employee_id] ?? null;
            $onShift = $punch !== null && $punch->type !== PunchType::ClockOut;
            $stale = $p->captured_at->lt($staleBefore);
            $state = $stale ? 'stale' : ($onShift ? 'on_shift' : 'off_shift');

            return [
                'employee' => $cards[$p->employee_id] ?? null,
                'employee_id' => $p->employee_id,
                'latitude' => (float) $p->latitude,
                'longitude' => (float) $p->longitude,
                'accuracy_m' => $p->accuracy_m,
                'speed_mps' => $p->speed_mps !== null ? (float) $p->speed_mps : null,
                'heading' => $p->heading !== null ? (float) $p->heading : null,
                'is_mocked' => (bool) $p->is_mocked,
                'app_state' => $p->app_state,
                'captured_at' => OperacionScope::iso($p->captured_at),
                'on_shift' => $onShift,
                'last_punch' => $punch ? ['type' => $punch->type->value, 'type_label' => $punch->type->label(), 'at' => OperacionScope::iso($punch->server_time)] : null,
                'state' => $state,
                'state_label' => match ($state) {
                    'stale' => 'Sin señal',
                    'on_shift' => 'En turno',
                    default => 'Fuera de turno',
                },
            ];
        })->filter(fn ($m) => $status === null || $m['state'] === $status)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function track(ListFiltersRequest $request, int $employeeId, string $date): array
    {
        $employee = $this->scope->employeeOrFail($request->user(), $employeeId);
        $tz = $this->scope->timezone();
        $start = CarbonImmutable::parse($date, $tz)->startOfDay();

        $points = LocationPoint::query()->where('employee_id', $employee->id)
            ->whereBetween('captured_at', [$start->utc(), $start->addDay()->utc()])
            ->orderBy('captured_at')->limit(5000)
            ->get(['latitude', 'longitude', 'accuracy_m', 'speed_mps', 'is_mocked', 'app_state', 'captured_at']);

        $punches = Punch::query()->with('geofence')->where('employee_id', $employee->id)->whereDate('local_date', $date)
            ->orderBy('server_time')->get();

        $distance = 0.0;
        $prev = null;
        foreach ($points as $pt) {
            if ($prev) {
                $distance += $this->haversine((float) $prev->latitude, (float) $prev->longitude, (float) $pt->latitude, (float) $pt->longitude);
            }
            $prev = $pt;
        }

        return [
            'employee' => $this->scope->employeeCards([$employee->id])[$employee->id] ?? null,
            'date' => $date,
            'points' => $points->map(fn ($p) => [
                'latitude' => (float) $p->latitude,
                'longitude' => (float) $p->longitude,
                'accuracy_m' => $p->accuracy_m,
                'speed_mps' => $p->speed_mps !== null ? (float) $p->speed_mps : null,
                'is_mocked' => (bool) $p->is_mocked,
                'captured_at' => OperacionScope::iso($p->captured_at),
            ])->values(),
            'punches' => $punches->map(fn (Punch $p) => AttendancePresenter::punch($p) + [
                'latitude' => $p->latitude !== null ? (float) $p->latitude : null,
                'longitude' => $p->longitude !== null ? (float) $p->longitude : null,
                'accuracy_meters' => $p->accuracy_meters,
                'geofence' => $p->geofence?->name,
            ])->values(),
            'summary' => [
                'points' => $points->count(),
                'distance_m' => (int) round($distance),
                'first_at' => OperacionScope::iso($points->first()?->captured_at),
                'last_at' => OperacionScope::iso($points->last()?->captured_at),
                'mocked' => $points->where('is_mocked', true)->count(),
            ],
        ];
    }

    /**
     * Sucursales con coordenadas y geocercas, como referencia en el mapa.
     *
     * @return list<array<string, mixed>>
     */
    private function branchPins(ListFiltersRequest $request): array
    {
        $ids = array_map(fn ($o) => (int) $o['value'], $this->scope->branchOptions($request->user()));
        $fences = Geofence::query()->whereIn('branch_id', $ids ?: [0])->where('active', true)->get()->groupBy('branch_id');

        return Branch::query()->whereIn('id', $ids ?: [0])->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'latitude' => (float) $b->latitude,
                'longitude' => (float) $b->longitude,
                'geofences' => ($fences[$b->id] ?? collect())->map(fn ($g) => [
                    'name' => $g->name,
                    'latitude' => $g->latitude !== null ? (float) $g->latitude : null,
                    'longitude' => $g->longitude !== null ? (float) $g->longitude : null,
                    'radius_meters' => (int) $g->radius_meters,
                ])->values(),
            ])->values()->all();
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
