<?php

use App\Enums\PunchType;
use App\Models\Device;
use App\Models\VacationRequest;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();

    // Organización B con su propio colaborador, dispositivo y marcaje.
    $this->orgB = $this->organization('Empresa B');
    $this->empB = $this->employee($this->orgB, schedule: $this->schedule());
    ['device' => $this->deviceB] = $this->approvedDevice($this->empB->user, $this->empB);
    $this->punchB = $this->punchAt($this->empB, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(now()->parse(self::NOW));

    $this->actingInOrganization($this->org);

    // Otro colaborador de la misma organización A.
    $this->peer = $this->employee($this->org);
    ['device' => $this->peerDevice] = $this->approvedDevice($this->peer->user, $this->peer);
});

it('sólo lista los dispositivos propios', function () {
    $ids = collect($this->mobile('GET', '/devices')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$this->device->id]);
});

it('no permite operar sobre dispositivos de otros usuarios u organizaciones (404)', function () {
    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->deviceB->device_uuid, 'expo_push_token' => 'ExponentPushToken[abc]'])->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->peerDevice->device_uuid, 'expo_push_token' => 'ExponentPushToken[abc]'])->assertNotFound();

    expect(Device::query()->withoutGlobalScopes()->whereNotNull('expo_push_token')->count())->toBe(0);

    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->device->device_uuid, 'expo_push_token' => 'ExponentPushToken[abc]'])->assertNoContent();
    expect($this->device->fresh()->expo_push_token)->toBe('ExponentPushToken[abc]');
});

it('no expone marcajes de otra organización', function () {
    $this->mobile('GET', '/attendance/punches/'.$this->punchB->uuid)->assertNotFound()->assertJsonPath('code', 'not_found');

    $history = $this->mobile('GET', '/attendance/history?from=2026-11-17&to=2026-11-17')->assertOk();
    expect($history->json('data.0.punches'))->toBe([]);
});

it('no usa un dispositivo de otro usuario para firmar marcajes', function () {
    $payload = $this->signedPunch('clock_in', $this->challenge(), ['device_uuid' => $this->peerDevice->device_uuid]);

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $payload), 403, 'device_unknown');
});

it('no permite cancelar solicitudes de vacaciones de otro colaborador', function () {
    $vacation = VacationRequest::query()->create([
        'employee_id' => $this->peer->id,
        'employment_id' => $this->peer->employments()->value('id'),
        'service_year' => 2, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02', 'days' => 2, 'status' => 'pending',
    ]);

    $this->mobile('POST', "/vacations/{$vacation->id}/cancel")->assertNotFound();
    expect($vacation->fresh()->status->value)->toBe('pending');
});

it('el usuario de B no ve datos de A', function () {
    $tokenB = $this->tokenFor($this->empB->user, $this->deviceB);

    $profile = $this->mobile('GET', '/profile', token: $tokenB)->assertOk();
    expect($profile->json('data.organization.id'))->toBe($this->orgB->id)
        ->and($profile->json('data.employee.id'))->toBe($this->empB->id);

    $ids = collect($this->mobile('GET', '/devices', token: $tokenB)->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->deviceB->id]);
});
