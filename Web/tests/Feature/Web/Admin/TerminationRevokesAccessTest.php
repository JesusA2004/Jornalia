<?php

use App\Domain\Security\PermissionCatalog;
use App\Enums\DeviceStatus;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $this->employee = $this->employee($this->org);
    ['device' => $this->device] = $this->approvedDevice($this->employee->user, $this->employee);
    $this->employee->user->createToken('device:'.$this->device->device_uuid, ['mobile']);
});

it('al registrar la baja de hoy revoca ese mismo día app, dispositivo y sesiones', function () {
    $today = CarbonImmutable::now('America/Mexico_City')->toDateString();

    $this->actingAs($this->hr)->post("/colaboradores/{$this->employee->id}/baja", ['ends_on' => $today, 'reason' => 'Renuncia voluntaria'])
        ->assertSessionHasNoErrors();

    expect($this->device->fresh()->status)->toBe(DeviceStatus::Revoked)
        ->and($this->employee->user->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('organization_user', ['user_id' => $this->employee->user_id, 'organization_id' => $this->org->id, 'status' => 'inactive']);

    // Historial conservado: el dispositivo no se borra.
    expect(Device::query()->withTrashed()->whereKey($this->device->id)->exists())->toBeTrue()
        ->and($this->employee->fresh()->employments()->count())->toBe(1);

    // El acceso no puede reactivarse mientras siga de baja.
    $platform = $this->userIn($this->org, PermissionCatalog::ROLE_PLATFORM_ADMIN, allBranches: true);
    $this->actingAs($platform)->post("/usuarios/{$this->employee->user_id}/reactivar")->assertSessionHasErrors('user');
});

it('una baja con fecha futura se revoca el día de la baja con el proceso programado', function () {
    $endsOn = CarbonImmutable::now('America/Mexico_City')->addDays(2)->toDateString();

    $this->actingAs($this->hr)->post("/colaboradores/{$this->employee->id}/baja", ['ends_on' => $endsOn, 'reason' => 'Fin de contrato'])
        ->assertSessionHasNoErrors();
    expect($this->device->fresh()->status)->toBe(DeviceStatus::Approved);

    $this->travelTo(CarbonImmutable::parse($endsOn.' 23:30', 'America/Mexico_City'));
    $this->artisan('asistencias:revocar-bajas')->assertSuccessful();

    expect($this->device->fresh()->status)->toBe(DeviceStatus::Revoked)
        ->and($this->employee->user->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('organization_user', ['user_id' => $this->employee->user_id, 'organization_id' => $this->org->id, 'status' => 'inactive']);
});
