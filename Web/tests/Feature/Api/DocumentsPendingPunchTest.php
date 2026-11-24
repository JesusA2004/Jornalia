<?php

use App\Domain\Attendance\AttendanceException;
use App\Domain\Security\KioskService;
use App\Domain\Signatures\PunchDocumentsGate;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Signatures\SignaturePinService;
use App\Enums\PunchType;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\ComplianceAlert;
use App\Models\Employee;
use App\Models\PolicyDocument;
use App\Models\Punch;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

/*
| Validación en el servidor del convenio de registro y del aviso de privacidad al
| marcar (Revisión RH, pantalla 09 y regla general 6): en línea se rechaza con
| `documents_pending`; sin conexión se registra y se alerta a RH.
*/

beforeEach(function () {
    $this->setUpMobile();
});

function docsPublish(string $code): PolicyDocument
{
    $body = "# {$code}\n\nTexto vigente.";

    return PolicyDocument::query()->create([
        'code' => $code, 'title' => $code === RegistrationDocuments::AGREEMENT ? 'Convenio de registro electrónico' : 'Aviso de privacidad',
        'version' => '1.0', 'body_markdown' => $body, 'sha256' => hash('sha256', $body),
        'requires_acknowledgement' => true, 'published_at' => now()->subMinute(),
    ]);
}

function docsPaperAgreement(Employee $employee): void
{
    Attachment::query()->create([
        'organization_id' => $employee->organization_id, 'attachable_type' => $employee->getMorphClass(), 'attachable_id' => $employee->id,
        'category' => RegistrationDocuments::PAPER_AGREEMENT_CATEGORY, 'disk' => 'local', 'path' => 'x/convenio.pdf',
        'original_name' => 'convenio.pdf', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function docsKioskPunch(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'employee_number' => $employee->employee_number, 'pin' => '4826', 'type' => PunchType::ClockIn->value,
        'client_uuid' => (string) Str::uuid(), 'device_time' => now()->utc()->toIso8601String(), 'timezone' => 'America/Mexico_City',
    ], $overrides);
}

it('rechaza el marcaje en línea con convenio o aviso pendientes (documents_pending)', function () {
    docsPublish(RegistrationDocuments::AGREEMENT);
    docsPublish(RegistrationDocuments::PRIVACY);

    $response = $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge()));

    $this->assertApiError($response, 409, 'documents_pending');
    $response->assertJsonPath('legacy_code', 'policy_violation');
    expect($response->json('message'))->toContain('convenio')->toContain('aviso de privacidad')
        ->and(Punch::query()->where('employee_id', $this->emp->id)->count())->toBe(0);
});

it('acepta el marcaje sin conexión capturado antes de sincronizar y alerta a RH', function () {
    docsPublish(RegistrationDocuments::AGREEMENT);
    $offline = $this->signedPunch('clock_in', null, ['device_time' => now()->subMinutes(30)->utc()->format('Y-m-d\TH:i:s.v\Z')]);

    $results = $this->mobile('POST', '/sync/punches', ['punches' => [$offline]])->assertOk()->json('data.results');

    expect($results[0]['result'])->toBe('created')
        ->and(Punch::query()->where('employee_id', $this->emp->id)->where('captured_offline', true)->count())->toBe(1);
    $alert = ComplianceAlert::query()->where('employee_id', $this->emp->id)->where('rule_code', PunchDocumentsGate::RULE_PUNCH_WITHOUT_AGREEMENT)->first();
    expect($alert)->not->toBeNull()
        ->and($alert->status)->toBe('open')
        ->and($alert->data['documents'])->toBe([RegistrationDocuments::AGREEMENT])
        ->and($alert->data['punches'][0]['punch_uuid'])->toBe($results[0]['punch']['uuid']);
});

it('rechaza en el kiosco en línea y registra la cola sin conexión con alerta', function () {
    $branch = Branch::query()->create(['code' => 'SUCK', 'name' => 'Sucursal Kiosco']);
    $employee = $this->employee($this->org, branch: $branch);
    app(SignaturePinService::class)->set($employee->user, '4826');
    $admin = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
    $code = app(KioskService::class)->create($admin, $branch, 'Tableta')['activation_code'];
    $token = (string) $this->postJson('/api/v1/kiosk/activate', [
        'activation_code' => $code,
        'device' => ['device_uuid' => (string) Str::uuid(), 'platform' => 'android', 'os_version' => '14', 'app_version' => '1.2.0', 'model' => 'Tab'],
    ])->assertCreated()->json('data.token');
    docsPublish(RegistrationDocuments::AGREEMENT);

    $this->withToken($token)->postJson('/api/v1/kiosk/punches', docsKioskPunch($employee))
        ->assertStatus(409)->assertJsonPath('code', 'documents_pending')->assertJsonPath('legacy_code', 'policy_violation');
    expect(Punch::query()->where('employee_id', $employee->id)->count())->toBe(0);

    $item = docsKioskPunch($employee, ['device_time' => now()->subMinutes(20)->utc()->toIso8601String()]);
    $results = $this->withToken($token)->postJson('/api/v1/kiosk/sync', ['punches' => [$item]])->assertOk()->json('data.results');

    expect($results[0]['result'])->toBe('created')
        ->and(ComplianceAlert::query()->where('employee_id', $employee->id)->where('rule_code', PunchDocumentsGate::RULE_PUNCH_WITHOUT_AGREEMENT)->value('data')['documents'])
        ->toBe([RegistrationDocuments::AGREEMENT]);
});

it('en el kiosco el aviso de privacidad pendiente no bloquea: registra y alerta a RH', function () {
    $branch = Branch::query()->create(['code' => 'SUKP', 'name' => 'Sucursal Kiosco Aviso']);
    $employee = $this->employee($this->org, branch: $branch);
    app(SignaturePinService::class)->set($employee->user, '4826');
    $admin = $this->userIn($this->org, 'Administrador de plataforma', allBranches: true);
    $code = app(KioskService::class)->create($admin, $branch, 'Tableta')['activation_code'];
    $token = (string) $this->postJson('/api/v1/kiosk/activate', [
        'activation_code' => $code,
        'device' => ['device_uuid' => (string) Str::uuid(), 'platform' => 'android', 'os_version' => '14', 'app_version' => '1.2.0', 'model' => 'Tab'],
    ])->assertCreated()->json('data.token');
    // El kiosco no tiene dónde aceptar el aviso: el marcaje en línea se registra y se alerta.
    docsPublish(RegistrationDocuments::PRIVACY);

    $this->withToken($token)->postJson('/api/v1/kiosk/punches', docsKioskPunch($employee))->assertCreated();

    expect(Punch::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(ComplianceAlert::query()->where('employee_id', $employee->id)->where('rule_code', PunchDocumentsGate::RULE_PUNCH_WITHOUT_AGREEMENT)->value('data')['documents'])
        ->toBe([RegistrationDocuments::PRIVACY]);
});

it('el convenio en papel adjunto al expediente permite marcar en línea', function () {
    docsPublish(RegistrationDocuments::AGREEMENT);
    docsPaperAgreement($this->emp);

    $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge()))->assertCreated();

    expect(ComplianceAlert::query()->where('rule_code', PunchDocumentsGate::RULE_PUNCH_WITHOUT_AGREEMENT)->exists())->toBeFalse();
});

it('sin documentos publicados no bloquea y deja una sola alerta de configuración', function () {
    $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge()))->assertCreated();
    $this->mobile('POST', '/attendance/punches', $this->signedPunch('break_start', $this->challenge()))->assertCreated();

    $alerts = ComplianceAlert::query()->where('rule_code', PunchDocumentsGate::RULE_DOCUMENTS_UNPUBLISHED)->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->employee_id)->toBeNull()
        ->and($alerts->first()->data['documents'])->toBe([RegistrationDocuments::AGREEMENT, RegistrationDocuments::PRIVACY]);

    // Publicado sólo el convenio: la alerta se actualiza al aviso faltante.
    docsPublish(RegistrationDocuments::AGREEMENT);
    docsPaperAgreement($this->emp);
    $this->mobile('POST', '/attendance/punches', $this->signedPunch('break_end', $this->challenge()))->assertCreated();
    expect(ComplianceAlert::query()->where('rule_code', PunchDocumentsGate::RULE_DOCUMENTS_UNPUBLISHED)->count())->toBe(1)
        ->and(ComplianceAlert::query()->where('rule_code', PunchDocumentsGate::RULE_DOCUMENTS_UNPUBLISHED)->value('data')['documents'])
        ->toBe([RegistrationDocuments::PRIVACY]);
});

it('el marcaje web en línea también exige los documentos', function () {
    docsPublish(RegistrationDocuments::AGREEMENT);

    expect(fn () => $this->punchAt($this->emp, PunchType::ClockIn, '2026-11-17 16:00:00'))
        ->toThrow(AttendanceException::class, 'convenio de registro electrónico');
    expect(Punch::query()->where('employee_id', $this->emp->id)->count())->toBe(0);
});
