<?php

use App\Domain\Security\AccessScope;
use App\Enums\PunchType;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Punch;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(fn () => $this->seedLegal());

it('aísla colaboradores y marcajes entre organizaciones', function () {
    $orgA = $this->organization('Empresa A');
    $empA = $this->employee($orgA, schedule: $this->schedule());
    $this->punchAt($empA, PunchType::ClockIn, '2026-11-17 15:00:00');

    $orgB = $this->organization('Empresa B');
    $empB = $this->employee($orgB, schedule: $this->schedule());

    // Contexto B: no ve nada de A
    expect(Employee::query()->pluck('id')->all())->toBe([$empB->id])
        ->and(Punch::query()->count())->toBe(0)
        ->and(Employee::query()->find($empA->id))->toBeNull();

    // Las cadenas son independientes por organización
    $this->actingInOrganization($orgA);
    expect(Punch::query()->firstOrFail()->sequence)->toBe(1);
});

it('limita el alcance por sucursal y supervisor', function () {
    $org = $this->organization();
    $north = Branch::query()->create(['name' => 'Norte', 'code' => 'NORTE']);
    $south = Branch::query()->create(['name' => 'Sur', 'code' => 'SUR']);

    $inNorth = $this->employee($org, branch: $north);
    $inSouth = $this->employee($org, branch: $south);

    $manager = $this->userIn($org, 'Gerente de sucursal');
    $manager->branches()->attach($north->id, ['organization_id' => $org->id]);

    $scope = app(AccessScope::class);
    expect($scope->canAccessEmployee($manager, $inNorth))->toBeTrue()
        ->and($scope->canAccessEmployee($manager, $inSouth))->toBeFalse();

    $hr = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    expect($scope->employees($hr)->count())->toBe(2);

    // Un colaborador sólo se ve a sí mismo
    expect($scope->employees($inSouth->user)->pluck('id')->all())->toBe([$inSouth->id]);
});
