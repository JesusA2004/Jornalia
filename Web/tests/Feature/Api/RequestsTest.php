<?php

use App\Enums\ApprovalStatus;
use App\Models\Attachment;
use App\Models\DocumentAcknowledgement;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\PolicyDocument;
use App\Models\RectificationRequest;
use App\Models\VacationRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
    Storage::fake('local');
});

it('registra una rectificación con evidencia sin tocar el marcaje original', function () {
    $receipt = $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge()))->assertCreated();
    $uuid = $receipt->json('data.punch.uuid');

    $this->app['auth']->forgetGuards();
    $response = $this->withToken($this->token)->post('/api/v1/rectifications', [
        'date' => '2026-11-17', 'punch_uuid' => $uuid, 'kind' => 'time_clarification',
        'proposed_time' => '2026-11-17T15:00:00Z', 'reason' => 'Llegué a las 9:00, la app tardó en abrir',
        'evidence' => UploadedFile::fake()->create('constancia.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $response->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.stage', 'por_autorizar')
        ->assertJsonPath('data.punch_uuid', $uuid)
        ->assertJsonPath('data.proposed_time', '2026-11-17T15:00:00Z');

    $rect = RectificationRequest::query()->findOrFail($response->json('data.id'));
    $attachment = Attachment::query()->where('attachable_id', $rect->id)->where('attachable_type', $rect->getMorphClass())->firstOrFail();
    expect($attachment->sha256)->toMatch('/^[a-f0-9]{64}$/');
    Storage::disk('local')->assertExists($attachment->path);

    $this->mobile('GET', '/attendance/punches/'.$uuid)->assertJsonPath('data.punch.server_time', '2026-11-17T16:00:00Z');
});

it('valida rectificaciones según el dominio', function () {
    $this->mobile('POST', '/rectifications', ['date' => '2026-11-17', 'kind' => 'other', 'reason' => 'Sin evidencia adjunta'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['evidence']);

    $post = function (array $data) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->post('/api/v1/rectifications', $data + ['evidence' => [UploadedFile::fake()->image('foto.jpg')]], ['Accept' => 'application/json']);
    };
    $post(['date' => '2026-11-17', 'kind' => 'missing_punch', 'reason' => 'Sin datos de hora'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['proposed_time']);

    $post(['date' => '2026-12-17', 'kind' => 'other', 'reason' => 'Fecha futura'])
        ->assertStatus(422)->assertJsonValidationErrors(['date']);
});

it('sólo ofrece tipos de incidencia solicitables por el colaborador', function () {
    $codes = collect($this->mobile('GET', '/incidence-types')->assertOk()->json('data'))->pluck('code');

    expect($codes)->toContain('INCAPACIDAD_EG', 'PERMISO_CON_GOCE', 'OMISION_SALIDA')->not->toContain('RETARDO', 'FALTA_INJUSTIFICADA', 'INCAPACIDAD');
});

it('registra incidencias exigiendo evidencia cuando el tipo la requiere', function () {
    $type = IncidenceType::query()->where('code', 'INCAPACIDAD_EG')->firstOrFail();
    $body = ['incidence_type_id' => $type->id, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-19', 'reason' => 'Incapacidad IMSS', 'folio' => 'LT123456'];

    $this->mobile('POST', '/incidences', $body)->assertStatus(422)->assertJsonValidationErrors(['evidence']);

    $this->app['auth']->forgetGuards();
    $created = $this->withToken($this->token)->post('/api/v1/incidences', $body + [
        'evidence' => UploadedFile::fake()->image('incapacidad.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $created->assertJsonPath('data.has_evidence', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.type.code', 'INCAPACIDAD_EG')
        ->assertJsonPath('data.folio', 'LT123456');

    $this->mobile('GET', '/incidences')->assertOk()->assertJsonPath('data.0.id', $created->json('data.id'))->assertJsonPath('meta.total', 1);
});

it('rechaza tipos de incidencia no solicitables o de otra organización', function () {
    // Tipo fuera del catálogo cerrado (desactivado): no se acepta.
    $retardo = IncidenceType::query()->create(['code' => 'RETARDO', 'name' => 'Retardo', 'category' => 'time', 'operational_effect' => 'informative', 'active' => false, 'employee_requestable' => false]);
    $this->mobile('POST', '/incidences', ['incidence_type_id' => $retardo->id, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'reason' => 'Prueba'])
        ->assertStatus(422)->assertJsonValidationErrors(['incidence_type_id']);

    $this->mobile('POST', '/incidences', ['incidence_type_id' => 999999, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'reason' => 'Prueba'])
        ->assertStatus(422)->assertJsonValidationErrors(['incidence_type_id']);

    expect(Incidence::query()->count())->toBe(0);
});

it('consulta saldo, solicita y cancela vacaciones', function () {
    $balance = $this->mobile('GET', '/vacations/balance')->assertOk()->json('data');
    expect($balance['service_year'])->toBe(2)
        ->and($balance['legal_days'])->toBe(14)
        ->and($balance['vacation_premium_percent'])->toBe('25.00');

    $created = $this->mobile('POST', '/vacations', ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-03', 'comment' => 'Viaje familiar'])->assertCreated();
    $created->assertJsonPath('data.days', 3)->assertJsonPath('data.status', 'pending');

    expect($this->mobile('GET', '/vacations/balance')->json('data.pending_days'))->toBe(3);

    $this->mobile('POST', '/vacations', ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-02', 'comment' => null])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $this->mobile('POST', '/vacations/'.$created->json('data.id').'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(VacationRequest::query()->findOrFail($created->json('data.id'))->status)->toBe(ApprovalStatus::Cancelled);

    $this->mobile('GET', '/vacations')->assertOk()->assertJsonPath('meta.total', 1);
});

it('muestra acuses pendientes y registra el acuse de forma inmutable e idempotente', function () {
    $doc = PolicyDocument::query()->create([
        'code' => 'REGLAMENTO', 'title' => 'Sistema de registro de asistencia', 'version' => '1.0', 'body_markdown' => '# Reglamento',
        'sha256' => hash('sha256', '# Reglamento'), 'requires_acknowledgement' => true, 'published_at' => now()->subHour(),
    ]);
    PolicyDocument::query()->create([
        'code' => 'BORRADOR', 'title' => 'Borrador', 'version' => '0.1', 'body_markdown' => 'x',
        'sha256' => hash('sha256', 'x'), 'requires_acknowledgement' => true, 'published_at' => null,
    ]);

    $pending = $this->mobile('GET', '/profile')->assertOk()->json('data.pending_acknowledgements');
    expect(array_column($pending, 'id'))->toContain($doc->id)->toHaveCount(2); // + aviso de ubicación

    $this->mobile('POST', "/acknowledgements/{$doc->id}", ['accepted' => true, 'device_uuid' => $this->device->device_uuid])->assertNoContent();
    $this->mobile('POST', "/acknowledgements/{$doc->id}", ['accepted' => true, 'device_uuid' => $this->device->device_uuid])->assertNoContent();

    $ack = DocumentAcknowledgement::query()->where('policy_document_id', $doc->id)->sole();
    expect($ack->document_sha256)->toBe($doc->sha256)
        ->and($ack->hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($ack->device_id)->toBe($this->device->id)
        ->and($this->mobile('GET', '/profile')->json('data.pending_acknowledgements'))->toHaveCount(1); // queda sólo el aviso de ubicación

    $this->mobile('POST', "/acknowledgements/{$doc->id}", ['accepted' => false, 'device_uuid' => $this->device->device_uuid])->assertStatus(422);
});

it('lista y marca notificaciones como leídas', function () {
    $ids = [];
    foreach (['Uno', 'Dos'] as $title) {
        $ids[] = $this->user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['kind' => 'info', 'title' => $title, 'body' => 'Texto'],
        ])->id;
    }

    $this->mobile('GET', '/notifications')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.read_at', null);
    $this->mobile('POST', "/notifications/{$ids[0]}/read")->assertNoContent();
    expect($this->user->unreadNotifications()->count())->toBe(1);
    $this->mobile('POST', '/notifications/read-all')->assertNoContent();
    expect($this->user->unreadNotifications()->count())->toBe(0);
    $this->mobile('POST', '/notifications/'.Str::uuid().'/read')->assertNotFound();
});

it('informa que la verificación facial no está disponible sin proveedor', function () {
    $this->mobile('GET', '/face-verification/status')->assertOk()->assertExactJson(['data' => [
        'available' => false, 'provider' => null, 'enrolled' => false, 'consent_required' => true,
    ]]);
});

it('expone el calendario del mes con turnos y descansos', function () {
    $entries = collect($this->mobile('GET', '/calendar?month=2026-11')->assertOk()->json('data.entries'));

    expect($entries->where('date', '2026-11-17')->pluck('kind')->all())->toBe(['shift'])
        ->and($entries->where('date', '2026-11-22')->pluck('kind')->all())->toBe(['rest_day']);
});
