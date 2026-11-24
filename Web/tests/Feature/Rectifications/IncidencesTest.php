<?php

use App\Domain\Incidences\IncidenceCatalog;
use App\Domain\Integrations\People\PeopleIncidenceReceiver;
use App\Domain\Integrations\People\PeopleIncidenceSource;
use App\Domain\Integrations\People\PeopleSourceException;
use App\Enums\ApprovalStatus;
use App\Enums\RectificationStage;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\IntegrationRun;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/*
| Incidencias (PDF pantalla 06): catálogo cerrado, evidencia por tipo,
| autorización por tipo, omisión → rectificación, cancelación con motivo y
| recepción idempotente desde Mr. Lana People.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->employee = $this->employee($this->org, schedule: $this->schedule(), hiredOn: '2023-01-10');
    $this->manager = $this->userIn($this->org, 'Gerente de sucursal');
    $this->manager->branches()->attach(Branch::query()->where('code', 'MATRIZ')->value('id'), ['organization_id' => $this->org->id]);
});

function a2Type(string $code): IncidenceType
{
    return IncidenceType::query()->where('code', $code)->firstOrFail();
}

it('provisiona el catálogo cerrado sin falta injustificada', function () {
    $codes = IncidenceType::query()->where('active', true)->pluck('code')->all();
    expect($codes)->toEqualCanonicalizing(IncidenceCatalog::codes())
        ->not->toContain('FALTA_INJUSTIFICADA', 'RETARDO');
    expect(a2Type('INCAPACIDAD_EG')->requires_folio)->toBeTrue()
        ->and(a2Type('OMISION_SALIDA')->omission_punch_type)->toBe('clock_out');
});

it('ajusta organizaciones existentes desactivando tipos fuera de catálogo sin perder historial', function () {
    $legacy = IncidenceType::query()->create([
        'code' => 'INCAPACIDAD', 'name' => 'Incapacidad', 'category' => 'disability', 'operational_effect' => 'justified',
        'requires_evidence' => true, 'requires_approval' => true, 'is_system' => true, 'active' => true,
    ]);
    $old = Incidence::query()->create([
        'employee_id' => $this->employee->id, 'incidence_type_id' => $legacy->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02',
        'status' => ApprovalStatus::Approved, 'source' => 'manager',
    ]);
    a2Type('LUTO')->forceFill(['approval_route' => 'hr'])->save();

    $this->artisan('incidencias:catalogo')->assertSuccessful();
    $this->artisan('incidencias:catalogo')->assertSuccessful(); // idempotente

    expect($legacy->fresh()->active)->toBeFalse()
        ->and($old->fresh()->type->name)->toBe('Incapacidad')
        ->and(a2Type('LUTO')->approval_route)->toBe('hr') // ajuste de RH conservado
        ->and(IncidenceType::query()->where('code', 'LUTO')->count())->toBe(1);
});

it('exige certificado IMSS y folio para la incapacidad y la autorizan jefe y RH', function () {
    $type = a2Type('INCAPACIDAD_EG');
    $base = ['employee_id' => $this->employee->id, 'incidence_type_id' => $type->id, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-20', 'reason' => 'Incapacidad médica'];

    $this->actingAs($this->manager)->post('/incidencias', $base)->assertSessionHasErrors('evidence');
    $this->actingAs($this->manager)->post('/incidencias', $base + ['evidence' => [UploadedFile::fake()->create('imss.pdf', 30, 'application/pdf')]])
        ->assertSessionHasErrors('folio');
    $this->actingAs($this->manager)->post('/incidencias', $base + ['folio' => 'LT123456', 'evidence' => [UploadedFile::fake()->create('imss.pdf', 30, 'application/pdf')]])
        ->assertRedirect();

    $incidence = Incidence::query()->sole();
    expect($incidence->folio)->toBe('LT123456')->and($incidence->attachments()->count())->toBe(1);

    // Quien capturó no autoriza su propia solicitud.
    $this->actingAs($this->manager)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertForbidden();

    $other = $this->userIn($this->org, 'Gerente de sucursal');
    $other->branches()->attach(Branch::query()->where('code', 'MATRIZ')->value('id'), ['organization_id' => $this->org->id]);
    $this->actingAs($other)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($incidence->fresh()->status)->toBe(ApprovalStatus::Pending)->and($incidence->fresh()->current_level)->toBe(2);
    // El jefe no puede resolver el nivel de RH.
    $this->actingAs($other)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertForbidden();

    $this->actingAs($this->hr)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($incidence->fresh()->status)->toBe(ApprovalStatus::Approved);
});

it('la omisión de checada abre una rectificación para firma y nunca crea marcajes', function () {
    $punches = Punch::query()->count();
    $this->actingAs($this->manager)->post('/incidencias', [
        'employee_id' => $this->employee->id, 'incidence_type_id' => a2Type('OMISION_SALIDA')->id,
        'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'proposed_time' => '18:05', 'reason' => 'Olvidó checar su salida',
        'evidence' => [UploadedFile::fake()->image('bitacora.jpg')],
    ])->assertRedirect();

    $incidence = Incidence::query()->sole();
    $r = RectificationRequest::query()->sole();
    expect($incidence->rectification_request_id)->toBe($r->id)
        ->and($r->incidence_id)->toBe($incidence->id)
        ->and($r->origin)->toBe('incidence')
        ->and($r->stage)->toBe(RectificationStage::ToAuthorize)
        ->and($r->proposed_type->value)->toBe('clock_out')
        ->and($r->proposed_time->utc()->format('Y-m-d H:i'))->toBe('2026-11-18 00:05')
        ->and($r->attachments()->count())->toBe(1)
        ->and(Punch::query()->count())->toBe($punches);

    // No se resuelve desde Incidencias.
    $this->actingAs($this->hr)->post("/incidencias/{$incidence->id}/decision", ['decision' => 'approve'])->assertSessionHasErrors('decision');

    // Si la rectificación se rechaza, la incidencia también.
    $this->actingAs($this->hr)->post("/rectificaciones/{$r->id}/decision", ['decision' => 'reject', 'comment' => 'Sin sustento', 'stage' => 'por_autorizar'])
        ->assertSessionHasNoErrors();
    expect($incidence->fresh()->status)->toBe(ApprovalStatus::Rejected);
});

it('no borra incidencias: sólo cancela con motivo y deja bitácora', function () {
    $this->actingAs($this->manager)->post('/incidencias', [
        'employee_id' => $this->employee->id, 'incidence_type_id' => a2Type('PERMISO_SIN_GOCE')->id,
        'starts_on' => '2026-11-19', 'ends_on' => '2026-11-19', 'reason' => 'Trámite personal',
        'evidence' => [UploadedFile::fake()->create('formato.pdf', 20, 'application/pdf')],
    ])->assertRedirect();
    $incidence = Incidence::query()->sole();
    $incidence->forceFill(['status' => ApprovalStatus::Approved])->save();

    $this->actingAs($this->hr)->post("/incidencias/{$incidence->id}/cancelar", [])->assertSessionHasErrors('reason');
    $this->actingAs($this->hr)->post("/incidencias/{$incidence->id}/cancelar", ['reason' => 'Capturada por error'])->assertSessionHasNoErrors();

    $incidence->refresh();
    expect($incidence->status)->toBe(ApprovalStatus::Cancelled)
        ->and($incidence->cancellation_reason)->toBe('Capturada por error')
        ->and($incidence->cancelled_by)->toBe($this->hr->id)
        ->and(Incidence::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'incidence.cancelled')->exists())->toBeTrue();
    $this->actingAs($this->hr)->delete("/incidencias/{$incidence->id}")->assertStatus(405);
});

it('sólo RH edita el catálogo y los parámetros de rectificaciones', function () {
    $type = a2Type('LUTO');
    $platform = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
    $payload = ['requires_evidence' => true, 'evidence_kind' => 'death_certificate', 'requires_folio' => false, 'approval_route' => 'hr', 'employee_requestable' => true, 'external_code' => 'LUTO_P'];

    $this->actingAs($platform)->put("/configuracion/incidencias/{$type->id}", $payload)->assertForbidden();
    $this->actingAs($platform)->post('/configuracion/incidencias', ['code' => 'NUEVO'])->assertStatus(405);

    $this->actingAs($this->hr)->put("/configuracion/incidencias/{$type->id}", $payload)->assertSessionHasNoErrors();
    expect($type->fresh()->approval_route)->toBe('hr')->and($type->fresh()->external_code)->toBe('LUTO_P');

    $this->actingAs($this->hr)->put('/configuracion/incidencias/rectificaciones', ['business_days' => 5, 'confirmed_by_hr' => true, 'closed_through' => '2026-11-15'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->hr)->get('/configuracion/incidencias')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('configuracion/Incidences')
            ->where('rectifications.business_days', 5)
            ->where('rectifications.closed_through', '2026-11-15')
            ->where('people.configured', false)
            ->has('types', 10));
});

it('recibe de People incidencias aprobadas de forma idempotente y registra errores', function () {
    $this->employee->forceFill(['external_source' => 'people', 'external_id' => 'P-77'])->save();
    a2Type('INCAPACIDAD_EG')->forceFill(['external_code' => 'INC_EG'])->save();

    $rows = [
        ['id' => 'X1', 'kind' => 'incidence', 'employee_external_id' => 'P-77', 'type_code' => 'INC_EG', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'folio' => 'AB1234', 'status' => 'approved'],
        ['id' => 'X2', 'kind' => 'vacation', 'employee_number' => $this->employee->employee_number, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02', 'status' => 'approved'],
        ['id' => 'X3', 'kind' => 'incidence', 'employee_external_id' => 'NO-EXISTE', 'type_code' => 'INC_EG', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'folio' => 'ZZ999', 'status' => 'approved'],
        ['id' => 'X4', 'kind' => 'incidence', 'employee_external_id' => 'P-77', 'type_code' => 'DESCONOCIDO', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'status' => 'approved'],
    ];
    $source = new class($rows) implements PeopleIncidenceSource
    {
        /** @param list<array<string, mixed>> $rows */
        public function __construct(public array $rows, public bool $configured = true) {}

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function approvedSince(?string $since): iterable
        {
            return $this->rows;
        }
    };
    $receiver = app(PeopleIncidenceReceiver::class);

    $run = $receiver->run($source);
    expect($run->status)->toBe('partial')
        ->and($run->stats['created'])->toBe(2)
        ->and($run->stats['errors'])->toBe(2)
        ->and(collect($run->conflicts)->pluck('external_id')->all())->toBe(['X3', 'X4']);
    $incidence = Incidence::query()->where('external_id', 'X1')->sole();
    expect($incidence->status)->toBe(ApprovalStatus::Approved)->and($incidence->source)->toBe('people')
        ->and(VacationRequest::query()->where('external_id', 'X2')->sole()->status)->toBe(ApprovalStatus::Approved);

    // Segunda corrida: sin duplicados; People cancela X1.
    $source->rows = [array_merge($rows[0], ['status' => 'cancelled']), $rows[1]];
    $second = $receiver->run($source);
    expect($second->stats['cancelled'])->toBe(1)->and($second->stats['unchanged'])->toBe(1)
        ->and(Incidence::query()->where('external_id', 'X1')->count())->toBe(1)
        ->and($incidence->fresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(VacationRequest::query()->count())->toBe(1);

    // Sin credenciales: no se ejecuta ni se simula.
    $source->configured = false;
    expect($receiver->run($source))->toBeNull()
        ->and($receiver->status($source)['configured'])->toBeFalse()
        ->and(IntegrationRun::query()->where('source', PeopleIncidenceReceiver::RUN_SOURCE)->count())->toBe(2);

    // Falla de conexión: corrida fallida sin cambios.
    $failing = new class implements PeopleIncidenceSource
    {
        public function isConfigured(): bool
        {
            return true;
        }

        public function approvedSince(?string $since): iterable
        {
            throw new PeopleSourceException('No fue posible conectar con People.');
        }
    };
    expect($receiver->run($failing)->status)->toBe('failed');
});
