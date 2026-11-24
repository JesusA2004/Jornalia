<?php

use App\Domain\Scheduling\ScheduleResolver;
use App\Models\AuditLog;
use App\Models\Holiday;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsWorkforce;

/*
| Festivos administrables por RH preservando los obligatorios (Revisión RH, pantalla 08):
| los días del art. 74 precargados no se eliminan ni desactivan; RH los anota, renombra
| o registra el día movido como ajuste de su organización; agrega días obligatorios
| variables (jornada electoral) y administra los de empresa y locales.
*/

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    Artisan::call('asistencias:festivos', ['--anio' => 2027]);
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $this->platform = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
    $this->independence = Holiday::query()->whereNull('organization_id')->where('code', 'INDEPENDENCIA')->whereYear('date', 2027)->firstOrFail();
});

it('no elimina ni desactiva los días oficiales precargados, ni siquiera RH', function () {
    $this->actingAs($this->hr)->post("/configuracion/festivos/{$this->independence->id}/desactivar", ['reason' => 'Ya no aplica en la empresa'])
        ->assertSessionHasErrors('holiday');
    // Sistemas no administra días obligatorios.
    $this->actingAs($this->platform)->put("/configuracion/festivos/{$this->independence->id}", ['date' => '2027-09-16', 'name' => 'X', 'change_reason' => 'Prueba de cambio'])
        ->assertForbidden();

    expect($this->independence->fresh()->trashed())->toBeFalse()
        ->and($this->independence->fresh()->name)->toBe('Día de la Independencia');
});

it('RH registra el día movido por la ley como ajuste de su organización con motivo y bitácora', function () {
    $employee = $this->employee($this->org);

    $this->actingAs($this->hr)->put("/configuracion/festivos/{$this->independence->id}", ['date' => '2027-09-17', 'name' => 'Día de la Independencia'])
        ->assertSessionHasErrors('change_reason');
    $this->actingAs($this->hr)->put("/configuracion/festivos/{$this->independence->id}", ['date' => '2028-09-16', 'name' => 'Día de la Independencia', 'change_reason' => 'Decreto DOF'])
        ->assertSessionHasErrors('date');

    $this->actingAs($this->hr)->put("/configuracion/festivos/{$this->independence->id}", [
        'date' => '2027-09-17', 'name' => 'Día de la Independencia (recorrido)', 'notes' => 'Recorrido por decreto', 'change_reason' => 'Decreto publicado en el DOF',
    ])->assertSessionHasNoErrors();

    $adjustment = Holiday::query()->where('organization_id', $this->org->id)->where('replaces_holiday_id', $this->independence->id)->firstOrFail();
    expect($adjustment->date->toDateString())->toBe('2027-09-17')
        ->and($adjustment->kind)->toBe('official')
        ->and($adjustment->is_mandatory_rest)->toBeTrue()
        ->and($adjustment->change_reason)->toBe('Decreto publicado en el DOF')
        // El global queda intacto para las demás organizaciones.
        ->and($this->independence->fresh()->date->toDateString())->toBe('2027-09-16');

    // Para el cálculo de la organización el descanso obligatorio es el día movido.
    $resolver = app(ScheduleResolver::class);
    expect($resolver->holidayOn($employee, '2027-09-16'))->toBeNull()
        ->and($resolver->holidayOn($employee, '2027-09-17')?->id)->toBe($adjustment->id);

    $log = AuditLog::query()->where('action', 'holiday.official_moved')->firstOrFail();
    expect($log->old_values['date'])->toBe('2027-09-16')->and($log->new_values['date'])->toBe('2027-09-17');

    // El ajuste tampoco se desactiva; editarlo de nuevo actualiza el mismo registro.
    $this->actingAs($this->hr)->post("/configuracion/festivos/{$adjustment->id}/desactivar", ['reason' => 'Revertir el cambio'])->assertSessionHasErrors('holiday');
    $this->actingAs($this->hr)->put("/configuracion/festivos/{$adjustment->id}", ['date' => '2027-09-16', 'name' => 'Día de la Independencia', 'change_reason' => 'Se revirtió el decreto'])
        ->assertSessionHasNoErrors();
    expect(Holiday::query()->where('replaces_holiday_id', $this->independence->id)->count())->toBe(1)
        ->and($resolver->holidayOn($employee, '2027-09-16')?->id)->toBe($adjustment->id);

    // La pantalla muestra el ajuste en lugar del global.
    $this->actingAs($this->hr)->get('/configuracion/festivos?year=2027')->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage_mandatory', true)
            ->where('holidays', fn ($rows) => collect($rows)->where('code', 'INDEPENDENCIA')->count() === 1
                && collect($rows)->firstWhere('code', 'INDEPENDENCIA')['origin'] === 'adjusted'
                && collect($rows)->firstWhere('code', 'INDEPENDENCIA')['can_deactivate'] === false));
});

it('RH agrega un descanso obligatorio variable (jornada electoral) y sólo lo da de baja con motivo', function () {
    $employee = $this->employee($this->org);
    $payload = ['date' => '2027-06-06', 'name' => 'Jornada electoral', 'kind' => 'official', 'legal_basis' => 'LFT art. 74 fr. IX', 'change_reason' => 'Calendario electoral 2027'];

    $this->actingAs($this->platform)->post('/configuracion/festivos', $payload)->assertForbidden();
    $this->actingAs($this->hr)->post('/configuracion/festivos', ['change_reason' => null] + $payload)->assertSessionHasErrors('change_reason');
    $this->actingAs($this->hr)->post('/configuracion/festivos', $payload + ['is_mandatory_rest' => false])->assertSessionHasNoErrors();

    $electoral = Holiday::query()->where('organization_id', $this->org->id)->where('name', 'Jornada electoral')->firstOrFail();
    expect($electoral->kind)->toBe('official')
        ->and($electoral->is_mandatory_rest)->toBeTrue() // por ley siempre es obligatorio
        ->and(app(ScheduleResolver::class)->holidayOn($employee, '2027-06-06')?->id)->toBe($electoral->id);

    $this->actingAs($this->hr)->post("/configuracion/festivos/{$electoral->id}/desactivar")->assertSessionHasErrors('reason');
    $this->actingAs($this->hr)->post("/configuracion/festivos/{$electoral->id}/desactivar", ['reason' => 'Se capturó con fecha errónea'])->assertSessionHasNoErrors();
    expect($electoral->fresh()->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'holiday.deactivated')->value('context')['reason'])->toBe('Se capturó con fecha errónea');

    $this->actingAs($this->hr)->post("/configuracion/festivos/{$electoral->id}/reactivar")->assertSessionHasNoErrors();
    expect($electoral->fresh()->trashed())->toBeFalse();
});

it('los días de empresa no obligatorios se editan y se dan de baja lógica', function () {
    $this->actingAs($this->platform)->post('/configuracion/festivos', ['date' => '2027-12-24', 'name' => 'Nochebuena', 'kind' => 'company', 'is_mandatory_rest' => false])
        ->assertSessionHasNoErrors();
    $day = Holiday::query()->where('organization_id', $this->org->id)->where('code', 'NOCHEBUENA')->firstOrFail();

    // Un día de empresa no se convierte en obligatorio por ley.
    $this->actingAs($this->hr)->put("/configuracion/festivos/{$day->id}", ['date' => '2027-12-24', 'name' => 'Nochebuena', 'kind' => 'official', 'is_mandatory_rest' => false])
        ->assertSessionHasErrors('kind');
    $this->actingAs($this->platform)->put("/configuracion/festivos/{$day->id}", ['date' => '2027-12-24', 'name' => 'Nochebuena (medio día)', 'kind' => 'company', 'is_mandatory_rest' => false])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->platform)->post("/configuracion/festivos/{$day->id}/desactivar")->assertSessionHasNoErrors();

    expect($day->fresh()->name)->toBe('Nochebuena (medio día)')
        ->and($day->fresh()->trashed())->toBeTrue()
        ->and(Holiday::withTrashed()->whereKey($day->id)->exists())->toBeTrue();
});
