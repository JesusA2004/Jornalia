<?php

use App\Models\Holiday;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsWorkforce;

/*
| Calendario (Revisión RH, pantalla 08): precarga anual idempotente de los días de
| descanso obligatorio del art. 74 LFT, editable por RH.
*/

uses(BuildsWorkforce::class);

beforeEach(fn () => $this->seedLegal());

function officialHolidays(int $year): array
{
    return Holiday::query()->withTrashed()->whereNull('organization_id')->whereYear('date', $year)
        ->orderBy('date')->get()->mapWithKeys(fn (Holiday $h) => [$h->code => $h->date->toDateString()])->all();
}

it('precarga los días de descanso obligatorio del año con sus reglas (art. 74)', function () {
    Artisan::call('asistencias:festivos', ['--anio' => 2032]);

    expect(officialHolidays(2032))->toBe([
        'ANIO_NUEVO' => '2032-01-01',
        'CONSTITUCION' => '2032-02-02',      // primer lunes de febrero
        'BENITO_JUAREZ' => '2032-03-15',     // tercer lunes de marzo
        'DIA_TRABAJO' => '2032-05-01',
        'INDEPENDENCIA' => '2032-09-16',
        'REVOLUCION' => '2032-11-15',        // tercer lunes de noviembre
        'NAVIDAD' => '2032-12-25',
    ]);

    Artisan::call('asistencias:festivos', ['--anio' => 2030]);
    expect(officialHolidays(2030))->toHaveKey('TRANSMISION_PODER', '2030-10-01'); // cada seis años
});

it('es idempotente y respeta lo que RH editó o dio de baja', function () {
    Artisan::call('asistencias:festivos', ['--anio' => 2032]);
    $count = Holiday::query()->withTrashed()->count();

    Holiday::query()->where('code', 'NAVIDAD')->whereYear('date', 2032)->firstOrFail()->update(['name' => 'Navidad (editado por RH)']);
    Holiday::query()->where('code', 'DIA_TRABAJO')->whereYear('date', 2032)->firstOrFail()->delete(); // baja lógica

    Artisan::call('asistencias:festivos', ['--anio' => 2032]);

    expect(Holiday::query()->withTrashed()->count())->toBe($count)
        ->and(Holiday::query()->where('code', 'NAVIDAD')->whereYear('date', 2032)->value('name'))->toBe('Navidad (editado por RH)')
        ->and(Holiday::query()->where('code', 'DIA_TRABAJO')->whereYear('date', 2032)->exists())->toBeFalse()
        ->and(Holiday::onlyTrashed()->where('code', 'DIA_TRABAJO')->whereYear('date', 2032)->exists())->toBeTrue();
});

it('programa la precarga anual en el scheduler', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

    expect($commands)->toContain('asistencias:festivos');
});
