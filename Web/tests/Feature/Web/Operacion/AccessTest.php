<?php

use App\Enums\PunchType;
use App\Models\AttendancePolicy;
use App\Models\Punch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->collaborator = $this->userIn($this->org, 'Colaborador');
    $this->employee = $this->employee($this->org, $this->collaborator, $this->schedule());
});

dataset('protected pages', [
    'asistencias' => ['/asistencias'],
    'rectificaciones' => ['/rectificaciones'],
    'incidencias' => ['/incidencias'],
    'vacaciones' => ['/vacaciones'],
    'horas extra' => ['/horas-extra'],
    'calendario' => ['/calendario'],
    'reportes' => ['/reportes'],
    'reporte' => ['/reportes/asistencia_diaria'],
    'cumplimiento' => ['/cumplimiento'],
    'integridad' => ['/cumplimiento/integridad'],
    'bitacora' => ['/cumplimiento/bitacora'],
    'ubicacion' => ['/ubicacion'],
]);

it('niega con 403 cada módulo a un colaborador sin permisos', function (string $url) {
    $this->actingAs($this->collaborator)->get($url)->assertForbidden();
})->with('protected pages');

it('permite cada módulo al administrador de la empresa', function (string $url) {
    $admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);
    $this->actingAs($admin)->get($url)->assertOk();
})->with('protected pages');

it('niega acciones de escritura sin permiso aunque se tenga lectura', function () {
    $auditor = $this->userIn($this->org, 'Auditor de cumplimiento', allBranches: true);

    $this->actingAs($auditor)->post('/rectificaciones/1/decision', ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($auditor)->post('/incidencias/1/decision', ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($auditor)->post('/vacaciones/1/decision', ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($auditor)->post('/horas-extra/1/autorizacion', ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($auditor)->post('/cumplimiento/alertas/1', ['status' => 'resolved', 'note' => 'nota'])->assertForbidden();
});

it('la autoridad laboral consulta y exporta pero no resuelve ni aprueba', function () {
    $authority = $this->userIn($this->org, 'Autoridad laboral', allBranches: true);

    $this->actingAs($authority)->get('/dashboard')->assertRedirect('/asistencias');
    $this->actingAs($authority)->get('/reportes/inspeccion_laboral')->assertOk();
    $this->actingAs($authority)->get('/cumplimiento/integridad')->assertOk();
    $this->actingAs($authority)->get('/cumplimiento/bitacora')->assertOk();
    $this->actingAs($authority)->post('/cumplimiento/integridad/verificar')->assertForbidden();
    $this->actingAs($authority)->get('/cumplimiento')->assertOk();
    $this->actingAs($authority)->post('/reportes/exportar', ['report' => 'inspeccion_laboral', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-18'])->assertRedirect();
    $this->actingAs($authority)->post('/cumplimiento/alertas/1', ['status' => 'resolved', 'note' => 'nota larga'])->assertForbidden();
    $this->actingAs($authority)->post('/rectificaciones/1/decision', ['decision' => 'approve'])->assertForbidden();
});

it('muestra al colaborador su resumen personal en el panel', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-18 15:02:00');

    $this->actingAs($this->collaborator)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('mode', 'personal')
            ->where('metrics', null)
            ->where('personal.employee.id', $this->employee->id)
            ->where('personal.web_punch.next_type', 'clock_out')
            ->has('personal.today.events', 1));
});

it('rechaza el marcaje web cuando la política no lo permite', function () {
    AttendancePolicy::query()->update(['web_punch_allowed' => false]);

    $this->actingAs($this->collaborator)
        ->from('/dashboard')
        ->post('/asistencias/marcar', ['type' => 'clock_in', 'client_uuid' => (string) Str::uuid()])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('punch');

    expect(Punch::query()->count())->toBe(0);
});

it('registra el marcaje web propio cuando la política lo permite', function () {
    AttendancePolicy::query()->update(['web_punch_allowed' => true, 'location_mode' => 'free']);

    $this->actingAs($this->collaborator)
        ->from('/dashboard')
        ->post('/asistencias/marcar', ['type' => 'clock_in', 'client_uuid' => (string) Str::uuid()])
        ->assertRedirect('/dashboard')
        ->assertSessionHasNoErrors();

    $punch = Punch::query()->sole();
    expect($punch->employee_id)->toBe($this->employee->id)
        ->and($punch->source->value)->toBe('web')
        ->and($punch->auth_method->value)->toBe('web_session');
});

it('no expone rutas de edición ni borrado de marcajes', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'asistencias') || str_starts_with($r->uri(), 'rectificaciones') || str_starts_with($r->uri(), 'reportes'));

    expect($routes->flatMap->methods()->intersect(['PUT', 'PATCH', 'DELETE'])->all())->toBe([]);
});
