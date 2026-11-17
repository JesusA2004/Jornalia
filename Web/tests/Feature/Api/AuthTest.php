<?php

use App\Domain\Security\DeviceService;
use App\Enums\DeviceStatus;
use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
    $this->deviceInfo = fn (?string $uuid = null) => [
        'device_uuid' => $uuid ?? (string) Str::uuid(),
        'name' => 'Pixel de Ana',
        'platform' => 'android',
        'os_version' => '15',
        'app_version' => '1.0.0',
        'model' => 'Pixel 9',
        'biometric_available' => true,
    ];
});

it('inicia sesión, vincula el dispositivo como pendiente y entrega el secreto una sola vez', function () {
    $info = ($this->deviceInfo)();

    $first = $this->mobileLogin($info)->assertOk();
    expect($first->json('data.device_secret'))->toBeString()
        ->and(strlen((string) base64_decode($first->json('data.device_secret'), true)))->toBe(32)
        ->and($first->json('data.device.status'))->toBe('pending')
        ->and($first->json('data.device.is_current'))->toBeTrue()
        ->and($first->json('data.token_expires_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($first->json('data.profile.employee.id'))->toBe($this->emp->id)
        ->and($first->json('data.device'))->not->toHaveKey('secret');

    $token = PersonalAccessToken::findToken($first->json('data.token'));
    expect($token->name)->toBe('device:'.$info['device_uuid'])
        ->and($token->abilities)->toBe(['mobile'])
        ->and($token->expires_at)->not->toBeNull();

    $second = $this->mobileLogin($info)->assertOk();
    expect($second->json('data.device_secret'))->toBeNull()
        ->and(PersonalAccessToken::query()->where('name', 'device:'.$info['device_uuid'])->count())->toBe(1);

    expect(AuditLog::query()->where('action', 'auth.login')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('con dispositivo pendiente no permite solicitar desafío de marcaje', function () {
    $response = $this->mobileLogin(($this->deviceInfo)())->assertOk();

    $this->assertApiError($this->mobile('POST', '/attendance/challenge', token: $response->json('data.token')), 423, 'device_pending');
});

it('con dispositivo aprobado permite solicitar desafío', function () {
    $info = ($this->deviceInfo)();
    $token = $this->mobileLogin($info)->json('data.token');
    $device = Device::query()->where('device_uuid', $info['device_uuid'])->firstOrFail();
    app(DeviceService::class)->approve($this->user, $device);

    $this->mobile('POST', '/attendance/challenge', token: $token)
        ->assertOk()
        ->assertJsonStructure(['data' => ['nonce', 'expires_at']]);
});

it('revocar un dispositivo invalida su token y el nuevo inicio de sesión lo re-vincula con nuevo secreto', function () {
    $info = ($this->deviceInfo)();
    $first = $this->mobileLogin($info);
    $device = Device::query()->where('device_uuid', $info['device_uuid'])->firstOrFail();
    app(DeviceService::class)->approve($this->user, $device);
    app(DeviceService::class)->revoke($this->user, $device, 'Extravío');

    $this->assertApiError($this->mobile('GET', '/profile', token: $first->json('data.token')), 401, 'unauthenticated');

    $again = $this->mobileLogin($info)->assertOk();
    expect($again->json('data.device_secret'))->toBeString()->not->toBe($first->json('data.device_secret'))
        ->and($again->json('data.device.status'))->toBe('pending')
        ->and($device->fresh()->status)->toBe(DeviceStatus::Pending);
});

it('rechaza credenciales inválidas con un error genérico que no revela si el correo existe', function () {
    $wrongPassword = $this->mobileLogin(($this->deviceInfo)(), 'incorrecta');
    $unknownEmail = $this->mobileLogin(($this->deviceInfo)(), 'password', 'nadie@example.com');

    $this->assertApiError($wrongPassword, 422, 'validation_failed');
    $this->assertApiError($unknownEmail, 422, 'validation_failed');
    expect($wrongPassword->json('errors'))->toBe($unknownEmail->json('errors'));
});

it('valida el cuerpo del inicio de sesión', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'no-es-correo'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['email', 'password', 'device']);
});

it('rechaza a usuarios inactivos con 403', function () {
    $this->user->forceFill(['status' => 'inactive'])->save();

    $this->assertApiError($this->mobileLogin(($this->deviceInfo)()), 403, 'forbidden');
});

it('rota el token en refresh y el anterior deja de funcionar', function () {
    $refresh = $this->mobile('POST', '/auth/refresh')->assertOk();
    $new = $refresh->json('data.token');
    expect($new)->toBeString()->not->toBe($this->token)
        ->and(array_keys($refresh->json('data')))->toBe(['token', 'token_expires_at']);

    $this->assertApiError($this->mobile('GET', '/profile'), 401, 'unauthenticated');
    $this->mobile('GET', '/profile', token: $new)->assertOk();
    expect(PersonalAccessToken::findToken($new)->name)->toBe('device:'.$this->device->device_uuid);
});

it('cierra sesión eliminando el token actual', function () {
    $this->mobile('POST', '/auth/logout')->assertNoContent();

    $this->assertApiError($this->mobile('GET', '/profile'), 401, 'unauthenticated');
});

it('exige autenticación en rutas protegidas', function () {
    $this->getJson('/api/v1/profile')->assertStatus(401)->assertJson(['code' => 'unauthenticated']);
});

it('expone health de forma pública', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.time', '2026-11-17T16:00:00Z');
});
