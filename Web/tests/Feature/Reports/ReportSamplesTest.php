<?php

use App\Domain\Reports\Layouts\ReportLayoutRegistry;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportParameters;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Models\Branch;
use App\Models\Department;
use App\Models\IncidenceType;
use App\Models\LegalEntity;
use App\Models\OvertimeRecord;
use App\Models\Position;
use App\Models\WorkCenter;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/**
 * Genera archivos de muestra para revisión visual (no es una prueba de regresión).
 * Se ejecuta sólo con REPORT_SAMPLES_DIR definido:
 *   REPORT_SAMPLES_DIR=/ruta php artisan test --filter=ReportSamples
 */
it('genera muestras de PDF, Excel y CSV para revisión visual', function () {
    $dir = (string) env('REPORT_SAMPLES_DIR');
    $this->seedLegal();
    $org = $this->organization('Muestra');
    $hr = $this->userIn($org, 'Recursos Humanos', allBranches: true);

    $entity = LegalEntity::query()->create(['legal_name' => 'PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. de C.V.', 'rfc' => 'PSM2311289J2', 'active' => true]);
    $center = WorkCenter::query()->create(['name' => 'Centro de prueba', 'code' => 'CT-PRUEBA', 'legal_entity_id' => $entity->id]);
    $a = Branch::query()->create(['name' => 'Sucursal Prueba A', 'code' => 'SPA', 'region' => 'Región Prueba 1', 'work_center_id' => $center->id]);
    $b = Branch::query()->create(['name' => 'Sucursal Prueba B', 'code' => 'SPB', 'region' => 'Región Prueba 2', 'work_center_id' => $center->id]);
    $dept = Department::query()->create(['name' => 'Operación', 'code' => 'OPE']);
    $pos = Position::query()->create(['name' => 'Asesor de prueba', 'code' => 'ASE', 'department_id' => $dept->id]);

    $people = [];
    foreach ([['Colaborador', 'Uno', $a], ['Colaboradora', 'Dos', $a], ['Colaborador', 'Tres Largo Apellido', $b]] as [$first, $last, $branch]) {
        $e = $this->employee($org, schedule: $this->schedule(), branch: $branch);
        $e->update(['first_name' => $first, 'last_name' => $last]);
        $e->employments()->first()->assignments()->update(['department_id' => $dept->id, 'position_id' => $pos->id]);
        $people[] = $e->refresh();
    }

    // Dos semanas de jornadas con retardos, descansos, extra y omisiones.
    foreach ($people as $i => $e) {
        for ($d = CarbonImmutable::parse('2026-11-09'); $d->lte(CarbonImmutable::parse('2026-11-20')); $d = $d->addDay()) {
            if ($d->isWeekend() || ($i === 1 && $d->day === 12) || $d->toDateString() === '2026-11-16') {
                continue; // fin de semana, una falta y el festivo
            }
            $in = $d->setTime(15, ($i * 7 + $d->day) % 3 === 0 ? 22 : 0);
            $this->punchAt($e, PunchType::ClockIn, $in->format('Y-m-d H:i:s'));
            $this->punchAt($e, PunchType::BreakStart, $d->setTime(19, 0)->format('Y-m-d H:i:s'));
            $this->punchAt($e, PunchType::BreakEnd, $d->setTime(20, 0)->format('Y-m-d H:i:s'));
            if (! ($i === 2 && $d->day === 20)) {
                $out = $d->addDay()->setTime(0, $d->day % 4 === 0 ? 50 : 0);
                $this->punchAt($e, PunchType::ClockOut, $out->format('Y-m-d H:i:s'));
            }
        }
    }
    $type = IncidenceType::query()->where('affects_payroll', true)->first() ?? IncidenceType::query()->first();
    $people[1]->incidences()->create(['incidence_type_id' => $type->id, 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'status' => 'approved', 'reason' => 'Cita médica (dato de prueba)', 'organization_id' => $org->id]);
    OvertimeRecord::query()->where('recorded_excess_minutes', '>', 0)->get()->each(fn ($r) => $r->forceFill(['status' => ApprovalStatus::Approved, 'authorized_minutes' => $r->recorded_excess_minutes])->save());
    $this->travelTo(CarbonImmutable::parse('2026-11-23 18:00:00', 'UTC'));

    $exporter = app(ReportExporter::class);
    foreach ((array) (env('REPORT_SAMPLES_ONLY') ? explode(',', (string) env('REPORT_SAMPLES_ONLY')) : ['por_sucursal', 'asistencia_detallada', 'asistencia_diaria', 'consolidada', 'horas_extra', 'inspeccion_laboral', 'omisiones_incidencias_evidencia', 'rectificaciones_firmas', 'noi_incidencias']) as $key) {
        $definition = ReportCatalog::definition($key);
        $params = ReportParameters::fromArray(['from' => $key === 'asistencia_diaria' ? '2026-11-17' : '2026-11-09', 'to' => $key === 'asistencia_diaria' ? '2026-11-18' : '2026-11-22'], 'America/Mexico_City', $definition->maxDays);
        foreach (['pdf', 'xlsx', 'csv'] as $format) {
            file_put_contents("{$dir}/progreso.log", "{$key}.{$format}".PHP_EOL, FILE_APPEND);
            $dataset = $exporter->dataset($definition, $params, $hr, app(CurrentOrganization::class)->require(), 'REP-2026-000123');
            $exporter->renderer($format)->render($dataset, app(ReportLayoutRegistry::class)->for($definition), "{$dir}/{$key}.{$format}");
        }
    }
    expect(is_file("{$dir}/asistencia_detallada.pdf"))->toBeTrue();
})->skip(fn () => ! env('REPORT_SAMPLES_DIR'), 'Sólo para revisión visual (REPORT_SAMPLES_DIR).');
