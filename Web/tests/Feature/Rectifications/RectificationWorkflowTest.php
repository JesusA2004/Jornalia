<?php

use App\Domain\Attendance\DeviceSignature;
use App\Domain\Attendance\HashChain;
use App\Domain\Attendance\RectificationService;
use App\Domain\Rectifications\RectificationSettings;
use App\Domain\Signatures\ElectronicSignatureService;
use App\Enums\AdjustmentAction;
use App\Enums\ApprovalStatus;
use App\Enums\RectificationStage;
use App\Exceptions\ImmutableRecordException;
use App\Models\ApprovalDecision;
use App\Models\AttendanceAdjustment;
use App\Models\Branch;
use App\Models\ElectronicSignature;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\User;
use App\Notifications\RectificationStageChanged;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

/*
| Flujo de 5 pasos (PDF pantalla 05): solicitud con evidencia → autorización del
| jefe → firma del colaborador (app) → validación RH → aplicación.
*/

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->setUpMobile(); // 2026-11-17 16:00 UTC (martes)
    $this->branch = Branch::query()->where('code', 'MATRIZ')->firstOrFail();
    $this->hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->manager = $this->userIn($this->org, 'Gerente de sucursal');
    $this->manager->branches()->attach($this->branch->id, ['organization_id' => $this->org->id]);
});

/**
 * Solicitud del colaborador vía API (multipart) por la salida omitida del lunes 16.
 *
 * @param  array<string, mixed>  $overrides
 */
function a2RequestMissingExit(object $t, array $overrides = [], bool $withEvidence = true): TestResponse
{
    app('auth')->forgetGuards();
    $data = array_merge([
        'date' => '2026-11-16', 'kind' => 'missing_punch', 'proposed_type' => 'clock_out',
        'proposed_time' => '2026-11-17T00:00:00Z', 'reason' => 'Se agotó la batería del teléfono',
    ], $overrides);
    if ($withEvidence) {
        $data['evidence'] = [UploadedFile::fake()->image('bitacora.jpg')];
    }

    return (fn () => $this->withToken($this->token)->post('/api/v1/rectifications', $data, ['Accept' => 'application/json']))->call($t);
}

function a2Decide(object $t, User $user, RectificationRequest $r, string $decision = 'approve', ?string $comment = null, ?string $stage = null): TestResponse
{
    app('auth')->forgetGuards();

    return $t->actingAs($user)->post("/rectificaciones/{$r->id}/decision", array_filter([
        'decision' => $decision, 'comment' => $comment, 'stage' => $stage ?? $r->fresh()->stage->value,
    ]));
}

function a2SetPin(object $t, string $pin = '4826'): void
{
    (fn () => $this->mobile('POST', '/profile/signature-pin', ['pin' => $pin]))->call($t)->assertOk()->assertJsonPath('data.has_pin', true);
}

it('recorre el flujo completo y aplica el ajuste sin tocar el marcaje original', function () {
    Notification::fake();
    $punches = Punch::query()->count();

    $id = a2RequestMissingExit($this)->assertCreated()
        ->assertJsonPath('data.stage', 'por_autorizar')
        ->assertJsonPath('data.original.summary', 'Sin salida')
        ->assertJsonPath('data.proposal.summary', 'Salida 18:00')
        ->assertJsonCount(1, 'data.evidences')
        ->json('data.id');
    $r = RectificationRequest::query()->findOrFail($id);
    expect($r->attachments()->first()->sha256)->toMatch('/^[a-f0-9]{64}$/');
    Notification::assertSentTo($this->manager, RectificationStageChanged::class);

    // Paso 3: el jefe inmediato autoriza → por firmar con plazo de 3 días hábiles.
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();
    $r->refresh();
    expect($r->stage)->toBe(RectificationStage::ToSign)
        ->and($r->authorized_by)->toBe($this->manager->id)
        // Autorizada el martes 17 → miércoles, jueves, viernes: vence el viernes 20 a las 23:59 (CDMX).
        ->and($r->signature_due_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-21 05:59');
    Notification::assertSentTo($this->user, RectificationStageChanged::class, fn ($n) => $n->event === 'to_sign');

    // Paso 4: firma del colaborador con NIP en la app.
    a2SetPin($this);
    $list = $this->mobile('GET', '/rectifications?stage=por_firmar')->assertOk();
    expect($list->json('data.0.signature_statement'))->toContain('Registro original: Sin salida')
        ->and($list->json('data.0.signature_payload_sha256'))->toMatch('/^[a-f0-9]{64}$/');

    $this->mobile('POST', "/rectifications/{$id}/sign", ['method' => 'pin', 'pin' => '4826'])->assertOk()
        ->assertJsonPath('data.stage', 'por_validar')
        ->assertJsonPath('data.signature.method', 'pin');
    $signature = ElectronicSignature::query()->sole();
    expect($signature->purpose)->toBe('rectification')
        ->and($signature->device_id)->toBe($this->device->id)
        ->and($signature->previous_hash)->toBe(HashChain::GENESIS)
        ->and(app(ElectronicSignatureService::class)->verifySeal($signature))->toBeTrue();
    Notification::assertSentTo($this->hr, RectificationStageChanged::class, fn ($n) => $n->event === 'signed');

    // Paso 5: validación RH → aplicada con ajuste encadenado.
    a2Decide($this, $this->hr, $r, comment: 'Validado')->assertSessionHasNoErrors();
    $r->refresh();
    expect($r->stage)->toBe(RectificationStage::Applied)
        ->and($r->status)->toBe(ApprovalStatus::Approved)
        ->and($r->validated_by)->toBe($this->hr->id)
        ->and($r->open_key)->toBeNull()
        ->and(Punch::query()->count())->toBe($punches);
    $adjustment = AttendanceAdjustment::query()->sole();
    expect($adjustment->action)->toBe(AdjustmentAction::Add)
        ->and($adjustment->approved_by)->toBe($this->hr->id)
        ->and($adjustment->rectification_request_id)->toBe($r->id);
    expect(ApprovalDecision::query()->where('approvable_id', $r->id)->pluck('level')->all())->toBe([1, 2]);
});

it('exige evidencia para guardar la solicitud (API y web)', function () {
    a2RequestMissingExit($this, withEvidence: false)->assertStatus(422)->assertJsonValidationErrors(['evidence']);

    $this->actingAs($this->hr)->post('/rectificaciones', [
        'employee_id' => $this->emp->id, 'date' => '2026-11-16', 'kind' => 'missing_punch',
        'proposed_type' => 'clock_out', 'proposed_time' => '18:00', 'reason' => 'Olvidó registrar su salida del día.',
    ])->assertSessionHasErrors('evidence');

    // Tipo de archivo no permitido.
    a2RequestMissingExit($this, ['evidence' => [UploadedFile::fake()->create('macro.exe', 10, 'application/octet-stream')]], withEvidence: false)
        ->assertStatus(422)->assertJsonValidationErrors(['evidence.0']);

    expect(RectificationRequest::query()->count())->toBe(0);
});

it('impide solicitudes duplicadas abiertas del mismo día y marcaje', function () {
    a2RequestMissingExit($this)->assertCreated();
    a2RequestMissingExit($this)->assertStatus(422)->assertJsonValidationErrors(['date']);
    // Otro marcaje omitido del mismo día sí procede.
    a2RequestMissingExit($this, ['proposed_type' => 'clock_in', 'proposed_time' => '2026-11-16T15:00:00Z'])->assertCreated();
});

it('no permite saltarse etapas con llamadas directas', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2SetPin($this);

    // Firmar antes de la autorización.
    $this->mobile('POST', "/rectifications/{$r->id}/sign", ['method' => 'pin', 'pin' => '4826'])
        ->assertStatus(422)->assertJsonValidationErrors(['stage']);
    // Validar RH mientras está por autorizar (etapa declarada falsa).
    a2Decide($this, $this->hr, $r, stage: 'por_validar')->assertSessionHasErrors('stage');
    // Llamada directa al dominio.
    expect(fn () => app(RectificationService::class)->validate($this->hr, $r, true, null))->toThrow(ValidationException::class);

    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();
    // Por firmar: ninguna decisión web aplica (ni RH).
    a2Decide($this, $this->hr, $r)->assertSessionHasErrors('stage');
    expect($r->fresh()->stage)->toBe(RectificationStage::ToSign)
        ->and(AttendanceAdjustment::query()->count())->toBe(0);
});

it('nadie aplica sin firma válida del colaborador, ni el super administrador', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();

    // Manipulación: se fuerza la etapa sin firma.
    $r->forceFill(['stage' => RectificationStage::ToValidate])->save();
    a2Decide($this, $this->hr, $r)->assertSessionHasErrors('signature');

    // Super administrador sin permiso operativo: sin autorización.
    $root = $this->userIn($this->org, null, allBranches: true);
    $root->forceFill(['is_super_admin' => true, 'two_factor_secret' => encrypt('TESTSECRET'), 'two_factor_confirmed_at' => now()])->save();
    a2Decide($this, $root, $r)->assertForbidden();
    expect(AttendanceAdjustment::query()->count())->toBe(0);
});

it('rechaza automáticamente al vencer el plazo de firma y el día queda como omisión', function () {
    Notification::fake();
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 23:00:00', 'UTC')); // aún en plazo
    $this->artisan('rectificaciones:vencer')->assertSuccessful();
    expect($r->fresh()->stage)->toBe(RectificationStage::ToSign);

    $this->travelTo(CarbonImmutable::parse('2026-11-21 06:00:00', 'UTC'));
    $this->artisan('rectificaciones:vencer')->assertSuccessful();
    $r->refresh();
    expect($r->stage)->toBe(RectificationStage::Rejected)
        ->and($r->rejection_stage)->toBe('vencida')
        ->and($r->status)->toBe(ApprovalStatus::Rejected)
        ->and(AttendanceAdjustment::query()->count())->toBe(0);
    Notification::assertSentTo($this->user, RectificationStageChanged::class, fn ($n) => $n->event === 'expired');
});

it('no acepta la firma fuera de plazo', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();
    a2SetPin($this);

    $this->travelTo(CarbonImmutable::parse('2026-11-21 07:00:00', 'UTC'));
    $token = $this->tokenFor($this->user, $this->device);
    $this->mobile('POST', "/rectifications/{$r->id}/sign", ['method' => 'pin', 'pin' => '4826'], $token)
        ->assertStatus(422)->assertJsonValidationErrors(['stage']);
    expect($r->fresh()->rejection_stage)->toBe('vencida');
});

it('permite al colaborador no aceptar la corrección con motivo', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();

    $this->mobile('POST', "/rectifications/{$r->id}/decline", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $this->mobile('POST', "/rectifications/{$r->id}/decline", ['reason' => 'La hora propuesta no es correcta'])->assertOk()
        ->assertJsonPath('data.stage', 'rechazada')
        ->assertJsonPath('data.rejection_stage', 'declinada');
});

it('exige motivo al rechazar y deja la solicitud rechazada', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r, 'reject')->assertSessionHasErrors('comment');
    a2Decide($this, $this->manager, $r, 'reject', 'Sin sustento suficiente')->assertSessionHasNoErrors();
    expect($r->fresh()->stage)->toBe(RectificationStage::Rejected)
        ->and($r->fresh()->rejection_stage)->toBe('por_autorizar');
    // Una vez cerrada, puede volver a solicitarse.
    a2RequestMissingExit($this)->assertCreated();
});

it('marca como extemporánea la rectificación posterior al cierre de nómina y sólo RH con permiso la aplica', function () {
    app(RectificationSettings::class)->save(['business_days' => 3, 'confirmed_by_hr' => false, 'closed_through' => '2026-11-16'], $this->hr->id);
    $id = a2RequestMissingExit($this)->assertCreated()->assertJsonPath('data.is_late', true)->json('data.id');
    $r = RectificationRequest::query()->findOrFail($id);
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();
    a2SetPin($this);
    $this->mobile('POST', "/rectifications/{$id}/sign", ['method' => 'pin', 'pin' => '4826'])->assertOk();

    // RH sin `rectifications.apply_late`.
    $validator = $this->userIn($this->org, null, allBranches: true);
    $validator->givePermissionTo(['rectifications.view', 'rectifications.validate']);
    $validator->forceFill(['two_factor_secret' => encrypt('TESTSECRET'), 'two_factor_confirmed_at' => now()])->save();
    a2Decide($this, $validator, $r)->assertForbidden();
    expect($r->fresh()->stage)->toBe(RectificationStage::ToValidate);

    a2Decide($this, $this->hr, $r)->assertSessionHasNoErrors();
    expect($r->fresh()->stage)->toBe(RectificationStage::Applied)->and($r->fresh()->is_late)->toBeTrue();
});

it('impide la autoautorización: ni el jefe sobre su registro ni quien capturó la solicitud', function () {
    $own = $this->employee($this->org, $this->manager, $this->schedule());
    $this->actingAs($this->manager)->post('/rectificaciones', [
        'employee_id' => $own->id, 'date' => '2026-11-16', 'kind' => 'missing_punch', 'proposed_type' => 'clock_out',
        'proposed_time' => '18:00', 'reason' => 'Olvidé registrar mi salida del lunes.',
        'evidence' => [UploadedFile::fake()->image('foto.jpg')],
    ])->assertRedirect();
    $mine = RectificationRequest::query()->where('employee_id', $own->id)->sole();
    a2Decide($this, $this->manager, $mine)->assertForbidden();

    // RH captura a nombre del colaborador: no puede autorizar su propia captura.
    $this->actingAs($this->hr)->post('/rectificaciones', [
        'employee_id' => $this->emp->id, 'date' => '2026-11-16', 'kind' => 'missing_punch', 'proposed_type' => 'clock_in',
        'proposed_time' => '09:00', 'reason' => 'Constancia del supervisor de la entrada.',
        'evidence' => [UploadedFile::fake()->create('constancia.pdf', 20, 'application/pdf')],
    ])->assertRedirect();
    $captured = RectificationRequest::query()->where('requested_by', $this->hr->id)->sole();
    a2Decide($this, $this->hr, $captured)->assertForbidden();
    a2Decide($this, $this->manager, $captured)->assertSessionHasNoErrors();
});

it('bloquea la doble decisión de una misma etapa', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r, stage: 'por_autorizar')->assertSessionHasNoErrors();
    a2Decide($this, $this->hr, $r, stage: 'por_autorizar')->assertSessionHasErrors('stage');
    expect(fn () => app(RectificationService::class)->authorize($this->hr, $r->fresh(), true, null))->toThrow(ValidationException::class);
    expect(ApprovalDecision::query()->where('approvable_id', $r->id)->count())->toBe(1);
});

it('aísla rectificaciones entre sucursales y colaboradores (IDOR)', function () {
    $other = Branch::query()->create(['code' => 'NORTE', 'name' => 'Norte']);
    $foreignEmp = $this->employee($this->org, schedule: $this->schedule(), branch: $other);
    ['device' => $foreignDevice] = $this->approvedDevice($foreignEmp->user, $foreignEmp);
    $foreignToken = $this->tokenFor($foreignEmp->user, $foreignDevice);

    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));

    // Otro colaborador no ve ni firma solicitudes ajenas.
    $this->mobile('POST', "/rectifications/{$r->id}/decline", ['reason' => 'Intento ajeno de rechazo'], $foreignToken)->assertNotFound();
    expect(collect($this->mobile('GET', '/rectifications', [], $foreignToken)->json('data'))->pluck('id'))->not->toContain($r->id);

    // Gerente de otra sucursal: ni detalle ni decisión.
    $otherManager = $this->userIn($this->org, 'Gerente de sucursal');
    $otherManager->branches()->attach($other->id, ['organization_id' => $this->org->id]);
    $this->actingAs($otherManager)->get("/rectificaciones/{$r->id}")->assertNotFound();
    a2Decide($this, $otherManager, $r)->assertNotFound();
    expect($r->fresh()->stage)->toBe(RectificationStage::ToAuthorize);
});

it('firma con biometría del teléfono validando la firma HMAC del dispositivo', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();
    $payload = $this->mobile('GET', '/rectifications')->json('data.0.signature_payload_sha256');

    $this->mobile('POST', "/rectifications/{$r->id}/sign", ['method' => 'biometric', 'device_signature' => str_repeat('a', 64)])
        ->assertStatus(422)->assertJsonValidationErrors(['device_signature']);

    $canonical = ElectronicSignatureService::biometricCanonical('rectification', $r->id, $payload, $this->device->device_uuid);
    $this->mobile('POST', "/rectifications/{$r->id}/sign", [
        'method' => 'biometric', 'device_signature' => DeviceSignature::sign($this->secret, $canonical),
    ])->assertOk()->assertJsonPath('data.stage', 'por_validar')->assertJsonPath('data.signature.method', 'biometric');

    expect(fn () => ElectronicSignature::query()->sole()->update(['method' => 'pin']))->toThrow(ImmutableRecordException::class);
});

it('muestra las cinco pestañas y acepta ?tab= en la URL', function () {
    $r = RectificationRequest::query()->findOrFail(a2RequestMissingExit($this)->json('data.id'));
    a2Decide($this, $this->manager, $r)->assertSessionHasNoErrors();

    $this->actingAs($this->hr)->get('/rectificaciones?tab=por_firmar')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('rectificaciones/Index')
            ->where('tab', 'por_firmar')
            ->where('counts.por_firmar', 1)
            ->where('counts.por_autorizar', 0)
            ->where('requests.data.0.original_summary', 'Sin salida')
            ->where('requests.data.0.proposal_summary', 'Salida 18:00')
            ->where('requests.data.0.signature_status', 'pendiente'));

    $this->actingAs($this->hr)->get('/rectificaciones?tab=inexistente')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'por_autorizar'));

    $this->actingAs($this->hr)->get("/rectificaciones/{$r->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('rectificaciones/Show')
            ->where('request.stage', 'por_firmar')
            ->where('signature', null));
});
