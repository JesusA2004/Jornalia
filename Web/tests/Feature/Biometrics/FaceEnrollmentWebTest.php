<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\FaceEnrollment;
use App\Models\PolicyDocument;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;
use Tests\Feature\Biometrics\Concerns\FakesFaceService;

uses(BuildsWorkforce::class, FakesFaceService::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->configureFaceService();
    $this->fakeFaceService();
    $this->org = $this->organization();
    $this->rh = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $this->emp = $this->employee($this->org);
    $this->url = "/colaboradores/{$this->emp->id}/rostro";
});

function enrollPayload(object $test, array $overrides = []): array
{
    return array_merge(['frames' => $test->jpegFrames(3), 'identity_verified' => '1', 'consent' => '1'], $overrides);
}

it('niega la página sin employees.update', function () {
    $user = $this->userIn($this->org, 'Supervisor');

    $this->actingAs($user)->get($this->url)->assertForbidden();
    $this->actingAs($user)->post($this->url, enrollPayload($this))->assertForbidden();
});

it('muestra el estado del registro facial', function () {
    $this->actingAs($this->rh)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('biometria/Rostro')
        ->where('employee.id', $this->emp->id)
        ->where('service.available', true)
        ->where('service.provider', 'opencv-open-source')
        ->where('consentDocument', null)
        ->has('enrollments', 0));
});

it('enrola con consentimiento, cifra la plantilla y audita', function () {
    $doc = PolicyDocument::query()->create([
        'code' => 'BIOMETRIA', 'title' => 'Consentimiento biométrico', 'version' => '1.0',
        'body_markdown' => 'Texto', 'sha256' => hash('sha256', 'Texto'), 'published_at' => now(),
    ]);

    $this->actingAs($this->rh)->post($this->url, enrollPayload($this))->assertSessionHasNoErrors();

    $enrollment = FaceEnrollment::query()->where('employee_id', $this->emp->id)->sole();
    $raw = DB::table('face_enrollments')->where('id', $enrollment->id)->value('template');

    expect($enrollment->template)->toBe(self::fakeTemplate())
        ->and($raw)->not->toBe(self::fakeTemplate())
        ->and($raw)->not->toContain(self::fakeTemplate())
        ->and($enrollment->toArray())->not->toHaveKey('template')
        ->and($enrollment->status)->toBe('active')
        ->and($enrollment->consent_document_id)->toBe($doc->id)
        ->and($enrollment->consent_document_sha256)->toBe($doc->sha256)
        ->and($enrollment->identity_verified_by)->toBe($this->rh->id);

    Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SERVICE.'/enroll' && count($r['frames']) === 3);
    expect(AuditLog::query()->where('action', 'biometrics.face.enrolled')->count())->toBe(1);
});

it('exige confirmar identidad y consentimiento', function () {
    $this->actingAs($this->rh)->post($this->url, enrollPayload($this, ['consent' => '0', 'identity_verified' => '0']))
        ->assertSessionHasErrors(['consent', 'identity_verified']);
    $this->actingAs($this->rh)->post($this->url, enrollPayload($this, ['frames' => $this->jpegFrames(2)]))
        ->assertSessionHasErrors('frames');

    expect(FaceEnrollment::query()->count())->toBe(0);
    Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/enroll'));
});

it('informa el rechazo del motor sin enrolar', function () {
    $this->fakeFaceService(enroll: ['result' => 'poor_quality', 'reasons' => ['too_dark']], enrollStatus: 422);

    $this->actingAs($this->rh)->post($this->url, enrollPayload($this))->assertSessionHasErrors(['frames' => 'Calidad insuficiente: falta luz.']);
    expect(FaceEnrollment::query()->count())->toBe(0);
});

it('informa si el motor no está disponible', function () {
    $this->refakeHttp([self::SERVICE.'/*' => Http::failedConnection()]);

    $this->actingAs($this->rh)->post($this->url, enrollPayload($this))->assertSessionHasErrors('frames');
    expect(FaceEnrollment::query()->count())->toBe(0);
});

it('re-enrolar revoca el registro anterior sin borrarlo', function () {
    $old = $this->enrollDirectly($this->emp, $this->rh);

    $this->actingAs($this->rh)->post($this->url, enrollPayload($this))->assertSessionHasNoErrors();

    expect(FaceEnrollment::query()->where('employee_id', $this->emp->id)->count())->toBe(2)
        ->and($old->fresh()->status)->toBe('revoked')
        ->and(FaceEnrollment::query()->where('employee_id', $this->emp->id)->active()->count())->toBe(1);
});

it('revoca con motivo y conserva la plantilla cifrada', function () {
    $enrollment = $this->enrollDirectly($this->emp, $this->rh);

    $this->actingAs($this->rh)->post("{$this->url}/{$enrollment->id}/revocar", ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->actingAs($this->rh)->post("{$this->url}/{$enrollment->id}/revocar", ['reason' => 'Solicitud del colaborador'])->assertSessionHasNoErrors();

    $enrollment->refresh();
    expect($enrollment->status)->toBe('revoked')
        ->and($enrollment->revoked_by)->toBe($this->rh->id)
        ->and($enrollment->template)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'biometrics.face.revoked')->count())->toBe(1);
});

it('cancelación ARCO vacía la plantilla y conserva el registro', function () {
    $enrollment = $this->enrollDirectly($this->emp, $this->rh);

    $this->actingAs($this->rh)->post("{$this->url}/{$enrollment->id}/cancelar", ['reason' => 'ARCO-2026-001'])->assertSessionHasErrors('confirm');
    $this->actingAs($this->rh)->post("{$this->url}/{$enrollment->id}/cancelar", ['reason' => 'ARCO-2026-001', 'confirm' => '1'])->assertSessionHasNoErrors();

    $enrollment->refresh();
    expect($enrollment->template)->toBeNull()
        ->and($enrollment->template_erased_at)->not->toBeNull()
        ->and($enrollment->status)->toBe('revoked')
        ->and(DB::table('face_enrollments')->where('id', $enrollment->id)->value('template'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'biometrics.face.template_erased')->value('context'))->toMatchArray(['reason' => 'ARCO-2026-001']);
});

it('no permite borrar físicamente un enrolamiento', function () {
    $enrollment = $this->enrollDirectly($this->emp, $this->rh);

    expect(fn () => $enrollment->delete())->toThrow(ImmutableRecordException::class);
});

it('aísla enrolamientos entre organizaciones', function () {
    $enrollment = $this->enrollDirectly($this->emp, $this->rh);
    $orgB = $this->organization('Empresa B');
    $rhB = $this->userIn($orgB, 'Recursos Humanos', allBranches: true);
    $empB = $this->employee($orgB);

    $this->actingAs($rhB)->get($this->url)->assertNotFound();
    $this->actingAs($rhB)->post("/colaboradores/{$empB->id}/rostro/{$enrollment->id}/revocar", ['reason' => 'Intento ajeno'])->assertNotFound();
    expect($enrollment->fresh()->status)->toBe('active');
});
