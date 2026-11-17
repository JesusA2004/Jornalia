<?php

namespace Tests\Feature\Api\Concerns;

use App\Domain\Attendance\DeviceSignature;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsWorkforce;

/**
 * Utilidades para probar la API móvil como lo haría la app: token Sanctum
 * ligado al dispositivo y marcajes firmados con HMAC.
 */
trait MobileApi
{
    use BuildsWorkforce;

    protected Organization $org;

    protected Employee $emp;

    protected User $user;

    protected Device $device;

    protected string $secret;

    protected string $token;

    /** Martes 2026-11-17 10:00 en Ciudad de México (UTC-6). */
    protected const NOW = '2026-11-17 16:00:00';

    protected function setUpMobile(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $this->seedLegal();
        $this->org = $this->organization('Empresa A');
        $this->emp = $this->employee($this->org, schedule: $this->schedule('09:00', '18:00', 60));
        $this->user = $this->emp->user;
        ['device' => $this->device, 'secret' => $this->secret] = $this->approvedDevice($this->user, $this->emp);
        $this->token = $this->tokenFor($this->user, $this->device);
    }

    protected function tokenFor(User $user, Device $device): string
    {
        return $user->createToken('device:'.$device->device_uuid, ['mobile'], now()->addDay())->plainTextToken;
    }

    /**
     * Petición JSON autenticada; reinicia los guards para no reutilizar el usuario
     * resuelto en peticiones previas de la misma prueba.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mobile(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($method, '/api/v1'.$uri, $data);
    }

    /**
     * @param  array<string, mixed>  $device
     */
    protected function mobileLogin(array $device, string $password = 'password', ?string $email = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'email' => $email ?? $this->user->email,
            'password' => $password,
            'device' => $device,
        ]);
    }

    protected function challenge(): string
    {
        return (string) $this->mobile('POST', '/attendance/challenge')->assertOk()->json('data.nonce');
    }

    /**
     * Construye un `PunchRequest` firmado exactamente como la app.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function signedPunch(string $type = 'clock_in', ?string $nonce = null, array $overrides = [], ?string $secret = null): array
    {
        $deviceTime = $overrides['device_time'] ?? now()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $payload = array_merge([
            'client_uuid' => (string) Str::uuid(),
            'type' => $type,
            'device_time' => $deviceTime,
            'timezone' => 'America/Mexico_City',
            'device_uuid' => $this->device->device_uuid,
            'auth_method' => 'device_biometric',
            'nonce' => $nonce,
            'captured_offline' => $nonce === null,
            'location' => null,
            'face_verification_id' => null,
        ], $overrides);

        $payload['signature'] = $overrides['signature'] ?? DeviceSignature::sign(
            $secret ?? $this->secret,
            DeviceSignature::canonical($payload['client_uuid'], $payload['type'], $payload['device_time'], $payload['nonce'], $payload['device_uuid']),
        );

        return $payload;
    }

    protected function assertApiError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        expect($response->json('message'))->toBeString()->not->toBe('');
    }
}
