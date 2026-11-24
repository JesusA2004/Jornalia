<?php

use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\RectificationStage;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\AttendanceAdjustment;
use App\Models\ComplianceAlert;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->employee = $this->employee($this->org, schedule: $this->schedule(), hiredOn: '2023-01-10');
});

it('registra una rectificación a nombre del colaborador y la autoriza otro responsable sin aplicar nada antes de la firma', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));

    $this->actingAs($this->hr)->post('/rectificaciones', [
        'employee_id' => $this->employee->id,
        'date' => '2026-11-17',
        'kind' => 'missing_punch',
        'proposed_type' => 'clock_out',
        'proposed_time' => '18:00',
        'reason' => 'Olvidó registrar la salida; lo confirma su supervisor.',
        'evidence' => UploadedFile::fake()->create('constancia.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $request = RectificationRequest::query()->sole();
    expect($request->attachments()->count())->toBe(1)
        ->and($request->stage)->toBe(RectificationStage::ToAuthorize)
        ->and($request->proposed_time->utc()->format('Y-m-d H:i'))->toBe('2026-11-18 00:00');

    $this->actingAs($this->hr)->get("/rectificaciones/{$request->id}")->assertOk();
    $attachment = $request->attachments()->first();
    $this->actingAs($this->hr)->get("/rectificaciones/{$request->id}/evidencias/{$attachment->id}")->assertOk();

    // Quien capturó no autoriza su propia captura.
    $this->actingAs($this->hr)->post("/rectificaciones/{$request->id}/decision", ['decision' => 'approve'])->assertForbidden();

    $reviewer = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    // Rechazo sin comentario: error de validación.
    $this->actingAs($reviewer)->post("/rectificaciones/{$request->id}/decision", ['decision' => 'reject'])
        ->assertSessionHasErrors('comment');
    $this->actingAs($reviewer)->post("/rectificaciones/{$request->id}/decision", ['decision' => 'approve', 'comment' => 'Autorizado'])
        ->assertSessionHasNoErrors();

    // Autorizada: queda por firmar; sin firma del colaborador no hay ajuste.
    expect($request->refresh()->stage)->toBe(RectificationStage::ToSign)
        ->and($request->status)->toBe(ApprovalStatus::Pending)
        ->and(AttendanceAdjustment::query()->count())->toBe(0)
        ->and($request->decisions()->count())->toBe(1);
});

it('impide que un responsable apruebe su propia solicitud', function () {
    $own = $this->employee($this->org, $this->hr, $this->schedule());
    $this->punchAt($own, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $this->actingAs($this->hr)->post('/rectificaciones', [
        'employee_id' => $own->id, 'date' => '2026-11-17', 'kind' => 'other', 'reason' => 'Aclaración propia de la jornada.',
        'evidence' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertRedirect();

    $request = RectificationRequest::query()->sole();
    $this->actingAs($this->hr)->post("/rectificaciones/{$request->id}/decision", ['decision' => 'approve'])->assertForbidden();
});

it('rechaza una rectificación con comentario y conserva el marcaje original', function () {
    $punch = $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $this->actingAs($this->hr)->post('/rectificaciones', [
        'employee_id' => $this->employee->id, 'date' => '2026-11-17', 'kind' => 'time_clarification',
        'punch_uuid' => $punch->uuid, 'proposed_time' => '08:30', 'reason' => 'La entrada real fue antes.',
        'evidence' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertRedirect();
    $request = RectificationRequest::query()->sole();

    $reviewer = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->actingAs($reviewer)->post("/rectificaciones/{$request->id}/decision", ['decision' => 'reject', 'comment' => 'Sin evidencia suficiente'])
        ->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($request->stage)->toBe(RectificationStage::Rejected)
        ->and(AttendanceAdjustment::query()->count())->toBe(0)
        ->and(Punch::query()->find($punch->id)->server_time->format('H:i'))->toBe('15:00');
});

it('registra y aprueba una incidencia con evidencia obligatoria', function () {
    $type = IncidenceType::query()->where('code', 'INCAPACIDAD_EG')->firstOrFail();

    $this->actingAs($this->hr)->post('/incidencias', [
        'employee_id' => $this->employee->id, 'incidence_type_id' => $type->id,
        'starts_on' => '2026-11-19', 'ends_on' => '2026-11-20', 'reason' => 'Incapacidad IMSS', 'folio' => 'LT123456',
    ])->assertSessionHasErrors('evidence');

    $this->actingAs($this->hr)->post('/incidencias', [
        'employee_id' => $this->employee->id, 'incidence_type_id' => $type->id,
        'starts_on' => '2026-11-19', 'ends_on' => '2026-11-20', 'reason' => 'Incapacidad IMSS', 'folio' => 'LT123456',
        'evidence' => UploadedFile::fake()->image('incapacidad.jpg'),
    ])->assertRedirect();

    $incidence = Incidence::query()->sole();
    expect($incidence->source)->toBe('manager')->and($incidence->status)->toBe(ApprovalStatus::Pending);

    $this->actingAs($this->hr)->get('/incidencias')->assertOk();
    // Nadie autoriza su propia captura; jefe inmediato y RH (dos niveles) la autorizan.
    $this->actingAs($this->hr)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertForbidden();
    $reviewer = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->actingAs($reviewer)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($incidence->refresh()->current_level)->toBe(2);
    $this->actingAs($reviewer)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($incidence->refresh()->status)->toBe(ApprovalStatus::Approved);
});

it('solicita, aprueba y cancela vacaciones respetando el saldo legal', function () {
    $this->actingAs($this->hr)->get('/vacaciones?tab=saldos')->assertOk()
        ->assertInertia(fn ($page) => $page->where('balances.data.0.balance.service_year', 3)
            ->where('balances.data.0.balance.legal_days', 16));

    $this->actingAs($this->hr)->post('/vacaciones', [
        'employee_id' => $this->employee->id, 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-11',
    ])->assertRedirect();
    $vacation = VacationRequest::query()->sole();
    expect($vacation->days)->toBe(5);

    $this->actingAs($this->hr)->get('/vacaciones?tab=calendario&month=2026-12')->assertOk()
        ->assertInertia(fn ($page) => $page->where('calendar.total_requests', 1));

    $this->actingAs($this->hr)->post("/vacaciones/{$vacation->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($vacation->refresh()->status)->toBe(ApprovalStatus::Approved);

    $this->actingAs($this->hr)->post("/vacaciones/{$vacation->id}/cancelar")->assertSessionHasNoErrors();
    expect($vacation->refresh()->status)->toBe(ApprovalStatus::Cancelled);
});

it('autoriza horas extra sin exceder el excedente registrado y nunca lo oculta', function () {
    $record = OvertimeRecord::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->employee->id,
        'date' => '2026-11-17', 'week_start' => '2026-11-16', 'recorded_excess_minutes' => 120,
        'double_minutes' => 120, 'triple_minutes' => 0, 'over_limit_minutes' => 0, 'status' => 'pending',
    ]);

    $this->actingAs($this->hr)->post("/horas-extra/{$record->id}/autorizacion", [
        'decision' => 'approve', 'authorized_minutes' => 180, 'justification' => 'Cierre de mes',
    ])->assertSessionHasErrors('authorized_minutes');

    $this->actingAs($this->hr)->post("/horas-extra/{$record->id}/autorizacion", [
        'decision' => 'approve', 'authorized_minutes' => 90, 'justification' => 'Cierre de mes',
    ])->assertSessionHasNoErrors();

    $record->refresh();
    expect($record->status)->toBe(ApprovalStatus::Approved)
        ->and($record->authorized_minutes)->toBe(90)
        ->and($record->recorded_excess_minutes)->toBe(120)
        ->and($record->authorized_by)->toBe($this->hr->id);

    $this->actingAs($this->hr)->get('/horas-extra?week=2026-11-16')->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.recorded_minutes', 120)
            ->where('totals.authorized_minutes', 90)
            ->where('totals.justified_minutes', 30)
            ->where('rows.data.0.totals.recorded_minutes', 120));
});

it('atiende y resuelve alertas de cumplimiento con nota', function () {
    $alert = ComplianceAlert::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'date' => '2026-11-17',
        'rule_code' => 'no_weekly_rest', 'severity' => 'warning', 'message' => 'Sin descanso', 'status' => 'open',
    ]);

    $this->actingAs($this->hr)->post("/cumplimiento/alertas/{$alert->id}", ['status' => 'resolved'])->assertSessionHasErrors('note');
    $this->actingAs($this->hr)->post("/cumplimiento/alertas/{$alert->id}", ['status' => 'resolved', 'note' => 'Se programó descanso compensatorio'])
        ->assertSessionHasNoErrors();

    expect($alert->refresh()->status)->toBe('resolved')->and($alert->resolved_by)->toBe($this->hr->id);
    $this->actingAs($this->hr)->get('/cumplimiento?status=all')->assertOk()
        ->assertInertia(fn ($page) => $page->where('alerts.total', 1)->has('legal.code'));
});

it('ejecuta la verificación de integridad y la registra en la bitácora', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/cumplimiento/integridad/anclar')->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/cumplimiento/integridad/verificar')->assertRedirect();

    $this->actingAs($admin)->get('/cumplimiento/integridad')->assertOk()
        ->assertInertia(fn ($page) => $page->where('latest.valid', true)
            ->where('chains.0.records', 1)
            ->where('anchors.total', 1));

    $this->actingAs($admin)->get('/cumplimiento/bitacora?module=integrity')->assertOk()
        ->assertInertia(fn ($page) => $page->where('logs.total', 2));

    // Un gerente de sucursal no verifica integridad.
    $manager = $this->userIn($this->org, 'Gerente de sucursal');
    $this->actingAs($manager)->post('/cumplimiento/integridad/verificar')->assertForbidden();
});

it('autoriza horas extra en dos niveles configurados y fija minutos sólo al final', function () {
    ApprovalFlow::query()->create(['organization_id' => $this->org->id, 'subject' => 'overtime', 'levels' => [
        ['level' => 1, 'permission' => 'overtime.approve', 'label' => 'Supervisor'],
        ['level' => 2, 'permission' => 'overtime.approve', 'label' => 'Recursos Humanos'],
    ]]);
    $record = OvertimeRecord::query()->create([
        'organization_id' => $this->org->id, 'employee_id' => $this->employee->id,
        'date' => '2026-11-17', 'week_start' => '2026-11-16', 'recorded_excess_minutes' => 60,
        'double_minutes' => 60, 'status' => 'pending',
    ]);
    $second = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);

    $this->actingAs($this->hr)->post("/horas-extra/{$record->id}/autorizacion", ['decision' => 'approve', 'authorized_minutes' => 60, 'justification' => 'Inventario'])
        ->assertSessionHasNoErrors();
    expect($record->refresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($record->current_level)->toBe(2)
        ->and($record->authorized_minutes)->toBe(0);

    $this->actingAs($second)->post("/horas-extra/{$record->id}/autorizacion", ['decision' => 'approve', 'authorized_minutes' => 45, 'justification' => 'Inventario'])
        ->assertSessionHasNoErrors();
    expect($record->refresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($record->authorized_minutes)->toBe(45)
        ->and($record->authorized_by)->toBe($second->id)
        ->and(ApprovalDecision::query()->where('approvable_id', $record->id)->where('approvable_type', $record->getMorphClass())->count())->toBe(2);
});

it('lista los marcajes del día para elegir cuál rectificar, con alcance', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:02:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));

    $this->actingAs($this->hr)->getJson("/rectificaciones/marcajes-del-dia?employee_id={$this->employee->id}&date=2026-11-17")
        ->assertOk()
        ->assertJsonPath('punches.0.type_label', 'Entrada')
        ->assertJsonPath('punches.0.time', '2026-11-17T15:02:00Z')
        ->assertJsonCount(1, 'punches');

    $orgB = $this->organization('Otra');
    $foreign = $this->employee($orgB, schedule: $this->schedule());
    $this->actingAs($this->hr)->getJson("/rectificaciones/marcajes-del-dia?employee_id={$foreign->id}&date=2026-11-17")->assertNotFound();
});
