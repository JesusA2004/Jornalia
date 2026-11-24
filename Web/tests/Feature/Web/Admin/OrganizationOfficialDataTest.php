<?php

use App\Domain\Organization\OfficialCompanyData;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Models\WorkCenter;
use Inertia\Support\SessionKey;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

it('carga los datos oficiales en vacío y es idempotente', function () {
    $org = $this->organization();
    $org->forceFill(['name' => 'Mr. Lana'])->save();

    $this->artisan('asistencias:datos-oficiales', ['--organizacion' => $org->id])->assertSuccessful();

    $entity = LegalEntity::query()->sole();
    expect($entity->legal_name)->toBe(OfficialCompanyData::LEGAL_NAME)
        ->and($entity->rfc)->toBe(OfficialCompanyData::RFC)
        ->and($entity->fiscal_address)->toBe(OfficialCompanyData::FISCAL_ADDRESS)
        ->and($entity->employer_registration)->toBeNull()
        ->and($org->fresh()->name)->toBe(OfficialCompanyData::TRADE_NAME);

    $this->artisan('asistencias:datos-oficiales', ['--organizacion' => $org->id])->assertSuccessful();
    expect(LegalEntity::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'organization.official_data_loaded')->count())->toBe(1);
});

it('no sobrescribe valores capturados distintos sin --forzar y registra antes/después al forzar', function () {
    $org = $this->organization('Corporativo MR LANA');
    $original = $org->name;
    LegalEntity::query()->create(['legal_name' => 'Otra Razón, S.A. de C.V.', 'rfc' => null, 'fiscal_address' => null]);

    $this->artisan('asistencias:datos-oficiales', ['--organizacion' => $org->id])->assertFailed();

    $entity = LegalEntity::query()->sole();
    // Los vacíos se llenan; los distintos se conservan.
    expect($entity->legal_name)->toBe('Otra Razón, S.A. de C.V.')
        ->and($entity->rfc)->toBe(OfficialCompanyData::RFC)
        ->and($org->fresh()->name)->toBe($original);

    $this->artisan('asistencias:datos-oficiales', ['--organizacion' => $org->id, '--forzar' => true])->assertSuccessful();
    expect($entity->fresh()->legal_name)->toBe(OfficialCompanyData::LEGAL_NAME)
        ->and($org->fresh()->name)->toBe(OfficialCompanyData::TRADE_NAME);

    $log = AuditLog::query()->where('action', 'organization.official_data_loaded')->latest('id')->first();
    expect(collect($log->context['changes'])->firstWhere('field', 'legal_name'))
        ->toMatchArray(['before' => 'Otra Razón, S.A. de C.V.', 'after' => OfficialCompanyData::LEGAL_NAME]);
});

it('exige indicar la organización cuando hay varias', function () {
    $this->organization('A');
    $this->organization('B');

    $this->artisan('asistencias:datos-oficiales')->assertFailed();
});

it('carga los datos oficiales desde la web y avisa conflictos sin sobrescribir', function () {
    $org = $this->organization('Corporativo');
    $original = $org->name;
    $admin = $this->userIn($org, 'Administrador de plataforma', allBranches: true);

    $this->actingAs($admin)->post('/organizacion/datos-oficiales')->assertSessionHasNoErrors();
    expect($org->fresh()->name)->toBe($original)
        ->and(LegalEntity::query()->sole()->rfc)->toBe(OfficialCompanyData::RFC);

    $this->actingAs($admin)->post('/organizacion/datos-oficiales', ['force' => true])->assertSessionHasNoErrors();
    expect($org->fresh()->name)->toBe(OfficialCompanyData::TRADE_NAME);

    $viewer = $this->userIn($org, 'Contraloría');
    $this->actingAs($viewer)->post('/organizacion/datos-oficiales', ['force' => true])->assertForbidden();
});

it('registra cada sucursal como centro de trabajo con registro patronal sin inventar datos', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de plataforma', allBranches: true);
    $branch = Branch::query()->create(['name' => 'Cuernavaca Centro', 'code' => 'CVA-01', 'address' => 'Domicilio capturado']);

    $this->actingAs($admin)->put("/organizacion/sucursales/{$branch->id}", [
        'name' => 'Cuernavaca Centro', 'code' => 'CVA-01', 'address' => 'Domicilio capturado', 'employer_registration' => 'reg-inválido',
    ])->assertSessionHasErrors('employer_registration');

    $this->actingAs($admin)->put("/organizacion/sucursales/{$branch->id}", [
        'name' => 'Cuernavaca Centro', 'code' => 'CVA-01', 'address' => 'Domicilio capturado', 'employer_registration' => 'A1234567101',
    ])->assertSessionHasNoErrors();
    expect($branch->fresh()->employer_registration)->toBe('A1234567101');

    $this->actingAs($admin)->post("/organizacion/sucursales/{$branch->id}/centro-trabajo")->assertSessionHasNoErrors();
    $center = WorkCenter::query()->sole();
    expect($branch->fresh()->work_center_id)->toBe($center->id)
        ->and($center->address)->toBe('Domicilio capturado')
        ->and($center->state_code)->toBeNull();

    // Idempotente.
    $this->actingAs($admin)->post("/organizacion/sucursales/{$branch->id}/centro-trabajo")->assertSessionHasNoErrors();
    expect(WorkCenter::query()->count())->toBe(1);

    $this->actingAs($admin)->get('/organizacion?tab=branches')->assertOk()
        ->assertInertia(fn ($page) => $page->component('organizacion/Index')
            ->where('branches.0.employer_registration', 'A1234567101')
            ->has('official.conflicts')
            ->where('people.configured', false));
});

it('valida el radio de geocerca y advierte fuera del rango sugerido', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de plataforma', allBranches: true);
    $branch = Branch::query()->create(['name' => 'Matriz', 'code' => 'MTZ']);
    $base = ['name' => 'Matriz', 'branch_id' => $branch->id, 'latitude' => 18.92, 'longitude' => -99.23];

    $this->actingAs($admin)->post('/organizacion/geocercas', $base + ['radius_meters' => 25])->assertSessionHasErrors('radius_meters');
    $this->actingAs($admin)->post('/organizacion/geocercas', $base + ['radius_meters' => 1500])->assertSessionHasErrors('radius_meters');

    $this->actingAs($admin)->post('/organizacion/geocercas', $base + ['radius_meters' => 300])
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.toast.type', 'warning');

    $this->actingAs($admin)->post('/organizacion/geocercas', $base + ['radius_meters' => 120])
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.toast.type', 'success');
});
