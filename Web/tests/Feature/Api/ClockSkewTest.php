<?php

use App\Domain\Attendance\ClockSkewMonitor;
use App\Models\ComplianceAlert;
use App\Models\Punch;
use Carbon\CarbonInterface;
use Tests\Feature\Api\Concerns\MobileApi;

/*
| Hora oficial (Revisión RH, regla general 3): se guarda la hora del dispositivo,
| la de sincronización y la de recepción en servidor. Diferencia > 10 min ⇒
| alerta `clock_skew` en Cumplimiento, distinguiendo sincronización tardía de
| reloj alterado.
*/

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

function skewIso(CarbonInterface $t): string
{
    return $t->utc()->format('Y-m-d\TH:i:s.v\Z');
}

function skewAlert(int $employeeId): ?ComplianceAlert
{
    return ComplianceAlert::query()->where('employee_id', $employeeId)->where('rule_code', 'clock_skew')->first();
}

it('guarda hora del dispositivo, de sincronización y de recepción en servidor', function () {
    $payload = $this->signedPunch('clock_in', null, ['device_time' => skewIso(now()->subMinutes(5)), 'synced_at' => skewIso(now())]);

    $response = $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();

    $response->assertJsonPath('data.punch.received_at', '2026-11-17T16:00:00Z')
        ->assertJsonPath('data.punch.synced_at', '2026-11-17T16:00:00Z')
        ->assertJsonPath('data.punch.device_time', '2026-11-17T15:55:00Z');
    $punch = Punch::query()->where('client_uuid', $payload['client_uuid'])->firstOrFail();
    expect($punch->received_at?->format('Y-m-d H:i:s'))->toBe('2026-11-17 16:00:00')
        ->and($punch->synced_at?->format('Y-m-d H:i:s'))->toBe('2026-11-17 16:00:00')
        ->and(skewAlert($this->emp->id))->toBeNull(); // 5 min: dentro de tolerancia
});

it('alerta sincronización sin conexión tardía cuando el reloj era coherente al sincronizar', function () {
    $payload = $this->signedPunch('clock_in', null, ['device_time' => skewIso(now()->subMinutes(45))]);

    $this->mobile('POST', '/sync/punches', ['punches' => [$payload], 'synced_at' => skewIso(now()->subSeconds(20))])->assertOk();

    $alert = skewAlert($this->emp->id);
    expect($alert)->not->toBeNull()
        ->and($alert->data['kind'])->toBe(ClockSkewMonitor::KIND_LATE_SYNC)
        ->and($alert->severity->value)->toBe('warning')
        ->and($alert->data['punches'][0]['difference_minutes'])->toBe(45)
        ->and($alert->date->toDateString())->toBe('2026-11-17');
});

it('alerta reloj alterado cuando el reloj del dispositivo tampoco coincide al sincronizar', function () {
    $payload = $this->signedPunch('clock_in', null, ['device_time' => skewIso(now()->subMinutes(40)), 'synced_at' => skewIso(now()->subMinutes(38))]);

    $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();

    $alert = skewAlert($this->emp->id);
    expect($alert->data['kind'])->toBe(ClockSkewMonitor::KIND_ALTERED)
        ->and($alert->severity->value)->toBe('critical');
});

it('alerta reloj alterado en un marcaje en línea con más de 10 minutos de diferencia', function () {
    $payload = $this->signedPunch('clock_in', $this->challenge(), ['device_time' => skewIso(now()->addMinutes(12))]);

    $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();

    expect(skewAlert($this->emp->id)?->data['kind'])->toBe(ClockSkewMonitor::KIND_ALTERED);
});

it('no alerta en el límite exacto de 10 minutos', function () {
    $payload = $this->signedPunch('clock_in', null, ['device_time' => skewIso(now()->subMinutes(10))]);

    $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();

    expect(skewAlert($this->emp->id))->toBeNull();
});
