<?php

use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

/**
 * 6 días × 7 h 50 min = 47 h semanales (legal en 2026, ilegal desde 2027: 46 h).
 */
function schedulePayload(array $overrides = []): array
{
    $segments = [];
    foreach (range(0, 5) as $day) {
        $segments[] = ['day_index' => $day, 'ordinal' => 1, 'starts_at' => '08:00', 'ends_at' => '15:50', 'break_minutes' => 0];
    }

    return array_merge([
        'name' => 'Seis días',
        'code' => 'SEIS',
        'type' => 'fixed',
        'shift_kind' => 'day',
        'cycle_length_days' => 7,
        'tolerance_minutes' => 10,
        'flex_minutes' => 0,
        'segments' => $segments,
    ], $overrides);
}

it('niega administrar horarios sin permiso', function () {
    $org = $this->organization();
    $user = $this->userIn($org, 'Supervisor');

    $this->actingAs($user)->get('/horarios')->assertForbidden();
    $this->actingAs($user)->post('/horarios', schedulePayload())->assertForbidden();
});

it('rechaza un horario que rebasa la jornada semanal vigente en 2027 (46 h)', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/horarios', schedulePayload(['applies_from' => '2027-01-04']))
        ->assertSessionHasErrors('segments');
    expect(Schedule::query()->count())->toBe(0);

    // En 2026 (48 h) el mismo horario es legal.
    $this->actingAs($admin)->post('/horarios', schedulePayload())->assertSessionHasNoErrors();
    expect(Schedule::query()->where('code', 'SEIS')->exists())->toBeTrue();
});

it('rechaza una jornada diaria superior al máximo legal', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/horarios', schedulePayload([
        'code' => 'LARGO',
        'segments' => [['day_index' => 0, 'ordinal' => 1, 'starts_at' => '07:00', 'ends_at' => '17:00', 'break_minutes' => 60]],
    ]))->assertSessionHasErrors('segments');

    // Nocturna de 8 h: excede 7 h.
    $this->actingAs($admin)->post('/horarios', schedulePayload([
        'code' => 'NOCHE', 'shift_kind' => 'night',
        'segments' => [['day_index' => 0, 'ordinal' => 1, 'starts_at' => '22:00', 'ends_at' => '06:00', 'break_minutes' => 0]],
    ]))->assertSessionHasErrors('segments');

    // Nocturna de 7 h que cruza medianoche: válida.
    $this->actingAs($admin)->post('/horarios', schedulePayload([
        'code' => 'NOCHE7', 'shift_kind' => 'night',
        'segments' => [['day_index' => 0, 'ordinal' => 1, 'starts_at' => '22:00', 'ends_at' => '05:00', 'break_minutes' => 0]],
    ]))->assertSessionHasNoErrors();
});

it('valida en vivo y reporta la reducción futura', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $result = $this->actingAs($admin)->postJson('/horarios/validar', [
        'shift_kind' => 'day', 'cycle_length_days' => 7, 'segments' => schedulePayload()['segments'],
    ])->assertOk()->json();

    expect($result['weekly_minutes'])->toBe(47 * 60)
        ->and(collect($result['checks'])->firstWhere('date', '2027-01-01')['ok'] ?? null)->toBeFalse();
});

it('impide asignar con vigencia 2027 un horario que rebasa 46 h', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $this->actingAs($admin)->post('/horarios', schedulePayload());
    $schedule = Schedule::query()->where('code', 'SEIS')->firstOrFail();
    $employee = $this->employee($org);

    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/movimientos", [
        'effective_from' => '2027-01-04', 'reason' => 'Cambio de turno', 'fields' => ['schedule_id'], 'schedule_id' => $schedule->id,
    ])->assertSessionHasErrors('schedule_id');

    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/movimientos", [
        'effective_from' => CarbonImmutable::today()->toDateString(), 'reason' => 'Cambio de turno', 'fields' => ['schedule_id'], 'schedule_id' => $schedule->id,
    ])->assertSessionHasNoErrors();
});

it('asigna horario de forma masiva y reporta conflictos', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $schedule = $this->schedule();
    $a = $this->employee($org);
    $b = $this->employee($org);
    $branchId = $a->assignmentOn(CarbonImmutable::today())->branch_id;
    $from = CarbonImmutable::today()->addDays(2)->toDateString();

    $this->actingAs($admin)->get('/horarios/asignacion?'.http_build_query([
        'schedule_id' => $schedule->id, 'effective_from' => $from, 'mode' => 'branch', 'branch_ids' => [$branchId],
    ]))->assertOk();

    $this->actingAs($admin)->post('/horarios/asignacion', [
        'schedule_id' => $schedule->id, 'effective_from' => $from, 'mode' => 'branch', 'branch_ids' => [$branchId],
    ])->assertSessionHasNoErrors();

    expect($a->fresh()->assignmentOn($from)->schedule_id)->toBe($schedule->id)
        ->and($b->fresh()->assignmentOn($from)->schedule_id)->toBe($schedule->id);
});

it('aísla horarios entre organizaciones', function () {
    $orgB = $this->organization('Empresa B');
    $foreign = $this->schedule();

    $orgA = $this->organization('Empresa A');
    $admin = $this->userIn($orgA, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->get("/horarios/{$foreign->id}/editar")->assertNotFound();
    $this->actingAs($admin)->put("/horarios/{$foreign->id}", schedulePayload())->assertNotFound();
    $this->actingAs($admin)->post("/horarios/{$foreign->id}/desactivar")->assertNotFound();
});

it('desactiva sin borrar y reactiva un horario', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $schedule = $this->schedule();

    $this->actingAs($admin)->post("/horarios/{$schedule->id}/desactivar")->assertSessionHasNoErrors();
    expect(Schedule::query()->find($schedule->id))->toBeNull()
        ->and(Schedule::withTrashed()->find($schedule->id))->not->toBeNull();

    $this->actingAs($admin)->post("/horarios/{$schedule->id}/reactivar")->assertSessionHasNoErrors();
    expect(Schedule::query()->find($schedule->id)?->active)->toBeTrue();
});

it('actualiza segmentos sin borrado físico', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $this->actingAs($admin)->post('/horarios', schedulePayload());
    $schedule = Schedule::query()->where('code', 'SEIS')->firstOrFail();

    $payload = schedulePayload();
    $payload['segments'] = array_slice($payload['segments'], 0, 5);
    $this->actingAs($admin)->put("/horarios/{$schedule->id}", $payload)->assertSessionHasNoErrors();

    expect($schedule->segments()->count())->toBe(5)
        ->and($schedule->segments()->withTrashed()->count())->toBe(6);

    $this->actingAs($admin)->put("/horarios/{$schedule->id}", schedulePayload())->assertSessionHasNoErrors();
    expect($schedule->segments()->count())->toBe(6);
});
