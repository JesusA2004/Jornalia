<?php

use App\Enums\PunchType;
use App\Jobs\RecomputeWorkday;
use App\Models\RectificationRequest;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

/*
| Asistencias y Panel (Revisión RH, pantallas 01–03): omisiones de checada,
| origen del marcaje, rectificaciones por firmar y marcajes originales de sólo
| lectura con exportación.
*/

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->org = $this->organization('Comercial Lana');
    $this->admin = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $this->open = $this->employee($this->org, schedule: $this->schedule());
    $this->complete = $this->employee($this->org, schedule: $this->schedule());

    // Martes 17-nov-2026: uno no marca salida; el otro completa su jornada.
    $this->punchAt($this->open, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($this->complete, PunchType::ClockIn, '2026-11-17 15:02:00');
    $this->punchAt($this->complete, PunchType::ClockOut, '2026-11-18 00:05:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 18:00:00', 'UTC'));
    RecomputeWorkday::dispatchSync($this->org->id, $this->open->id, '2026-11-17');
});

function rectificationFor(int $orgId, int $employeeId, string $stage): RectificationRequest
{
    return RectificationRequest::query()->create([
        'organization_id' => $orgId,
        'employee_id' => $employeeId,
        'date' => '2026-11-17',
        'kind' => 'missing_punch',
        'proposed_type' => 'clock_out',
        'proposed_time' => '2026-11-18 00:00:00',
        'reason' => 'Olvidó marcar salida',
        'status' => 'pending',
        'stage' => $stage,
    ]);
}

it('filtra «Sólo omisiones de checada» combinable con otros filtros', function () {
    $this->actingAs($this->admin)->get('/asistencias?from=2026-11-17&to=2026-11-17&omisiones=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('asistencias/Index')
            ->where('filters.omisiones', true)
            ->where('totals.omissions', 1)
            ->missing('totals.auto_clock_outs')
            ->has('workdays.data', 1)
            ->where('workdays.data.0.employee_id', $this->open->id)
            ->where('workdays.data.0.omission', 'missing_clock_out')
            ->where('workdays.data.0.origin.code', 'omission'));

    $this->actingAs($this->admin)->get('/asistencias?from=2026-11-17&to=2026-11-17&omisiones=1&employee_id='.$this->complete->id)
        ->assertInertia(fn (Assert $page) => $page->has('workdays.data', 0));

    $this->actingAs($this->admin)->get('/asistencias?from=2026-11-17&to=2026-11-17')
        ->assertInertia(fn (Assert $page) => $page->has('workdays.data', 2)->where('totals.omissions', 1));
});

it('muestra el origen del marcaje en los marcajes originales', function () {
    $this->actingAs($this->admin)->get('/asistencias?tab=marcajes&from=2026-11-17&to=2026-11-17')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'marcajes')
            ->has('punches.data', 3)
            ->where('punches.data.0.origin.code', 'web')
            ->has('punches.data.0.received_at'));
});

it('exporta los marcajes originales en CSV (sólo lectura)', function () {
    $response = $this->actingAs($this->admin)->get('/asistencias/marcajes/exportar?from=2026-11-17&to=2026-11-17');

    $response->assertOk();
    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    expect($lines[0])->toContain('Origen del marcaje')
        ->and($lines[0])->toContain('Recepción en servidor')
        ->and(count($lines))->toBe(4);
});

it('no ofrece rutas para editar ni borrar marcajes originales', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'asistencias'))
        ->flatMap(fn ($r) => $r->methods())->unique()->values()->all();

    expect($routes)->not->toContain('PUT')->not->toContain('PATCH')->not->toContain('DELETE');
});

it('panel: omisiones sin rectificar, firmas pendientes y rectificaciones por firmar', function () {
    $this->actingAs($this->admin)->get('/dashboard?period=7d')
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.pending.omissions', 1)
            ->where('metrics.pending.signatures', 0)
            ->missing('metrics.totals.auto_clock_outs')
            ->missing('metrics.totals.after_cutoff_minutes')
            ->has('metrics.today.branches'));

    rectificationFor($this->org->id, $this->open->id, 'por_firmar');

    $this->actingAs($this->admin)->get('/dashboard?period=7d')
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.pending.omissions', 0)      // ya tiene rectificación en curso
            ->where('metrics.pending.signatures', 1)
            ->where('metrics.totals.to_sign', 1));
});

it('panel: asistencia por sucursal con presentes contra programados de hoy', function () {
    // Jueves 19-nov 09:00 local: sólo uno de los dos programados entra.
    $this->punchAt($this->complete, PunchType::ClockIn, '2026-11-19 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 16:00:00', 'UTC'));

    $this->actingAs($this->admin)->get('/dashboard?period=today')
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.today.branches.0.name', 'Matriz')
            ->where('metrics.today.branches.0.scheduled', 2)
            ->where('metrics.today.branches.0.present', 1)
            ->where('metrics.today.branches.0.rate', 50));
});

it('panel: «Sin salida» cuenta jornadas abiertas cuyo horario ya terminó', function () {
    $this->punchAt($this->complete, PunchType::ClockIn, '2026-11-19 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 01:00:00', 'UTC')); // 19:00 local, turno terminó a las 18:00

    $this->actingAs($this->admin)->get('/dashboard?period=today')
        ->assertInertia(fn (Assert $page) => $page->where('metrics.today.missing_out', 1));
});
