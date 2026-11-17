<?php

use App\Enums\PunchType;
use App\Models\Branch;
use App\Models\LocationPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->org = $this->organization();
    $this->north = Branch::query()->create(['name' => 'Norte', 'code' => 'NTE']);
    $this->south = Branch::query()->create(['name' => 'Sur', 'code' => 'SUR']);
    $this->inNorth = $this->employee($this->org, schedule: $this->schedule(), branch: $this->north);
    $this->inSouth = $this->employee($this->org, schedule: $this->schedule(), branch: $this->south);
    $this->punchAt($this->inNorth, PunchType::ClockIn, '2026-11-18 15:00:00');

    $point = function ($employee, string $at, float $lat, float $lng) {
        LocationPoint::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'employee_id' => $employee->id,
            'user_id' => $employee->user_id, 'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => 8,
            'app_state' => 'foreground', 'captured_at' => $at, 'received_at' => $at,
        ]);
        $employee->forceFill(['last_location_at' => $at])->saveQuietly();
    };
    $point($this->inNorth, '2026-11-18 15:05:00', 19.4326, -99.1332);
    $point($this->inNorth, '2026-11-18 15:20:00', 19.4400, -99.1400);
    $point($this->inSouth, '2026-11-18 14:00:00', 20.6597, -103.3496);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 15:25:00', 'UTC'));
});

it('muestra la última posición por colaborador con su estado', function () {
    $admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->get('/ubicacion')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ubicacion/Index')
            ->has('markers', 2)
            ->where('markers', fn ($markers) => collect($markers)->firstWhere('employee_id', $this->inNorth->id)['state'] === 'on_shift'
                && collect($markers)->firstWhere('employee_id', $this->inNorth->id)['latitude'] === 19.44
                && collect($markers)->firstWhere('employee_id', $this->inSouth->id)['state'] === 'stale'));
});

it('entrega el recorrido del día con distancia y marcajes', function () {
    $admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->get("/ubicacion?employee_id={$this->inNorth->id}&date=2026-11-18")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('track.points', 2)
            ->has('track.punches', 1)
            ->where('track.summary.distance_m', fn ($m) => $m > 900 && $m < 1200));
});

it('limita el mapa al alcance del gerente de sucursal', function () {
    $manager = $this->userIn($this->org, 'Gerente de sucursal');
    $manager->branches()->attach($this->north->id, ['organization_id' => $this->org->id]);

    $this->actingAs($manager)->get('/ubicacion')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('markers', 1)->where('markers.0.employee_id', $this->inNorth->id));
    $this->actingAs($manager)->get("/ubicacion?employee_id={$this->inSouth->id}")->assertNotFound();
});
