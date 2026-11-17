<?php

use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

it('renderiza cada pantalla de administración con su componente', function (string $url, string $component) {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $employee = $this->employee($org, schedule: $this->schedule());
    $url = str_replace('{employee}', (string) $employee->id, $url);

    $this->actingAs($admin)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component, true));
})->with([
    ['/colaboradores', 'colaboradores/Index'],
    ['/colaboradores/nuevo', 'colaboradores/Create'],
    ['/colaboradores/{employee}', 'colaboradores/Show'],
    ['/colaboradores/importar', 'colaboradores/Import'],
    ['/horarios', 'horarios/Index'],
    ['/horarios/nuevo', 'horarios/Form'],
    ['/horarios/asignacion', 'horarios/Assignment'],
    ['/horarios/excepciones', 'horarios/Exceptions'],
    ['/dispositivos', 'dispositivos/Index'],
    ['/organizacion', 'organizacion/Index'],
    ['/usuarios', 'usuarios/Index'],
    ['/usuarios/roles', 'usuarios/Roles'],
    ['/configuracion', 'configuracion/Company'],
    ['/configuracion/asistencia', 'configuracion/Attendance'],
    ['/configuracion/autorizaciones', 'configuracion/Approvals'],
    ['/configuracion/incidencias', 'configuracion/Incidences'],
    ['/configuracion/vacaciones', 'configuracion/Vacations'],
    ['/configuracion/festivos', 'configuracion/Holidays'],
    ['/configuracion/documentos', 'configuracion/Documents'],
    ['/configuracion/normativa', 'configuracion/Legal'],
]);
