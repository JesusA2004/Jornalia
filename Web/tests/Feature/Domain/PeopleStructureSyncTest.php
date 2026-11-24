<?php

use App\Domain\Integrations\People\HttpPeopleStructureSource;
use App\Domain\Integrations\People\PeopleStructureSource;
use App\Domain\Integrations\People\PeopleStructureSynchronizer;
use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\IntegrationRun;
use App\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

/**
 * Fuente en memoria (sin conexión productiva).
 *
 * @param  array<string, list<array<string, mixed>>>  $data
 */
function fakePeople(array $data, bool $configured = true): PeopleStructureSource
{
    return new class($data, $configured) implements PeopleStructureSource
    {
        public function __construct(private array $data, private bool $configured) {}

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function departments(): iterable
        {
            return $this->data['departments'] ?? [];
        }

        public function positions(): iterable
        {
            return $this->data['positions'] ?? [];
        }

        public function branches(): iterable
        {
            return $this->data['branches'] ?? [];
        }

        public function terminations(?string $since): iterable
        {
            return $this->data['terminations'] ?? [];
        }
    };
}

it('sincroniza estructura de forma idempotente por identificador externo', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador RH', allBranches: true);
    // Registro local previo con la misma clave: se vincula, no se duplica.
    $local = Department::query()->create(['name' => 'Ventas (local)', 'code' => 'VEN']);

    $source = fakePeople([
        'departments' => [
            ['id' => 'D2', 'code' => 'CRE', 'name' => 'Crédito', 'parent_id' => 'D1'],
            ['id' => 'D1', 'code' => 'ven', 'name' => 'Ventas'],
            ['id' => 'D3', 'code' => '', 'name' => 'Sin clave'],
        ],
        'positions' => [
            ['id' => 'P1', 'code' => 'ASE', 'name' => 'Asesora de crédito', 'department_id' => 'D2'],
            ['id' => 'P2', 'code' => 'GER', 'name' => 'Gerente', 'department_id' => 'DX'],
        ],
        'branches' => [
            ['id' => 'B1', 'code' => 'CVA-01', 'name' => 'Cuernavaca', 'region' => 'Centro', 'employer_registration' => 'A1234567101'],
            ['id' => 'B2', 'code' => 'TLX', 'name' => 'Tlaxcala', 'employer_registration' => 'x'],
        ],
    ]);

    $run = app(PeopleStructureSynchronizer::class)->run($admin, $source);

    expect($run->status)->toBe('partial')
        ->and($run->stats['errors'])->toBe(3)
        ->and(Department::query()->count())->toBe(2)
        ->and($local->fresh()->external_id)->toBe('D1')
        ->and($local->fresh()->name)->toBe('Ventas')
        ->and(Department::query()->where('external_id', 'D2')->value('parent_id'))->toBe($local->id)
        ->and(Position::query()->where('external_id', 'P1')->first()->department_id)->toBe(Department::query()->where('external_id', 'D2')->value('id'))
        ->and(Position::query()->where('external_id', 'P2')->exists())->toBeFalse()
        ->and(Branch::query()->where('external_id', 'B1')->value('employer_registration'))->toBe('A1234567101')
        ->and(Branch::query()->where('external_id', 'B2')->value('employer_registration'))->toBeNull();

    $types = collect($run->conflicts)->pluck('type')->all();
    expect($types)->toContain('incomplete', 'unknown_reference', 'invalid');

    // Segunda ejecución: sin duplicados ni cambios.
    $again = app(PeopleStructureSynchronizer::class)->run($admin, $source);
    expect(Department::query()->count())->toBe(2)
        ->and(Branch::query()->count())->toBe(2)
        ->and($again->stats['created'])->toBe(0)
        ->and($again->stats['updated'])->toBe(0);
});

it('no sobrescribe coordenadas ni centro de trabajo locales y respeta la simulación', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador RH', allBranches: true);
    $branch = Branch::query()->create(['name' => 'Matriz', 'code' => 'MTZ', 'latitude' => 18.92, 'longitude' => -99.23, 'external_source' => 'people', 'external_id' => 'B9']);

    $source = fakePeople(['branches' => [['id' => 'B9', 'code' => 'MTZ', 'name' => 'Matriz Cuernavaca']]]);

    $sim = app(PeopleStructureSynchronizer::class)->run($admin, $source, dryRun: true);
    expect($sim->status)->toBe('simulated')->and($branch->fresh()->name)->toBe('Matriz');

    app(PeopleStructureSynchronizer::class)->run($admin, $source);
    expect($branch->fresh()->name)->toBe('Matriz Cuernavaca')
        ->and((float) $branch->fresh()->latitude)->toBe(18.92);
});

it('aplica bajas de People revocando el acceso y reporta las incompletas', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador RH', allBranches: true);
    $employee = $this->employee($org);
    $employee->forceFill(['external_source' => 'people', 'external_id' => 'E-100'])->save();

    $source = fakePeople(['terminations' => [
        ['employee_id' => 'E-100', 'ends_on' => now('America/Mexico_City')->toDateString(), 'reason' => 'Renuncia voluntaria'],
        ['employee_id' => 'E-404', 'ends_on' => now('America/Mexico_City')->toDateString()],
        ['employee_id' => 'E-100'],
    ]]);

    $run = app(PeopleStructureSynchronizer::class)->run($admin, $source);

    expect($run->stats['terminated'])->toBe(1)
        ->and($run->stats['errors'])->toBe(2)
        ->and($employee->fresh()->status)->toBe(EmployeeStatus::Terminated)
        ->and(DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $employee->user_id)->value('status'))->toBe('inactive');

    // Idempotente: la misma baja no se aplica dos veces.
    $again = app(PeopleStructureSynchronizer::class)->run($admin, fakePeople(['terminations' => [['employee_id' => 'E-100', 'ends_on' => now('America/Mexico_City')->toDateString()]]]));
    expect($again->stats['terminated'])->toBe(0)->and($again->stats['unchanged'])->toBe(1);
});

it('sin credenciales queda «Sin configurar» y no simula conexión', function () {
    config(['services.people.url' => null, 'services.people.token' => null]);
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de plataforma', allBranches: true);
    Http::fake();

    $this->actingAs($admin)->get('/integraciones')->assertOk()
        ->assertInertia(fn ($page) => $page->component('integraciones/Index')->where('people.configured', false));

    $this->actingAs($admin)->post('/integraciones/people/sincronizar')->assertSessionHasNoErrors();
    Http::assertNothingSent();
    expect(IntegrationRun::query()->where('source', 'people_structure')->value('status'))->toBe('failed');
});

it('consume la API HTTP de People paginada y registra errores de la fuente', function () {
    config(['services.people.url' => 'https://people.test/api', 'services.people.token' => 'token-de-prueba']);
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de plataforma', allBranches: true);

    Http::fake([
        'people.test/api/departments*' => Http::sequence()
            ->push(['data' => [['id' => 'D1', 'code' => 'VEN', 'name' => 'Ventas']], 'next_page_url' => 'p2'])
            ->push(['data' => [['id' => 'D2', 'code' => 'CRE', 'name' => 'Crédito']], 'next_page_url' => null]),
        'people.test/api/positions*' => Http::response(['data' => [], 'next_page_url' => null]),
        'people.test/api/branches*' => Http::response(['data' => [], 'next_page_url' => null]),
        'people.test/api/terminations*' => Http::response(['message' => 'error'], 500),
    ]);

    $run = app(PeopleStructureSynchronizer::class)->run($admin, app(HttpPeopleStructureSource::class));

    // Un error de la fuente revierte todo el lote: nada a medias.
    expect($run->status)->toBe('failed')
        ->and(Department::query()->count())->toBe(0)
        ->and(collect($run->conflicts)->pluck('type'))->toContain('source_error');
});

it('sólo usuarios autorizados ven y sincronizan la integración', function () {
    $org = $this->organization();
    $viewer = $this->userIn($org, 'Contraloría', allBranches: true);
    $manager = $this->userIn($org, 'Gerente de sucursal');

    $this->actingAs($manager)->get('/integraciones')->assertForbidden();
    $this->actingAs($viewer)->post('/integraciones/people/sincronizar')->assertForbidden();
});
