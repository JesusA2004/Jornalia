<?php

use App\Domain\Attendance\RectificationService;
use App\Enums\PunchType;
use App\Models\Branch;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
});

it('no permite ver datos de otra organización (404) ni los lista', function () {
    $orgB = $this->organization('Empresa B');
    $employeeB = $this->employee($orgB, schedule: $this->schedule());
    $punch = $this->punchAt($employeeB, PunchType::ClockIn, '2026-11-18 15:00:00');
    $requestB = app(RectificationService::class)->submit($employeeB->user, $employeeB, [
        'date' => '2026-11-18', 'punch_uuid' => $punch->uuid, 'kind' => 'other', 'proposed_type' => null, 'proposed_time' => null,
        'reason' => 'Aclaración de prueba para otra empresa',
    ], [UploadedFile::fake()->image('evidencia.jpg')]);

    $orgA = $this->organization('Empresa A');
    $adminA = $this->userIn($orgA, 'Administrador de empresa', allBranches: true);
    app(CurrentOrganization::class)->set(null);

    $this->actingAs($adminA)->get("/rectificaciones/{$requestB->id}")->assertNotFound();
    $this->actingAs($adminA)->get("/asistencias/{$employeeB->id}/2026-11-18")->assertNotFound();
    $this->actingAs($adminA)->post("/rectificaciones/{$requestB->id}/decision", ['decision' => 'approve'])->assertNotFound();

    $this->actingAs($adminA)->get('/rectificaciones?tab=por_autorizar')
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 0)->where('counts.por_autorizar', 0));
    $this->actingAs($adminA)->get('/asistencias?tab=marcajes&from=2026-11-18&to=2026-11-18')
        ->assertInertia(fn (Assert $page) => $page->where('punches.total', 0));
});

it('limita a un gerente a su sucursal', function () {
    $org = $this->organization();
    $north = Branch::query()->create(['name' => 'Norte', 'code' => 'NTE']);
    $south = Branch::query()->create(['name' => 'Sur', 'code' => 'SUR']);
    $schedule = $this->schedule();
    $inNorth = $this->employee($org, schedule: $schedule, branch: $north);
    $inSouth = $this->employee($org, schedule: $schedule, branch: $south);
    $this->punchAt($inNorth, PunchType::ClockIn, '2026-11-18 15:00:00');
    $this->punchAt($inSouth, PunchType::ClockIn, '2026-11-18 15:01:00');

    $manager = $this->userIn($org, 'Gerente de sucursal');
    $manager->branches()->attach($north->id, ['organization_id' => $org->id]);

    $this->actingAs($manager)->get('/asistencias?tab=marcajes&from=2026-11-18&to=2026-11-18')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('punches.total', 1)
            ->where('punches.data.0.employee_id', $inNorth->id));

    $this->actingAs($manager)->get("/asistencias/{$inSouth->id}/2026-11-18")->assertNotFound();
    $this->actingAs($manager)->get("/asistencias/{$inNorth->id}/2026-11-18")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('asistencias/Show')->has('day.punches', 1));

    // Filtrar por un colaborador fuera de alcance no amplía el alcance.
    $this->actingAs($manager)->get("/asistencias?tab=marcajes&from=2026-11-18&to=2026-11-18&employee_id={$inSouth->id}")
        ->assertInertia(fn (Assert $page) => $page->where('punches.total', 0));
    $this->actingAs($manager)->get("/reportes/historial_marcajes?from=2026-11-18&to=2026-11-18&employee_id={$inSouth->id}")->assertNotFound();
    $this->actingAs($manager)->get('/reportes/historial_marcajes?from=2026-11-18&to=2026-11-18')
        ->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
});
