<?php

use App\Domain\Reports\Dataset\DatasetRow;
use App\Domain\Reports\Dataset\ReportDataset;
use App\Domain\Reports\Noi\NoiConfig;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\Support\SpreadsheetSafe;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OrganizationSetting;
use App\Models\OvertimeRecord;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\WorkdaySummary;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/**
 * Semana del lunes 16 al domingo 22 de noviembre de 2026 (America/Mexico_City, UTC−6).
 * Horario 09:00–18:00 lunes a viernes.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    // Disco propio por proceso: otras suites que corren en paralelo limpian el disco «fake» compartido.
    $root = storage_path('framework/testing/disks/reports-'.getmypid());
    (new Filesystem)->cleanDirectory($root);
    config(['filesystems.disks.local.root' => $root]);
    Storage::forgetDisk('local');
    $this->org = $this->organization('Comercial Lana');
    $this->hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->employee = $this->employee($this->org, schedule: $this->schedule());

    // Martes: 09:25 → 19:00 (retardo y tiempo extra).
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:25:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 01:00:00');
    // Miércoles: 09:00 → 18:00 con descanso 12:00–13:00.
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-18 15:00:00');
    $this->punchAt($this->employee, PunchType::BreakStart, '2026-11-18 18:00:00');
    $this->punchAt($this->employee, PunchType::BreakEnd, '2026-11-18 19:00:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-19 00:00:00');
    // Jueves: entrada sin salida (omisión). Viernes: sin marcaje (falta).
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-19 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-23 18:00:00', 'UTC'));

    $this->params = fn (array $extra = []) => ReportParameters::fromArray(['from' => '2026-11-16', 'to' => '2026-11-22', ...$extra], 'America/Mexico_City');
    $this->dataset = fn (string $key, array $extra = [], ?User $user = null) => app(ReportExporter::class)->dataset(
        ReportCatalog::definition($key), ($this->params)($extra), $user ?? $this->hr, app(CurrentOrganization::class)->require(), 'REP-PRUEBA',
    );
});

/**
 * @return array{data: list<array<string, mixed>>, groups: list<DatasetRow>, subtotals: list<DatasetRow>}
 */
function collectDataset(ReportDataset $dataset): array
{
    $out = ['data' => [], 'groups' => [], 'subtotals' => []];
    foreach ($dataset->rows() as $row) {
        match ($row->kind) {
            DatasetRow::ROW => $out['data'][] = $row->values,
            DatasetRow::GROUP => $out['groups'][] = $row,
            default => $out['subtotals'][] = $row,
        };
    }

    return $out;
}

it('incluye una fila por día del periodo: sin marcaje, descansos y festivos no desaparecen', function () {
    $before = WorkdaySummary::query()->count();
    $rows = collectDataset(($this->dataset)('asistencia_detallada'));

    expect($rows['data'])->toHaveCount(7)
        ->and(array_column($rows['data'], 'date'))->toBe(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22']);

    $byDate = collect($rows['data'])->keyBy('date');
    expect($byDate['2026-11-17']['late_minutes'])->toBe(25)
        ->and($byDate['2026-11-17']['first_in'])->toBe('2026-11-17T15:25:00Z')
        ->and($byDate['2026-11-18']['break_start'])->toBe('2026-11-18T18:00:00Z')
        ->and($byDate['2026-11-18']['break_end'])->toBe('2026-11-18T19:00:00Z')
        ->and($byDate['2026-11-19']['omission'])->toContain('Sin salida')
        ->and($byDate['2026-11-20']['status'])->toBe('absent')
        ->and($byDate['2026-11-21']['status_label'])->toBe('Descanso')
        ->and($byDate['2026-11-22']['schedule'])->toBe('Descanso')
        ->and($byDate['2026-11-17']['punch_origin'])->toBe('Web')
        ->and($byDate['2026-11-16']['status'])->toBeIn(['holiday', 'absent']);

    // Los días sin proyección se calculan con WorkdayCalculator sin persistir nada.
    expect(WorkdaySummary::query()->count())->toBe($before);
});

it('agrupa por colaborador y semana con subtotales y total general consistentes', function () {
    $dataset = ($this->dataset)('asistencia_detallada');
    $rows = collectDataset($dataset);

    expect($rows['groups'])->toHaveCount(2)
        ->and($rows['groups'][0]->level)->toBe(0)
        ->and($rows['groups'][1]->label)->toBe('Semana del 16/11/2026 al 22/11/2026')
        ->and($rows['subtotals'])->toHaveCount(2);

    $worked = array_sum(array_column($rows['data'], 'worked_minutes'));
    $totals = $dataset->totals();
    expect($totals['worked_minutes'])->toBe($worked)
        ->and($totals['late_minutes'])->toBe(25)
        ->and($rows['subtotals'][0]->values['worked_minutes'])->toBe($worked)
        ->and($rows['subtotals'][1]->count)->toBe(7)
        ->and($totals['ordinary_minutes'] + $totals['extra_minutes'])->toBe($worked)
        ->and($dataset->rowCount())->toBe(7)
        ->and($dataset->dataSha256())->toHaveLength(64);
});

it('los concentrados cuadran con el detalle día por día', function () {
    $detail = collectDataset(($this->dataset)('asistencia_detallada'))['data'];
    $byBranch = collectDataset(($this->dataset)('por_sucursal'))['data'];
    $byPeriod = collectDataset(($this->dataset)('por_colaborador_periodo'))['data'];

    expect($byBranch)->toHaveCount(1)
        ->and($byBranch[0]['group_name'])->toBe('Matriz')
        ->and($byBranch[0]['employees'])->toBe(1)
        ->and($byBranch[0]['worked_minutes'])->toBe(array_sum(array_column($detail, 'worked_minutes')))
        ->and($byBranch[0]['absent_days'])->toBe(count(array_filter($detail, fn ($d) => $d['status'] === 'absent')))
        ->and($byPeriod)->toHaveCount(1)
        ->and($byPeriod[0]['period'])->toBe('Semana 16/11 – 22/11/2026')
        ->and($byPeriod[0]['omission_days'])->toBe(1);
});

it('filtra retardos, faltas y omisiones desde la misma proyección', function () {
    expect(collectDataset(($this->dataset)('retardos'))['data'])->toHaveCount(1)
        ->and(collectDataset(($this->dataset)('omisiones'))['data'][0]['date'])->toBe('2026-11-19')
        ->and(array_column(collectDataset(($this->dataset)('faltas'))['data'], 'date'))->toContain('2026-11-20')
        ->and(collectDataset(($this->dataset)('descansos'))['data'][0]['break_minutes'])->toBe(60);
});

it('respeta el alcance del usuario: un gerente sólo ve su sucursal', function () {
    $other = Branch::query()->create(['name' => 'Norte', 'code' => 'NORTE', 'region' => 'Norte']);
    $outsider = $this->employee($this->org, schedule: $this->schedule(), branch: $other);
    $outsider->update(['first_name' => 'Bruno']);
    $manager = $this->userIn($this->org, 'Gerente de sucursal');
    $manager->branches()->attach(Branch::query()->where('code', 'MATRIZ')->value('id'), ['organization_id' => $this->org->id]);

    $names = array_unique(array_column(collectDataset(($this->dataset)('asistencia_detallada', [], $manager))['data'], 'employee_name'));
    expect($names)->toBe(['Ana Prueba']);

    $this->actingAs($manager)->post('/reportes/exportar', [
        'report' => 'asistencia_detallada', 'format' => 'csv', 'from' => '2026-11-16', 'to' => '2026-11-22', 'employee_id' => $outsider->id,
    ])->assertNotFound();
    expect(ReportExport::query()->count())->toBe(0);

    // Por región: sólo las sucursales de esa región.
    $north = collectDataset(($this->dataset)('asistencia_detallada', ['region' => 'Norte']))['data'];
    expect(array_unique(array_column($north, 'employee_name')))->toBe(['Bruno Prueba']);
});

it('registra en bitácora la solicitud, generación y descarga con folio', function () {
    $this->actingAs($this->hr)->post('/reportes/exportar', [
        'report' => 'asistencia_detallada', 'format' => 'xlsx', 'from' => '2026-11-16', 'to' => '2026-11-22',
    ])->assertRedirect();

    $export = ReportExport::query()->sole();
    expect($export->status)->toBe('completed')
        ->and($export->folio)->toMatch('/^REP-\d{4}-\d{6}$/')
        ->and($export->row_count)->toBe(7);

    $this->actingAs($this->hr)->get("/reportes/descargas/{$export->id}")
        ->assertOk()->assertHeader('X-Report-Folio', $export->folio);

    $logs = AuditLog::query()->where('subject_id', $export->id)->where('subject_type', 'ReportExport')->get();
    expect($logs->pluck('action')->all())->toEqualCanonicalizing(['report.requested', 'report.generated', 'report.downloaded'])
        ->and($logs->firstWhere('action', 'report.generated')->user_id)->toBe($this->hr->id)
        ->and($logs->firstWhere('action', 'report.generated')->context['folio'])->toBe($export->folio);
});

it('neutraliza inyección de fórmulas en CSV y Excel', function () {
    expect(SpreadsheetSafe::text('=1+1'))->toBe("'=1+1")
        ->and(SpreadsheetSafe::text('+52'))->toBe("'+52")
        ->and(SpreadsheetSafe::text('-2'))->toBe("'-2")
        ->and(SpreadsheetSafe::text('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(SpreadsheetSafe::text('Ana'))->toBe('Ana');

    $this->employee->update(['first_name' => '=HYPERLINK("http://x","clic")']);
    foreach (['csv', 'xlsx'] as $format) {
        $this->actingAs($this->hr)->post('/reportes/exportar', [
            'report' => 'asistencia_detallada', 'format' => $format, 'from' => '2026-11-16', 'to' => '2026-11-22',
        ]);
    }
    $csv = Storage::disk('local')->get(ReportExport::query()->where('format', 'csv')->sole()->path);
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK')->not->toContain('"=HYPERLINK');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(ReportExport::query()->where('format', 'xlsx')->sole()->path));
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    expect($sheet)->not->toContain('<f>')->toContain("'=HYPERLINK");
});

it('genera Excel con encabezados inmovilizados, autofiltro, fechas reales y duraciones [h]:mm', function () {
    $this->actingAs($this->hr)->post('/reportes/exportar', [
        'report' => 'asistencia_detallada', 'format' => 'xlsx', 'from' => '2026-11-16', 'to' => '2026-11-22',
    ]);
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path(ReportExport::query()->sole()->path)))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = (string) $zip->getFromName('xl/styles.xml');
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $hasImage = $zip->locateName('xl/media/image1.png') !== false;
    $zip->close();

    expect($sheet)->toContain('<pane ')->toContain('state="frozen"')->toContain('<autoFilter ')->toContain('<cols>')
        ->and($styles)->toContain('[h]:mm')->toContain('dd/mm/yyyy')
        ->and($workbook)->toContain('Información')->toContain('_xlnm._FilterDatabase')
        ->and($sheet)->toContain('TOTAL GENERAL')
        ->and($hasImage)->toBeTrue();
});

it('genera CSV UTF-8 con BOM, encabezado institucional, subtotales y total', function () {
    $this->actingAs($this->hr)->post('/reportes/exportar', [
        'report' => 'asistencia_detallada', 'format' => 'csv', 'from' => '2026-11-16', 'to' => '2026-11-22',
    ]);
    $csv = Storage::disk('local')->get(ReportExport::query()->sole()->path);
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Razón social')->toContain('RFC')->toContain('Folio,REP-')
        ->and($csv)->toContain('TOTAL GENERAL (7)')->toContain('Subtotal Semana del 16/11/2026')
        ->and($csv)->toContain('SHA-256 de los datos');
});

it('genera PDF con encabezado institucional para cualquier reporte', function (string $key) {
    $this->actingAs($this->hr)->post('/reportes/exportar', [
        'report' => $key, 'format' => 'pdf', 'from' => '2026-11-16', 'to' => '2026-11-22',
        'employee_id' => $key === 'asistencia_individual' ? $this->employee->id : null,
    ])->assertRedirect();
    $export = ReportExport::query()->sole();
    expect($export->status)->toBe('completed', (string) $export->error)
        ->and(substr(Storage::disk('local')->get($export->path), 0, 5))->toBe('%PDF-');
})->with(['asistencia_detallada', 'asistencia_individual', 'rectificaciones_firmas', 'omisiones_incidencias_evidencia', 'inspeccion_laboral', 'noi_incidencias', 'por_region']);

it('exporta para NOI faltas y horas extra autorizadas con claves configurables', function () {
    $record = OvertimeRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->first();
    expect($record)->not->toBeNull();
    $record->forceFill(['status' => ApprovalStatus::Approved, 'authorized_minutes' => 30])->save();

    OrganizationSetting::query()->create(['key' => NoiConfig::SETTING_KEY, 'value' => ['concepts' => ['absence' => ['code' => 'F01']]]]);
    $rows = collectDataset(($this->dataset)('noi_incidencias'))['data'];
    $byCode = collect($rows)->groupBy('concept_code');

    expect($byCode['F01']->pluck('date')->all())->toContain('2026-11-20')
        ->and($byCode['HORAS_EXTRA_DOBLES']->first()['quantity'])->toBe('0.50')
        ->and($byCode['HORAS_EXTRA_DOBLES']->first()['date'])->toBe('2026-11-17');

    $this->actingAs($this->hr)->post('/reportes/exportar', [
        'report' => 'noi_incidencias', 'format' => 'csv', 'from' => '2026-11-16', 'to' => '2026-11-22',
    ]);
    $lines = preg_split('/\r?\n/', trim(substr(Storage::disk('local')->get(ReportExport::query()->sole()->path), 3)));
    expect($lines[0])->toBe('"Clave empleado",Nombre,Fecha,"Clave concepto",Concepto,Unidad,Cantidad')
        ->and(collect($lines)->first(fn ($l) => str_contains($l, 'F01')))->toContain('20/11/2026');
});

it('reporta omisiones con su estado de rectificación y evidencia', function () {
    $rows = collectDataset(($this->dataset)('omisiones_incidencias_evidencia'))['data'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['kind_label'])->toBe('Omisión de checada')
        ->and($rows[0]['status_label'])->toBe('Sin rectificar')
        ->and($rows[0]['evidence'])->toBe('Sin evidencia')
        ->and($rows[0]['signature_method'])->toBe('Sin firma');
});

it('previsualiza cada reporte del catálogo con el mismo dataset', function (string $key) {
    $query = $key === 'asistencia_individual' ? "&employee_id={$this->employee->id}" : '';
    $this->actingAs($this->hr)->get("/reportes/{$key}?from=2026-11-16&to=2026-11-22{$query}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reportes/Show')->where('report.key', $key)->has('rows.data')->where('needs_employee', false));
})->with(fn () => ReportCatalog::keys());

it('exige colaborador en la asistencia individual', function () {
    $this->actingAs($this->hr)->get('/reportes/asistencia_individual?from=2026-11-16&to=2026-11-22')
        ->assertInertia(fn (Assert $page) => $page->where('needs_employee', true)->where('rows.total', 0));
    $this->actingAs($this->hr)->post('/reportes/exportar', ['report' => 'asistencia_individual', 'format' => 'pdf', 'from' => '2026-11-16', 'to' => '2026-11-22'])
        ->assertSessionHasErrors('employee_id');
});

it('no muestra referencias a salida automática en el catálogo', function () {
    $labels = collect(ReportCatalog::definitions())->flatMap(fn ($d) => array_map(fn ($c) => $c->label.' '.$c->key, $d->columns))->implode(' ');
    expect(mb_strtolower($labels))->not->toContain('automática')->not->toContain('auto_clock')->not->toContain('cierre');
});
