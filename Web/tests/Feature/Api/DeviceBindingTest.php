<?php

use App\Domain\Security\DeviceService;
use App\Enums\DeviceStatus;
use App\Models\AuditLog;
use App\Models\ComplianceAlert;
use App\Models\Device;
use App\Notifications\DeviceSecurityAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    config(['integrity.auto_approve_first_device' => true]);
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->employee = $this->employee($this->org);
    $this->user = $this->employee->user;
});

function loginFrom($test, $user, string $deviceUuid, ?string $hardwareId)
{
    app('auth')->forgetGuards();

    return $test->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password',
        'device' => [
            'device_uuid' => $deviceUuid, 'hardware_id' => $hardwareId, 'name' => 'Teléfono', 'platform' => 'android',
            'os_version' => '15', 'app_version' => '1.0.0', 'model' => 'SM-G781B', 'biometric_available' => true,
        ],
    ]);
}

it('autoriza sólo el primer teléfono y deja pendiente un cambio de teléfono avisando a RH', function () {
    Notification::fake();

    $first = loginFrom($this, $this->user, (string) Str::uuid(), 'hw-uno')->assertOk();
    expect($first->json('data.device.status'))->toBe('approved');

    $second = loginFrom($this, $this->user, (string) Str::uuid(), 'hw-dos')->assertOk();
    expect($second->json('data.device.status'))->toBe('pending');

    Notification::assertSentTo($this->hr, DeviceSecurityAlert::class, fn ($n) => $n->type === 'device_change');

    // Una sola sesión: el token del primer teléfono ya no sirve.
    app('auth')->forgetGuards();
    $this->withToken($first->json('data.token'))->getJson('/api/v1/profile')->assertUnauthorized();
});

it('al autorizar el nuevo teléfono revoca el anterior', function () {
    loginFrom($this, $this->user, $uuidA = (string) Str::uuid(), 'hw-uno')->assertOk();
    loginFrom($this, $this->user, $uuidB = (string) Str::uuid(), 'hw-dos')->assertOk();

    $new = Device::query()->where('device_uuid', $uuidB)->firstOrFail();
    app(DeviceService::class)->approve($this->hr, $new);

    $old = Device::query()->where('device_uuid', $uuidA)->firstOrFail();
    expect($old->status)->toBe(DeviceStatus::Revoked)
        ->and($old->replaced_by_device_id)->toBe($new->id)
        ->and($new->fresh()->status)->toBe(DeviceStatus::Approved);
});

it('bloquea que otra persona inicie sesión en un teléfono ya vinculado', function () {
    Notification::fake();
    loginFrom($this, $this->user, (string) Str::uuid(), 'hw-compartido')->assertOk();

    $other = $this->employee($this->org);
    $response = loginFrom($this, $other->user, (string) Str::uuid(), 'hw-compartido');

    $response->assertStatus(409)->assertJsonPath('code', 'device_in_use');
    expect(Device::query()->where('user_id', $other->user_id)->count())->toBe(0)
        ->and(ComplianceAlert::query()->where('rule_code', 'device_in_use')->where('employee_id', $other->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'device.in_use_blocked')->exists())->toBeTrue();
    Notification::assertSentTo($this->hr, DeviceSecurityAlert::class, fn ($n) => $n->type === 'device_in_use');
});

it('la sesión móvil vence al terminar el día', function () {
    $this->travelTo(now('America/Mexico_City')->setTime(10, 0)->utc());
    $login = loginFrom($this, $this->user, (string) Str::uuid(), 'hw-uno')->assertOk();

    $expires = CarbonImmutable::parse($login->json('data.token_expires_at'))->setTimezone('America/Mexico_City');
    expect($expires->format('H:i'))->toBe('23:59')
        ->and($expires->toDateString())->toBe(now('America/Mexico_City')->toDateString());

    $this->travelTo(now()->addDay());
    app('auth')->forgetGuards();
    $this->withToken($login->json('data.token'))->getJson('/api/v1/profile')->assertUnauthorized();
});
