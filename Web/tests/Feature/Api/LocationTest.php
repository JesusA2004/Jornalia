<?php

use App\Enums\DeviceStatus;
use App\Enums\PunchType;
use App\Models\EmploymentAssignment;
use App\Models\LocationPoint;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\ContractShapes;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
    // Puesto de campo con seguimiento durante la jornada y entrada a las 09:05 (hora local).
    $position = Position::query()->create(['organization_id' => $this->org->id, 'name' => 'Asesor de campo', 'code' => 'CAMPO', 'location_tracking' => 'during_shift']);
    EmploymentAssignment::query()->where('organization_id', $this->org->id)->update(['position_id' => $position->id]);
    $this->punchAt($this->emp, PunchType::ClockIn, '2026-11-17 15:05:00');
    $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    $this->point = fn (array $overrides = []) => array_merge([
        'uuid' => (string) Str::uuid(),
        'latitude' => 19.4326,
        'longitude' => -99.1332,
        'accuracy_m' => 8.4,
        'heading' => 90.5,
        'speed_mps' => 1.2,
        'altitude_m' => 2240.0,
        'captured_at' => '2026-11-17T15:59:30.000Z',
        'app_state' => 'background',
        'is_mocked' => false,
    ], $overrides);
});

it('acepta, deduplica y rechaza puntos por elemento sin abortar el lote', function () {
    $ok = ($this->point)();

    $response = $this->mobile('POST', '/location/points', ['points' => [
        $ok,
        $ok,
        ($this->point)(['latitude' => 200]),
        ($this->point)(['captured_at' => '2026-11-17T16:10:00Z']),
        ($this->point)(['captured_at' => '2026-11-16T08:00:00Z']),
        ($this->point)(['captured_at' => '2026-11-17T13:00:00Z']),
    ]])->assertOk();

    $data = $response->json('data');
    expect($data['accepted'])->toBe(1)
        ->and($data['duplicates'])->toBe(1)
        ->and($data['rejected'])->toBe(4)
        ->and(array_column($data['rejections'], 'code'))->toBe(['invalid_coordinates', 'future_time', 'too_old', 'outside_window'])
        ->and(array_column($data['rejections'], 'index'))->toBe([2, 3, 4, 5])
        ->and($data['settings']['window']['active_now'])->toBeTrue()
        ->and(LocationPoint::query()->where('employee_id', $this->emp->id)->count())->toBe(1);

    expect(array_keys($data))->toBe(['accepted', 'duplicates', 'rejected', 'rejections', 'settings']);
    ContractShapes::check($data['settings'], ContractShapes::shapes()['LocationSettings'], 'LocationSettings');

    $state = $this->mobile('GET', '/location/state')->assertOk()->json('data');
    ContractShapes::check($state, ContractShapes::shapes()['LocationState'], 'LocationState');
    expect($state['last']['uuid'])->toBe($ok['uuid'])
        ->and($state['last']['accuracy_m'])->toBe(8)
        ->and($state['last']['received_at'])->toBe('2026-11-17T16:00:00Z');
});

it('valida el lote: falta, vacío o más de 50 puntos', function () {
    $this->mobile('POST', '/location/points', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->mobile('POST', '/location/points', ['points' => []])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->mobile('POST', '/location/points', ['points' => array_map(fn () => ($this->point)(), range(1, 51))])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    // Un punto malformado no es error de lote: se rechaza por elemento.
    $this->mobile('POST', '/location/points', ['points' => [['foo' => 'bar']]])->assertOk()->assertJsonPath('data.rejected', 1);
});

it('exige un dispositivo aprobado', function () {
    $this->device->forceFill(['status' => DeviceStatus::Pending])->save();

    $this->assertApiError($this->mobile('POST', '/location/points', ['points' => [($this->point)()]]), 423, 'device_pending');
    expect(LocationPoint::query()->count())->toBe(0);
});

it('aísla la ubicación entre organizaciones', function () {
    $this->mobile('POST', '/location/points', ['points' => [($this->point)()]])->assertOk()->assertJsonPath('data.accepted', 1);

    $orgB = $this->organization('Empresa B');
    $empB = $this->employee($orgB, schedule: $this->schedule());
    ['device' => $deviceB] = $this->approvedDevice($empB->user, $empB);
    $tokenB = $this->tokenFor($empB->user, $deviceB);

    $this->mobile('GET', '/location/state', token: $tokenB)->assertOk()->assertJsonPath('data.last', null);
});
