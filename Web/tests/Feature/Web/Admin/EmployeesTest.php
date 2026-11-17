<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentAssignment;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

function hirePayload(Branch $branch, array $overrides = []): array
{
    return array_merge([
        'employee_number' => 'A0001',
        'first_name' => 'Lucía',
        'last_name' => 'Hernández',
        'second_last_name' => 'Ruiz',
        'email' => 'lucia@example.com',
        'starts_on' => '2026-11-01',
        'contract_type' => 'indefinite',
        'work_modality' => 'onsite',
        'branch_id' => $branch->id,
    ], $overrides);
}

it('niega el listado de colaboradores sin permiso', function () {
    $org = $this->organization();
    $user = $this->userIn($org, 'Colaborador');

    $this->actingAs($user)->get('/colaboradores')->assertForbidden();
    $this->actingAs($user)->get('/colaboradores/nuevo')->assertForbidden();
});

it('lista colaboradores con permiso', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    $this->employee($org);

    $this->actingAs($admin)->get('/colaboradores?search=Ana')->assertOk();
});

it('aísla expedientes entre organizaciones', function () {
    $orgB = $this->organization('Empresa B');
    $foreign = $this->employee($orgB);

    $orgA = $this->organization('Empresa A');
    $admin = $this->userIn($orgA, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->get("/colaboradores/{$foreign->id}")->assertNotFound();
    $this->actingAs($admin)->put("/colaboradores/{$foreign->id}", ['employee_number' => 'X', 'first_name' => 'X', 'last_name' => 'Y'])->assertNotFound();
    $this->actingAs($admin)->post("/colaboradores/{$foreign->id}/baja", ['ends_on' => '2026-11-20', 'reason' => 'Prueba'])->assertNotFound();

    $this->actingInOrganization($orgB);
    expect(Employee::query()->find($foreign->id)->status->value)->toBe('active');
});

it('restringe el expediente al alcance por sucursal', function () {
    $org = $this->organization();
    $other = Branch::query()->create(['code' => 'NORTE', 'name' => 'Norte']);
    $employee = $this->employee($org, branch: $other);
    $manager = $this->userIn($org, 'Gerente de sucursal');

    $this->actingAs($manager)->get("/colaboradores/{$employee->id}")->assertForbidden();
});

it('da de alta un colaborador con relación laboral y asignación', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    $branch = Branch::query()->create(['code' => 'CENTRO', 'name' => 'Centro']);

    $this->actingAs($admin)->post('/colaboradores', hirePayload($branch))->assertRedirect();

    $employee = Employee::query()->where('employee_number', 'A0001')->firstOrFail();
    expect($employee->employments()->count())->toBe(1)
        ->and($employee->assignmentOn('2026-11-05')?->branch_id)->toBe($branch->id);
});

it('rechaza número de empleado duplicado y protege identificadores oficiales', function () {
    $org = $this->organization();
    $branch = Branch::query()->create(['code' => 'CENTRO', 'name' => 'Centro']);
    $rh = $this->userIn($org, 'Gerente de sucursal', allBranches: true);
    $rh->givePermissionTo('employees.create');

    $this->actingAs($rh)->post('/colaboradores', hirePayload($branch, ['curp' => 'HERL900101MDFRZC09']))
        ->assertSessionHasErrors('curp');

    $this->actingAs($rh)->post('/colaboradores', hirePayload($branch))->assertSessionHasNoErrors();
    $this->actingAs($rh)->post('/colaboradores', hirePayload($branch, ['first_name' => 'Otra']))->assertSessionHasErrors('employee_number');
});

it('sólo revela CURP/RFC/NSS con permiso y queda auditado', function () {
    $org = $this->organization();
    $employee = $this->employee($org);
    $employee->forceFill(['curp' => 'HERL900101MDFRZC09'])->save();

    $viewer = $this->userIn($org, 'Gerente de sucursal', allBranches: true);
    $this->actingAs($viewer)->postJson("/colaboradores/{$employee->id}/identificadores")->assertForbidden();

    $rh = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    $this->actingAs($rh)->postJson("/colaboradores/{$employee->id}/identificadores")
        ->assertOk()->assertJson(['curp' => 'HERL900101MDFRZC09']);

    $this->assertDatabaseHas('audit_logs', ['action' => 'employee.identifiers_viewed', 'subject_id' => $employee->id]);
});

it('baja y reingreso conservan el historial laboral', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    $employee = $this->employee($org, hiredOn: '2024-01-15');
    $branchId = $employee->assignmentOn('2024-02-01')->branch_id;

    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/baja", ['ends_on' => '2026-11-10', 'reason' => 'Renuncia voluntaria'])
        ->assertSessionHasNoErrors();

    $employee->refresh();
    expect($employee->status->value)->toBe('terminated');
    $first = $employee->employments()->firstOrFail();
    expect($first->ends_on->toDateString())->toBe('2026-11-10')
        ->and($first->assignments()->first()->effective_to->toDateString())->toBe('2026-11-10');

    // Reingreso antes de la baja: rechazado.
    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/reingreso", [
        'starts_on' => '2026-11-05', 'contract_type' => 'indefinite', 'work_modality' => 'onsite', 'branch_id' => $branchId,
    ])->assertSessionHasErrors('starts_on');

    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/reingreso", [
        'starts_on' => '2026-11-16', 'contract_type' => 'fixed_term', 'work_modality' => 'hybrid', 'branch_id' => $branchId,
    ])->assertSessionHasNoErrors();

    $employee->refresh();
    expect($employee->employments()->count())->toBe(2)
        ->and($employee->status->value)->toBe('active')
        ->and($employee->employmentOn('2025-06-01')?->id)->toBe($first->id)
        ->and($employee->employmentOn('2026-11-12'))->toBeNull()
        ->and($employee->employmentOn('2026-11-17')?->contract_type->value)->toBe('fixed_term');

    // La primera relación quedó intacta.
    expect($first->fresh()->starts_on->toDateString())->toBe('2024-01-15');
});

it('un movimiento cierra la asignación vigente sin alterar vigencias pasadas', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Recursos Humanos', allBranches: true);
    $employee = $this->employee($org, hiredOn: '2024-01-15');
    $original = $employee->assignmentOn('2025-01-01');
    $position = Position::query()->create(['code' => 'CAJ', 'name' => 'Cajero']);
    $today = CarbonImmutable::today();

    // Retroactivo: rechazado.
    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/movimientos", [
        'effective_from' => $today->subDay()->toDateString(), 'reason' => 'Ascenso', 'fields' => ['position_id'], 'position_id' => $position->id,
    ])->assertSessionHasErrors('effective_from');

    $this->actingAs($admin)->post("/colaboradores/{$employee->id}/movimientos", [
        'effective_from' => $today->addDays(3)->toDateString(), 'reason' => 'Ascenso', 'fields' => ['position_id'], 'position_id' => $position->id,
    ])->assertSessionHasNoErrors();

    $original->refresh();
    expect($original->effective_from->toDateString())->toBe('2024-01-15')
        ->and($original->effective_to->toDateString())->toBe($today->addDays(2)->toDateString())
        ->and($original->position_id)->toBeNull();

    $new = EmploymentAssignment::query()->where('employment_id', $original->employment_id)->whereKeyNot($original->id)->firstOrFail();
    expect($new->position_id)->toBe($position->id)
        ->and($new->branch_id)->toBe($original->branch_id)
        ->and($new->effective_from->toDateString())->toBe($today->addDays(3)->toDateString())
        ->and($employee->fresh()->assignmentOn('2025-06-01')->id)->toBe($original->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'employee.moved', 'subject_id' => $employee->id]);
});

it('crea la cuenta del colaborador con contraseña temporal aleatoria mostrada una vez', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $branch = Branch::query()->create(['code' => 'CENTRO', 'name' => 'Centro']);
    $this->actingAs($admin)->post('/colaboradores', hirePayload($branch));
    $employee = Employee::query()->where('employee_number', 'A0001')->firstOrFail();

    $response = $this->actingAs($admin)->post("/colaboradores/{$employee->id}/cuenta", ['email' => 'lucia.cuenta@example.com', 'mode' => 'password']);
    $response->assertSessionHas('temporary_password');
    $password = session('temporary_password');

    expect(strlen($password))->toBe(16)
        ->and($employee->fresh()->user_id)->not->toBeNull()
        ->and(Hash::check($password, $employee->fresh()->user->password))->toBeTrue();
    $this->assertDatabaseMissing('audit_logs', ['context' => json_encode(['temporary_password' => $password])]);
});
