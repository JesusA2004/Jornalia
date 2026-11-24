<?php

use App\Enums\DeviceStatus;
use App\Models\Punch;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

it('un dispositivo pendiente no registra marcajes: la falta queda como omisión', function () {
    $this->device->forceFill(['status' => DeviceStatus::Pending, 'approved_at' => null])->save();

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in')), 423, 'device_pending');
    // La cola offline responde por elemento: el marcaje queda rechazado.
    $sync = $this->mobile('POST', '/sync/punches', ['punches' => [$this->signedPunch('clock_in')]]);
    expect($sync->status())->toBeIn([200, 207, 423])
        ->and($sync->getContent())->toContain('device_pending');

    expect(Punch::query()->count())->toBe(0);
});

it('un dispositivo revocado tampoco registra marcajes', function () {
    $this->device->forceFill(['status' => DeviceStatus::Revoked, 'revoked_at' => now()])->save();

    $response = $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in'));
    expect($response->status())->toBeIn([401, 403, 423]);
    expect(Punch::query()->count())->toBe(0);
});
