<?php

use App\Enums\PunchType;
use App\Models\AuditLog;
use App\Models\EmploymentAssignment;
use App\Models\LocationPoint;
use App\Models\OrganizationSetting;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

/*
| Ubicación en tiempo real (PDF pantalla 04): parámetro por puesto, seguimiento
| de la entrada a la salida con pausa en descanso, corte por salida omitida y
| depuración legal de recorridos.
*/

beforeEach(function () {
    $this->setUpMobile();
    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'name' => 'Asesor de campo', 'code' => 'CAMPO', 'location_tracking' => 'on_punch']);
    EmploymentAssignment::query()->where('organization_id', $this->org->id)->update(['position_id' => $this->position->id]);
    $this->at = fn (string $utc) => [
        'uuid' => (string) Str::uuid(), 'latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 8,
        'captured_at' => $utc, 'app_state' => 'foreground', 'is_mocked' => false,
    ];
});

it('expone el modo del puesto y los estados de pausa en la política de asistencia', function () {
    $policy = $this->mobile('GET', '/attendance/policy')->assertOk()->json('data');
    expect($policy['location_tracking'])->toBe('on_punch')
        ->and($policy['tracking_paused_states'])->toBe(['break', 'meal'])
        ->and($policy['location_contingency_minutes'])->toBe(60);

    $this->position->update(['location_tracking' => 'during_shift']);
    expect($this->mobile('GET', '/attendance/policy')->json('data.location_tracking'))->toBe('during_shift');
});

it('con «Solo ubicación al marcar» rechaza el seguimiento aunque haya jornada abierta', function () {
    $this->punchAt($this->emp, PunchType::ClockIn, '2026-11-17 15:05:00');
    $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));

    $res = $this->mobile('POST', '/location/points', ['points' => [($this->at)('2026-11-17T15:59:00Z')]])->assertOk()->json('data');
    expect($res['accepted'])->toBe(0)
        ->and($res['rejections'][0]['code'])->toBe('tracking_disabled')
        ->and($res['settings']['mode'])->toBe('on_punch');
});

it('el seguimiento inicia con la entrada, se pausa en descanso y termina con la salida', function () {
    $this->position->update(['location_tracking' => 'during_shift']);
    $this->punchAt($this->emp, PunchType::ClockIn, '2026-11-17 15:05:00');
    $this->punchAt($this->emp, PunchType::BreakStart, '2026-11-17 15:30:00');
    $this->punchAt($this->emp, PunchType::BreakEnd, '2026-11-17 15:45:00');
    $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));

    $res = $this->mobile('POST', '/location/points', ['points' => [
        ($this->at)('2026-11-17T15:00:00Z'), // antes de la entrada
        ($this->at)('2026-11-17T15:10:00Z'), // jornada
        ($this->at)('2026-11-17T15:35:00Z'), // descanso registrado
        ($this->at)('2026-11-17T15:50:00Z'), // jornada reanudada
    ]])->assertOk()->json('data');

    expect($res['accepted'])->toBe(2)
        ->and(array_column($res['rejections'], 'code'))->toBe(['outside_window', 'on_break'])
        ->and($res['settings']['phase'])->toBe('active');

    $this->punchAt($this->emp, PunchType::ClockOut, '2026-11-17 16:10:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-17 16:20:00', 'UTC'));
    $after = $this->mobile('POST', '/location/points', ['points' => [($this->at)('2026-11-17T16:15:00Z')]])->assertOk()->json('data');
    expect($after['rejections'][0]['code'])->toBe('outside_window')
        ->and($after['settings']['phase'])->toBe('off');
});

it('corta el seguimiento tras el fin del turno más el margen si la salida se omite (sin crear marcajes)', function () {
    $this->position->update(['location_tracking' => 'during_shift']);
    OrganizationSetting::query()->create(['organization_id' => $this->org->id, 'key' => 'location.policy', 'value' => ['retention_days' => 90, 'contingency_minutes' => 30, 'retention_confirmed' => false]]);
    $this->punchAt($this->emp, PunchType::ClockIn, '2026-11-17 15:05:00');
    // Turno 09:00–18:00 (CDMX) = 15:00–00:00 UTC; corte a las 00:30 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 00:45:00', 'UTC'));
    $punches = DB::table('attendance_punches')->count();

    $res = $this->mobile('POST', '/location/points', ['points' => [
        ($this->at)('2026-11-18T00:20:00Z'),
        ($this->at)('2026-11-18T00:40:00Z'),
    ]])->assertOk()->json('data');

    expect($res['accepted'])->toBe(1)
        ->and($res['rejections'][0]['code'])->toBe('contingency_cutoff')
        ->and(DB::table('attendance_punches')->count())->toBe($punches);
});

it('depura recorridos vencidos (excepción legal documentada) y conserva los vigentes', function () {
    $make = fn (string $at) => LocationPoint::query()->create([
        'uuid' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'employee_id' => $this->emp->id, 'user_id' => $this->user->id,
        'latitude' => 19.43, 'longitude' => -99.13, 'app_state' => 'foreground', 'captured_at' => $at, 'received_at' => $at,
    ]);
    $old = $make('2026-07-01 15:00:00');
    $recent = $make('2026-11-10 15:00:00');

    // Fuera de la depuración programada, el trigger impide borrar (y nunca se permite UPDATE).
    expect(fn () => DB::table('location_points')->where('id', $old->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('location_points')->where('id', $old->id)->update(['latitude' => 1]))->toThrow(QueryException::class);

    $this->artisan('ubicacion:depurar', ['--organizacion' => $this->org->id])->assertSuccessful();

    expect(LocationPoint::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(LocationPoint::query()->whereKey($recent->id)->exists())->toBeTrue();
    $log = AuditLog::query()->where('organization_id', $this->org->id)->where('action', 'location.purged')->firstOrFail();
    expect($log->context['purged'])->toBe(1)->and($log->context['retention_days'])->toBe(90);

    // La bandera de depuración no queda activa en la sesión.
    expect(fn () => DB::table('location_points')->where('id', $recent->id)->delete())->toThrow(QueryException::class);
});
