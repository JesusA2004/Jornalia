<?php

use App\Domain\Security\KioskService;
use App\Domain\Signatures\SignaturePinService;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\ComplianceAlert;
use App\Models\Kiosk;
use App\Models\Punch;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->admin = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
    $this->branch = Branch::query()->create(['code' => 'SUC1', 'name' => 'Sucursal Centro']);
    $this->employee = $this->employee($this->org, branch: $this->branch);
    app(SignaturePinService::class)->set($this->employee->user, '4826');

    $created = app(KioskService::class)->create($this->admin, $this->branch, 'Tableta Centro');
    $this->kiosk = $created['kiosk'];
    $this->code = $created['activation_code'];
});

function activateKiosk($test, string $code)
{
    return $test->postJson('/api/v1/kiosk/activate', [
        'activation_code' => $code,
        'device' => ['device_uuid' => (string) Str::uuid(), 'platform' => 'android', 'os_version' => '14', 'app_version' => '1.2.0', 'model' => 'Galaxy Tab A9'],
    ]);
}

function kioskPunch($test, string $token, array $overrides = [])
{
    return $test->withToken($token)->postJson('/api/v1/kiosk/punches', array_merge([
        'employee_number' => $test->employee->employee_number,
        'pin' => '4826',
        'type' => PunchType::ClockIn->value,
        'client_uuid' => (string) Str::uuid(),
        'device_time' => now()->utc()->toIso8601String(),
        'timezone' => 'America/Mexico_City',
    ], $overrides));
}

it('activa el kiosco con un código de un solo uso y registra modelo y versión', function () {
    $response = activateKiosk($this, $this->code)->assertCreated();
    expect($response->json('data.token'))->toBeString()->toHaveLength(64)
        ->and($response->json('data.kiosk.status'))->toBe('active')
        ->and($response->json('data.kiosk.branch.name'))->toBe('Sucursal Centro');

    $kiosk = $this->kiosk->fresh();
    expect($kiosk->model)->toBe('Galaxy Tab A9')->and($kiosk->app_version)->toBe('1.2.0')->and($kiosk->activation_code_hash)->toBeNull();

    // Segundo uso del mismo código: rechazado.
    activateKiosk($this, $this->code)->assertStatus(422)->assertJsonPath('code', 'kiosk_activation_invalid');

    $this->withToken($response->json('data.token'))->getJson('/api/v1/kiosk/status')->assertOk()->assertJsonPath('data.status', 'active');
});

it('rechaza códigos vencidos y tokens inválidos', function () {
    $this->kiosk->forceFill(['activation_expires_at' => now()->subMinute()])->save();
    activateKiosk($this, $this->code)->assertStatus(422);

    $this->withToken(Str::random(64))->getJson('/api/v1/kiosk/status')->assertStatus(401)->assertJsonPath('code', 'kiosk_unauthenticated');
});

it('registra el marcaje con origen kiosco, encadenado e idempotente por client_uuid', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');
    $uuid = (string) Str::uuid();

    kioskPunch($this, $token, ['client_uuid' => $uuid])->assertCreated()->assertJsonPath('data.duplicate', false);
    kioskPunch($this, $token, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.duplicate', true);

    $punches = Punch::query()->where('employee_id', $this->employee->id)->get();
    expect($punches)->toHaveCount(1)
        ->and($punches[0]->source)->toBe(PunchSource::Kiosk)
        ->and($punches[0]->kiosk_id)->toBe($this->kiosk->id)
        ->and($punches[0]->hash)->not->toBeEmpty();
});

it('responde igual ante número inexistente o NIP incorrecto y bloquea tras 5 intentos', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');

    kioskPunch($this, $token, ['employee_number' => 'NOEXISTE'])->assertStatus(401)->assertJsonPath('code', 'kiosk_invalid_credentials');
    foreach (range(1, 4) as $i) {
        kioskPunch($this, $token, ['pin' => '1357'])->assertStatus(401)->assertJsonPath('code', 'kiosk_invalid_credentials');
    }
    // Quinto intento fallido bloquea; con el NIP correcto sigue bloqueado.
    kioskPunch($this, $token, ['pin' => '1357'])->assertStatus(401);
    kioskPunch($this, $token)->assertStatus(423)->assertJsonPath('code', 'pin_locked');
    expect(Punch::query()->count())->toBe(0);
});

it('limita los intentos por kiosco y número de empleado', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');
    RateLimiter::clear('kiosk:'.$this->kiosk->id.':'.mb_strtolower($this->employee->employee_number));
    foreach (range(1, 10) as $i) {
        RateLimiter::hit('kiosk:'.$this->kiosk->id.':'.mb_strtolower($this->employee->employee_number), 60);
    }
    kioskPunch($this, $token)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});

it('rechaza al colaborador de otra sucursal y de otra organización', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');

    $otherBranch = Branch::query()->create(['code' => 'SUC2', 'name' => 'Sucursal Norte']);
    $other = $this->employee($this->org, branch: $otherBranch);
    app(SignaturePinService::class)->set($other->user, '4826');
    kioskPunch($this, $token, ['employee_number' => $other->employee_number])->assertStatus(403)->assertJsonPath('code', 'kiosk_wrong_branch');

    $orgB = $this->organization('Otra');
    $branchB = Branch::query()->create(['code' => 'SUC1', 'name' => 'Centro B']);
    $foreign = $this->employee($orgB, branch: $branchB);
    app(SignaturePinService::class)->set($foreign->user, '4826');
    $this->actingInOrganization($this->org);

    kioskPunch($this, $token, ['employee_number' => $foreign->employee_number])->assertStatus(401);
    expect(Punch::query()->withoutGlobalScopes()->where('employee_id', $foreign->id)->exists())->toBeFalse();
});

it('un kiosco revocado deja de operar', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');
    app(KioskService::class)->revoke($this->admin, $this->kiosk->fresh(), 'Tableta extraviada');

    kioskPunch($this, $token)->assertStatus(401);
    expect(Kiosk::query()->withTrashed()->find($this->kiosk->id))->not->toBeNull();
});

it('sincroniza la cola offline procesando cada elemento por separado', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');
    $ok = (string) Str::uuid();

    $response = $this->withToken($token)->postJson('/api/v1/kiosk/sync', ['punches' => [
        ['employee_number' => $this->employee->employee_number, 'pin' => '4826', 'type' => 'clock_in', 'client_uuid' => $ok, 'device_time' => now()->subMinutes(30)->utc()->toIso8601String()],
        ['employee_number' => $this->employee->employee_number, 'pin' => '0000', 'type' => 'clock_out', 'client_uuid' => (string) Str::uuid(), 'device_time' => now()->utc()->toIso8601String()],
    ]])->assertOk();

    expect($response->json('data.results.0.result'))->toBe('created')
        ->and($response->json('data.results.1.result'))->toBe('rejected')
        ->and($response->json('data.results.1.error.code'))->toBe('kiosk_invalid_credentials');

    $punch = Punch::query()->where('client_uuid', $ok)->firstOrFail();
    expect($punch->source)->toBe(PunchSource::Kiosk)->and($punch->captured_offline)->toBeTrue();
});

it('exige verificación facial cuando la política la pide con un código propio y compatible', function () {
    AttendancePolicy::query()->update(['face_verification_required' => true]);
    $token = activateKiosk($this, $this->code)->json('data.token');

    // Sin verificación y con una verificación inexistente: mismo código propio.
    foreach ([[], ['face_verification_id' => (string) Str::uuid()]] as $extra) {
        $response = kioskPunch($this, $token, $extra)->assertStatus(422)
            ->assertJsonPath('code', 'face_verification_required')
            ->assertJsonPath('legacy_code', 'policy_violation');
        // Compatibilidad: el mensaje sigue mencionando la verificación facial.
        expect($response->json('message'))->toContain('facial');
    }

    // Cola offline: el elemento se rechaza con el mismo código.
    $sync = $this->withToken($token)->postJson('/api/v1/kiosk/sync', ['punches' => [
        ['employee_number' => $this->employee->employee_number, 'pin' => '4826', 'type' => 'clock_in', 'client_uuid' => (string) Str::uuid(), 'device_time' => now()->subMinutes(5)->utc()->toIso8601String()],
    ]])->assertOk();
    expect($sync->json('data.results.0.result'))->toBe('rejected')
        ->and($sync->json('data.results.0.error.code'))->toBe('face_verification_required')
        ->and($sync->json('data.results.0.error.legacy_code'))->toBe('policy_violation');

    expect(Punch::query()->count())->toBe(0);
});

it('genera alerta en Cumplimiento por ubicación simulada', function () {
    $token = activateKiosk($this, $this->code)->json('data.token');

    kioskPunch($this, $token, ['location' => ['latitude' => 19.43, 'longitude' => -99.13, 'accuracy_meters' => 5, 'is_mocked' => true]])->assertCreated();

    expect(ComplianceAlert::query()->where('employee_id', $this->employee->id)->where('rule_code', 'mock_location')->exists())->toBeTrue();
});
