<?php

use App\Models\Employee;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    // Ruta de prueba con enlace implícito de modelo dentro del grupo real.
    Route::middleware(['web', 'auth', 'organization'])
        ->get('/_test/empleado/{employee}', fn (Employee $employee) => $employee->id);
});

it('no resuelve por ID modelos de otra organización (route model binding)', function () {
    $orgA = $this->organization('Empresa A');
    $empA = $this->employee($orgA);

    $orgB = $this->organization('Empresa B');
    $userB = $this->userIn($orgB, 'Administrador de empresa', allBranches: true);

    app(CurrentOrganization::class)->set(null);

    $this->actingAs($userB)->get("/_test/empleado/{$empA->id}")->assertNotFound();
});
