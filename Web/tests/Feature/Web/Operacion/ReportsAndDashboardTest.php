<?php

use App\Domain\Reports\ReportCatalog;
use App\Enums\PunchType;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    // Disco propio por proceso: otras suites en paralelo limpian el disco «fake» compartido.
    $root = storage_path('framework/testing/disks/reports-dash-'.getmypid());
    (new Filesystem)->cleanDirectory($root);
    config(['filesystems.disks.local.root' => $root]);
    Storage::forgetDisk('local');
    $this->org = $this->organization('Comercial Lana');
    $this->admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);
    $this->employee = $this->employee($this->org, schedule: $this->schedule());
    // Martes 17-nov-2026: entrada 09:25 local (retardo), salida 19:00.
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:25:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 01:00:00');
    // Miércoles 18-nov: entrada puntual, sigue en jornada.
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-18 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
});

it('muestra el panel ejecutivo con datos reales del periodo', function () {
    $this->actingAs($this->admin)->get('/dashboard?period=7d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('mode', 'executive')
            ->where('metrics.today.active_employees', 1)
            ->where('metrics.today.scheduled', 1)
            ->where('metrics.today.present', 1)
            ->where('metrics.today.open_sessions', 1)
            ->where('metrics.totals.late_days', 1)
            ->where('metrics.totals.punches', 3)
            ->where('metrics.totals.worked_minutes', fn ($v) => $v >= 575)
            ->has('metrics.trend', 7)
            ->where('metrics.branches.0.name', 'Matriz'));
});

it('previsualiza cada reporte del catálogo sin errores', function (string $key) {
    $this->actingAs($this->admin)->get("/reportes/{$key}?from=2026-11-01&to=2026-11-18")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reportes/Show')->where('report.key', $key)->has('rows.data'));
})->with(fn () => ReportCatalog::keys());

it('genera un Excel válido y lo descarga con su huella', function () {
    $this->actingAs($this->admin)->post('/reportes/exportar', [
        'report' => 'asistencia_diaria', 'format' => 'xlsx', 'from' => '2026-11-16', 'to' => '2026-11-18',
    ])->assertRedirect();

    $export = ReportExport::query()->sole();
    expect($export->status)->toBe('completed')->and($export->row_count)->toBeGreaterThanOrEqual(2);

    $content = Storage::disk('local')->get($export->path);
    expect(substr($content, 0, 2))->toBe('PK')
        ->and(hash('sha256', $content))->toBe($export->sha256);

    $response = $this->actingAs($this->admin)->get("/reportes/descargas/{$export->id}");
    $response->assertOk()
        ->assertHeader('X-Report-Id', $export->uuid)
        ->assertHeader('X-Report-Sha256', $export->sha256);
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('genera un PDF válido con el reporte de inspección laboral', function () {
    $this->actingAs($this->admin)->post('/reportes/exportar', [
        'report' => 'inspeccion_laboral', 'format' => 'pdf', 'from' => '2026-11-16', 'to' => '2026-11-18',
        'observations' => 'Requerimiento de inspección',
    ])->assertRedirect();

    $export = ReportExport::query()->sole();
    expect($export->status)->toBe('completed')->and($export->row_count)->toBe(3);
    expect(substr(Storage::disk('local')->get($export->path), 0, 5))->toBe('%PDF-')
        ->and($export->parameters['data_sha256'] ?? null)->toHaveLength(64);
});

it('genera un CSV con encabezados y filas', function () {
    $this->actingAs($this->admin)->post('/reportes/exportar', [
        'report' => 'historial_marcajes', 'format' => 'csv', 'from' => '2026-11-16', 'to' => '2026-11-18',
    ])->assertRedirect();

    $export = ReportExport::query()->sole();
    $content = Storage::disk('local')->get($export->path);
    $lines = array_values(array_filter(explode("\n", trim($content))));
    // Encabezado institucional, encabezados de columna y una fila por marcaje.
    $table = array_values(array_filter($lines, fn ($l) => str_contains($l, ',Entrada,') || str_contains($l, ',Salida,')));
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain('Razón social')->toContain('Secuencia')
        ->and($table)->toHaveCount(3)
        ->and($export->row_count)->toBe(3);
});

it('no permite descargar exportaciones de otro usuario', function () {
    $this->actingAs($this->admin)->post('/reportes/exportar', [
        'report' => 'faltas', 'format' => 'csv', 'from' => '2026-11-16', 'to' => '2026-11-18',
    ]);
    $export = ReportExport::query()->sole();
    $other = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);

    $this->actingAs($other)->get("/reportes/descargas/{$export->id}")->assertNotFound();
});

it('valida parámetros de exportación', function () {
    $this->actingAs($this->admin)->post('/reportes/exportar', ['report' => 'inexistente', 'format' => 'doc', 'from' => '2026-11-18', 'to' => '2026-11-01'])
        ->assertSessionHasErrors(['report', 'format', 'to']);
});
