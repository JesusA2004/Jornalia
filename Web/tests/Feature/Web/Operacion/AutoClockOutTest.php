<?php

use App\Domain\Attendance\AutoClockOut;
use App\Enums\PunchType;
use App\Models\AttendancePolicy;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->org = $this->organization();
    $this->admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);
    $this->employee = $this->employee($this->org, schedule: $this->schedule());

    // Entrada 09:00 local (UTC-6); cierre automático a las 19:00 local = 01:00 UTC.
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    AttendancePolicy::query()->update(['auto_clock_out_at' => '19:00:00']);
    if (AttendancePolicy::query()->count() === 0) {
        AttendancePolicy::query()->create(['name' => 'Base', 'location_mode' => 'free', 'allowed_auth_methods' => ['device_biometric'], 'web_punch_allowed' => true, 'is_default' => true, 'auto_clock_out_at' => '19:00:00']);
    }
    $now = CarbonImmutable::parse('2026-11-18 02:30:00', 'UTC');
    $this->travelTo($now);
    $this->auto = app(AutoClockOut::class)->closeIfDue($this->employee->refresh(), $now);
});

it('muestra la salida automática con la hora límite y la de registro', function () {
    expect($this->auto)->not->toBeNull();

    $this->actingAs($this->admin)->get("/asistencias/{$this->employee->id}/2026-11-17")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('day.auto_clock_out.cutoff_at', '2026-11-18T01:00:00Z')
            ->where('day.auto_clock_out.registered_at', '2026-11-18T02:30:00Z')
            ->where('day.punches', fn ($punches) => collect($punches)->contains(fn ($p) => $p['is_system']
                && $p['recognized_time'] === '2026-11-18T01:00:00Z'
                && collect($p['anomalies'])->contains('label', 'Salida automática'))));
});

it('cuenta las salidas automáticas en listados y reportes', function () {
    $this->actingAs($this->admin)->get('/asistencias?tab=marcajes&auto=1&from=2026-11-16&to=2026-11-18')
        ->assertInertia(fn (Assert $page) => $page->where('punches.total', 1)->where('totals.auto_clock_outs', 1));

    $this->actingAs($this->admin)->get('/reportes/historial_marcajes?from=2026-11-16&to=2026-11-18')
        ->assertInertia(fn (Assert $page) => $page->where('rows.data', fn ($rows) => collect($rows)->contains(fn ($r) => $r['origin_label'] === 'Sistema (cierre automático)'
            && $r['recognized_time'] === '2026-11-18T01:00:00Z')
            && collect($rows)->contains('origin_label', 'Colaborador')));

    $this->actingAs($this->admin)->get('/dashboard?period=7d')
        ->assertInertia(fn (Assert $page) => $page->where('metrics.totals.auto_clock_outs', 1));
});
