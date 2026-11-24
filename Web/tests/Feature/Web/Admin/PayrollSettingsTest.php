<?php

use App\Domain\Reports\MealPause;
use App\Domain\Reports\Noi\NoiConfig;
use App\Models\AuditLog;
use Tests\Concerns\BuildsWorkforce;

/*
| Configuración › Nómina: claves NOI y pausa de comida por organización, editables
| sólo por RH (`settings.incidences`) y con bitácora.
*/

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $this->platform = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
});

function noiPayload(array $codes = []): array
{
    $config = NoiConfig::defaults();
    foreach ($codes as $key => $code) {
        $config['concepts'][$key]['code'] = $code;
    }

    return $config;
}

it('muestra las claves NOI iniciales como pendientes de RH', function () {
    expect(NoiConfig::pendingConcepts(NoiConfig::current()))->toBe(['absence', 'vacation', 'overtime_double', 'overtime_triple']);

    $this->actingAs($this->hr)->get('/configuracion/nomina')->assertOk()
        ->assertInertia(fn ($page) => $page->component('configuracion/Payroll')
            ->where('noi_pending', ['absence', 'vacation', 'overtime_double', 'overtime_triple'])
            ->where('meal_pause.configured', false)
            ->where('can.manage', true));
});

it('RH guarda claves y columnas NOI con validación y bitácora; Sistemas no', function () {
    $payload = noiPayload(['absence' => 'F01', 'vacation' => 'V01', 'overtime_double' => 'HE2', 'overtime_triple' => 'HE3']);

    $this->actingAs($this->platform)->put('/configuracion/nomina/noi', $payload)->assertForbidden();
    // Claves repetidas y columnas obligatorias.
    $this->actingAs($this->hr)->put('/configuracion/nomina/noi', noiPayload(['absence' => 'HE2', 'overtime_double' => 'HE2']))
        ->assertSessionHasErrors('concepts.absence.code');
    $this->actingAs($this->hr)->put('/configuracion/nomina/noi', ['columns' => [['key' => 'employee_name', 'label' => 'Nombre']]] + $payload)
        ->assertSessionHasErrors('columns');

    $this->actingAs($this->hr)->put('/configuracion/nomina/noi', $payload)->assertSessionHasNoErrors();

    $config = NoiConfig::current();
    expect($config['concepts']['absence']['code'])->toBe('F01')
        ->and(NoiConfig::pendingConcepts($config))->toBe([])
        ->and(AuditLog::query()->where('action', 'reports.noi_updated')->first()?->new_values['concepts']['absence']['code'])->toBe('F01');
});

it('la pausa de comida es un ajuste por organización que leen los reportes', function () {
    config(['reports.meal_pause' => 0]);
    expect(MealPause::current($this->org->id))->toBe(0);

    $this->actingAs($this->platform)->put('/configuracion/nomina/comida', ['pause' => 2, 'confirmed_by_hr' => true])->assertForbidden();
    $this->actingAs($this->hr)->put('/configuracion/nomina/comida', ['pause' => 9, 'confirmed_by_hr' => true])->assertSessionHasErrors('pause');
    $this->actingAs($this->hr)->put('/configuracion/nomina/comida', ['pause' => 2, 'confirmed_by_hr' => true])->assertSessionHasNoErrors();

    expect(MealPause::settings($this->org->id))->toBe(['pause' => 2, 'confirmed_by_hr' => true, 'configured' => true])
        ->and(AuditLog::query()->where('action', 'reports.meal_pause_updated')->first()?->old_values['pause'])->toBe(0);

    // Otra organización conserva el valor inicial.
    $other = $this->organization('Otra');
    expect(MealPause::current($other->id))->toBe(0);
});
