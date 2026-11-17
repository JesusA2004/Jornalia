<?php

use App\Enums\DeviceStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Geofence;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

it('autoriza, revoca con motivo y marca corporativo un dispositivo', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $worker = $this->userIn($org, 'Colaborador');
    $employee = $this->employee($org, $worker);
    ['device' => $device] = $this->approvedDevice($worker, $employee);
    $device->forceFill(['status' => DeviceStatus::Pending, 'approved_at' => null])->save();

    $this->actingAs($admin)->get('/dispositivos?status=pending')->assertOk();
    $this->actingAs($admin)->post("/dispositivos/{$device->id}/autorizar")->assertSessionHasNoErrors();
    expect($device->fresh()->status)->toBe(DeviceStatus::Approved);

    $this->actingAs($admin)->post("/dispositivos/{$device->id}/revocar", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post("/dispositivos/{$device->id}/revocar", ['reason' => 'Teléfono extraviado'])->assertSessionHasNoErrors();
    expect($device->fresh()->status)->toBe(DeviceStatus::Revoked)
        ->and($device->fresh()->revoke_reason)->toBe('Teléfono extraviado');

    $this->actingAs($admin)->patch("/dispositivos/{$device->id}/corporativo", ['is_corporate' => true])->assertSessionHasNoErrors();
    expect($device->fresh()->is_corporate)->toBeTrue();
});

it('muestra el cambio de teléfono con el teléfono anterior y su reemplazo', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $worker = $this->userIn($org, 'Colaborador');
    $employee = $this->employee($org, $worker);
    ['device' => $old] = $this->approvedDevice($worker, $employee);
    $old->forceFill(['model' => 'Pixel 6'])->save();
    ['device' => $new] = $this->approvedDevice($worker, $employee);
    $new->forceFill(['status' => DeviceStatus::Pending, 'approved_at' => null, 'pending_reason' => 'device_change', 'model' => 'Galaxy A55'])->save();

    $this->actingAs($admin)->get('/dispositivos?status=pending')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('devices.data.0.id', $new->id)
            ->where('devices.data.0.pending_reason', 'device_change')
            ->where('devices.data.0.previous_device.id', $old->id)
            ->where('devices.data.0.previous_device.model', 'Pixel 6'));

    $this->actingAs($admin)->post("/dispositivos/{$new->id}/autorizar")->assertSessionHasNoErrors();
    expect($old->fresh()->status)->toBe(DeviceStatus::Revoked);

    $this->actingAs($admin)->get('/dispositivos?status=revoked')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('devices.data.0.id', $old->id)
            ->where('devices.data.0.replaced_by.model', 'Galaxy A55'));
});

it('niega dispositivos sin permiso y entre organizaciones', function () {
    $orgB = $this->organization('Empresa B');
    $workerB = $this->userIn($orgB, 'Colaborador');
    ['device' => $foreign] = $this->approvedDevice($workerB, $this->employee($orgB, $workerB));

    $orgA = $this->organization('Empresa A');
    $admin = $this->userIn($orgA, 'Administrador de empresa', allBranches: true);
    $plain = $this->userIn($orgA, 'Colaborador');

    $this->actingAs($plain)->get('/dispositivos')->assertForbidden();
    $this->actingAs($admin)->post("/dispositivos/{$foreign->id}/revocar", ['reason' => 'Intento ajeno'])->assertNotFound();

    $this->actingInOrganization($orgB);
    expect(Device::query()->find($foreign->id)->status)->toBe(DeviceStatus::Approved);
});

it('administra estructura con validación de geocercas y sin borrado físico', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/organizacion/sucursales', ['name' => 'Centro', 'code' => 'cen', 'latitude' => 19.4326, 'longitude' => -99.1332])
        ->assertSessionHasNoErrors();
    $branch = Branch::query()->where('code', 'CEN')->firstOrFail();

    $this->actingAs($admin)->post('/organizacion/geocercas', ['name' => 'Centro', 'branch_id' => $branch->id, 'latitude' => 120, 'longitude' => -99.1, 'radius_meters' => 10])
        ->assertSessionHasErrors(['latitude', 'radius_meters']);
    $this->actingAs($admin)->post('/organizacion/geocercas', ['name' => 'Centro', 'branch_id' => $branch->id, 'latitude' => 19.4326, 'longitude' => -99.1332, 'radius_meters' => 150])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)->get('/organizacion')->assertOk();

    $geofence = Geofence::query()->firstOrFail();
    $this->actingAs($admin)->post("/organizacion/geocercas/{$geofence->id}/desactivar")->assertSessionHasNoErrors();
    expect(Geofence::query()->find($geofence->id))->toBeNull()
        ->and(Geofence::withTrashed()->find($geofence->id))->not->toBeNull();
    $this->actingAs($admin)->post("/organizacion/geocercas/{$geofence->id}/reactivar")->assertSessionHasNoErrors();
    expect(Geofence::query()->find($geofence->id)?->active)->toBeTrue();
});

it('no desactiva una sucursal con colaboradores asignados', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $employee = $this->employee($org);
    $branchId = $employee->assignmentOn(now())->branch_id;

    $this->actingAs($admin)->post("/organizacion/sucursales/{$branchId}/desactivar")->assertSessionHasErrors('record');
});

it('aísla la estructura entre organizaciones', function () {
    $orgB = $this->organization('Empresa B');
    $foreign = Branch::query()->create(['code' => 'B1', 'name' => 'Ajena']);

    $orgA = $this->organization('Empresa A');
    $admin = $this->userIn($orgA, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->put("/organizacion/sucursales/{$foreign->id}", ['name' => 'Hackeada', 'code' => 'B1'])->assertNotFound();
    $this->actingAs($admin)->post('/organizacion/geocercas', ['name' => 'X', 'branch_id' => $foreign->id, 'latitude' => 19, 'longitude' => -99, 'radius_meters' => 100])
        ->assertSessionHasErrors('branch_id');

    $viewer = $this->userIn($orgA, 'Supervisor');
    $this->actingAs($viewer)->get('/organizacion')->assertForbidden();
});
