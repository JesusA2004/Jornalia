<?php

use App\Enums\DeviceStatus;
use App\Models\AttendancePolicy;
use App\Models\Punch;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

it('registra un marcaje firmado con desafío y devuelve el comprobante', function () {
    $nonce = $this->challenge();
    $payload = $this->signedPunch('clock_in', $nonce);

    $response = $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();

    $response->assertJsonPath('data.punch.type', 'clock_in')
        ->assertJsonPath('data.punch.status', 'accepted')
        ->assertJsonPath('data.punch.source', 'mobile')
        ->assertJsonPath('data.punch.server_time', '2026-11-17T16:00:00Z')
        ->assertJsonPath('data.punch.captured_offline', false)
        ->assertJsonPath('data.punch.rectified', false)
        ->assertJsonPath('data.workday.date', '2026-11-17')
        ->assertJsonPath('data.workday.status', 'working')
        ->assertJsonPath('data.workday.next_expected', 'clock_out');

    expect($response->json('data.receipt_code'))->toStartWith('ML-')
        ->and($response->json('data.punch.hash'))->toMatch('/^[a-f0-9]{64}$/')
        ->and($response->json('data.punch'))->not->toHaveKeys(['ip_address', 'user_agent', 'device_id']);

    $punch = Punch::query()->where('uuid', $response->json('data.punch.uuid'))->firstOrFail();
    expect($punch->client_uuid)->toBe($payload['client_uuid'])
        ->and($punch->device_id)->toBe($this->device->id)
        ->and($punch->signature_valid)->toBeTrue();

    $this->mobile('GET', '/attendance/punches/'.$punch->uuid)
        ->assertOk()
        ->assertJsonPath('data.punch.uuid', $punch->uuid)
        ->assertJsonPath('data.receipt_code', $response->json('data.receipt_code'));
});

it('es idempotente por client_uuid: el reenvío devuelve 200 y el mismo marcaje', function () {
    $payload = $this->signedPunch('clock_in', $this->challenge());

    $first = $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();
    $second = $this->mobile('POST', '/attendance/punches', $payload)->assertOk();

    expect($second->json('data.punch.uuid'))->toBe($first->json('data.punch.uuid'))
        ->and(Punch::query()->where('employee_id', $this->emp->id)->count())->toBe(1);
});

it('rechaza una firma inválida con 401', function () {
    $payload = $this->signedPunch('clock_in', $this->challenge(), secret: base64_encode(random_bytes(32)));

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $payload), 401, 'invalid_signature');
    expect(Punch::query()->count())->toBe(0);
});

it('rechaza una firma calculada sobre otro device_time (debe usarse el string exacto)', function () {
    $payload = $this->signedPunch('clock_in', $this->challenge());
    $payload['device_time'] = '2026-11-17T16:00:00Z'; // mismo instante, distinto string

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $payload), 401, 'invalid_signature');
});

it('rechaza un nonce reutilizado con 409', function () {
    $nonce = $this->challenge();
    $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $nonce))->assertCreated();

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_out', $nonce)), 409, 'challenge_used');
});

it('rechaza un nonce inexistente o expirado con 409', function () {
    $nonce = $this->challenge();
    $this->travel(5)->minutes();

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $nonce)), 409, 'challenge_expired');
    $this->assertApiError($this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', str_repeat('a', 48))), 409, 'challenge_expired');
});

it('rechaza marcajes desde un dispositivo revocado con 403', function () {
    $nonce = $this->challenge();
    $this->device->forceFill(['status' => DeviceStatus::Revoked, 'revoked_at' => now()])->save();

    $this->assertApiError($this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $nonce)), 403, 'device_revoked');
    $this->assertApiError($this->mobile('POST', '/attendance/challenge'), 403, 'device_revoked');
});

it('exige nonce para marcajes en línea', function () {
    $payload = $this->signedPunch('clock_in', null, ['captured_offline' => false]);

    $this->mobile('POST', '/attendance/punches', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['nonce']);
});

it('rechaza una secuencia inválida en línea con 409', function () {
    $this->assertApiError($this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_out', $this->challenge())), 409, 'invalid_sequence');
});

it('acepta un marcaje offline sin nonce, con la hora declarada y marcado como offline', function () {
    $deviceTime = now()->subMinutes(30)->utc()->format('Y-m-d\TH:i:s.v\Z');
    $payload = $this->signedPunch('clock_in', null, ['device_time' => $deviceTime]);

    $response = $this->mobile('POST', '/attendance/punches', $payload)->assertCreated();

    $response->assertJsonPath('data.punch.captured_offline', true)
        ->assertJsonPath('data.punch.source', 'mobile_offline')
        ->assertJsonPath('data.punch.device_time', '2026-11-17T15:30:00Z')
        ->assertJsonPath('data.punch.server_time', '2026-11-17T16:00:00Z');
    expect($response->json('data.punch.anomalies'))->toContain('no_location');
});

it('marca para revisión (flagged) un offline cuando la política no lo permite', function () {
    AttendancePolicy::query()->where('is_default', true)->update(['allow_offline' => false]);

    $response = $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in'))->assertCreated();

    $response->assertJsonPath('data.punch.status', 'flagged');
    expect($response->json('data.punch.anomalies'))->toContain('offline_not_allowed');
});

it('sincroniza lotes con resultados mixtos sin abortar por un elemento inválido', function () {
    $ok = $this->signedPunch('clock_in', null, ['device_time' => now()->subHours(2)->utc()->format('Y-m-d\TH:i:s.v\Z')]);
    $badSignature = $this->signedPunch('clock_out', null, ['signature' => str_repeat('0', 64)]);
    $invalid = $this->signedPunch('clock_out');
    unset($invalid['type']);
    $later = $this->signedPunch('clock_out', null, ['device_time' => now()->subHour()->utc()->format('Y-m-d\TH:i:s.v\Z')]);

    $response = $this->mobile('POST', '/sync/punches', ['punches' => [$ok, $ok, $badSignature, $invalid, $later]])->assertOk();

    $results = $response->json('data.results');
    expect(array_column($results, 'result'))->toBe(['created', 'duplicate', 'rejected', 'rejected', 'created'])
        ->and(array_column($results, 'client_uuid'))->toBe([$ok['client_uuid'], $ok['client_uuid'], $badSignature['client_uuid'], $invalid['client_uuid'], $later['client_uuid']])
        ->and($results[0]['punch']['uuid'])->toBe($results[1]['punch']['uuid'])
        ->and($results[2]['error']['code'])->toBe('invalid_signature')
        ->and($results[3]['error']['code'])->toBe('validation_failed')
        ->and($results[3]['error']['errors'])->toHaveKey('type')
        ->and(Punch::query()->where('employee_id', $this->emp->id)->count())->toBe(2);
});

it('valida el lote de sincronización', function () {
    $this->mobile('POST', '/sync/punches', ['punches' => []])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('no ofrece rutas para modificar o eliminar marcajes y el registro sigue intacto', function () {
    $created = $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge()))->assertCreated();
    $uuid = $created->json('data.punch.uuid');
    $before = Punch::query()->where('uuid', $uuid)->firstOrFail()->getAttributes();

    foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
        $status = $this->mobile($method, '/attendance/punches/'.$uuid, ['type' => 'clock_out'])->status();
        expect($status)->toBeIn([404, 405]);
        $status = $this->mobile($method, '/attendance/punches', ['uuid' => $uuid])->status();
        expect($status)->toBeIn([404, 405]);
    }

    expect(Punch::query()->where('uuid', $uuid)->firstOrFail()->getAttributes())->toBe($before);
});

it('requiere relación laboral para marcar', function () {
    $other = $this->userIn($this->org, 'Colaborador');
    $token = $other->createToken('device:'.$this->device->device_uuid, ['mobile'])->plainTextToken;

    $this->assertApiError($this->mobile('POST', '/attendance/challenge', token: $token), 403, 'no_employment');
});
