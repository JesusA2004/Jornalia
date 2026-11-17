<?php

use App\Domain\Biometrics\FaceVerificationProvider;
use App\Domain\Biometrics\FaceVerificationService;
use App\Models\AttendancePolicy;
use App\Models\AuditLog;
use App\Models\ComplianceAlert;
use App\Models\FaceChallenge;
use App\Models\FaceVerification;
use App\Models\Punch;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Concerns\ContractShapes;
use Tests\Feature\Api\Concerns\MobileApi;
use Tests\Feature\Biometrics\Concerns\FakesFaceService;

uses(MobileApi::class, FakesFaceService::class);

beforeEach(function () {
    $this->setUpMobile();
    $this->configureFaceService();
    $this->fakeFaceService();
});

/** Espejo de packages/contracts/src/api.ts (sección "Verificación facial"). */
const FACE_CHALLENGE_SHAPE = [
    'id' => 'uuid',
    'steps' => ['list', ['code' => 'enum:turn_left|turn_right|move_closer|move_away', 'label' => 'string']],
    'expires_at' => 'datetime',
];
const FACE_RESULT_SHAPE = [
    'id' => 'uuid',
    'result' => 'enum:match|no_match|liveness_failed|inconclusive|no_face|multiple_faces|poor_quality|error',
    'expires_at' => 'datetime',
];

it('informa no disponible sin servicio configurado', function () {
    config(['biometrics.service.url' => null]);
    app()->forgetInstance(FaceVerificationProvider::class);

    $res = $this->mobile('GET', '/face-verification/status')->assertOk();
    ContractShapes::assert($res->json('data'), 'FaceVerificationStatus');
    expect($res->json('data'))->toBe(['available' => false, 'provider' => null, 'enrolled' => false, 'consent_required' => true]);

    $this->assertApiError($this->mobile('POST', '/face-verification/challenge'), 503, 'face_unavailable');
});

it('informa disponible y enrolado', function () {
    $this->enrollDirectly($this->emp, $this->user);

    $this->mobile('GET', '/face-verification/status')->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.provider', 'opencv-open-source')
        ->assertJsonPath('data.enrolled', true);

    Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SERVICE.'/health' && $r->hasHeader('Authorization', 'Bearer '.self::SERVICE_TOKEN));
});

it('no disponible si el servicio no responde', function () {
    $this->refakeHttp([self::SERVICE.'/*' => Http::failedConnection()]);

    $this->mobile('GET', '/face-verification/status')->assertOk()->assertJsonPath('data.available', false);
});

it('emite un reto aleatorio de dos pasos distintos', function () {
    $this->enrollDirectly($this->emp, $this->user);

    $res = $this->mobile('POST', '/face-verification/challenge')->assertCreated();
    ContractShapes::check($res->json('data'), FACE_CHALLENGE_SHAPE, 'FaceChallenge');

    $codes = array_column($res->json('data.steps'), 'code');
    expect($codes)->toHaveCount(2)
        ->and(array_unique($codes))->toHaveCount(2)
        ->and($res->json('data.expires_at'))->toBe('2026-11-17T16:00:30Z');
    foreach ($codes as $code) {
        expect($code)->toBeIn(['turn_left', 'turn_right', 'move_closer', 'move_away']);
    }
});

it('todo reto incluye al menos un giro de cabeza', function () {
    $this->enrollDirectly($this->emp, $this->user);

    foreach (range(1, 8) as $_) {
        $codes = array_column($this->mobile('POST', '/face-verification/challenge')->json('data.steps'), 'code');
        expect(array_intersect($codes, ['turn_left', 'turn_right']))->not->toBeEmpty();
    }
});

it('rechaza el reto sin enrolamiento', function () {
    $this->assertApiError($this->mobile('POST', '/face-verification/challenge'), 409, 'face_not_enrolled');
});

it('verifica, registra el resultado y permite marcar con el id', function () {
    $enrollment = $this->enrollDirectly($this->emp, $this->user);
    $challenge = $this->mobile('POST', '/face-verification/challenge')->json('data');

    $res = $this->faceVerify($challenge['id'])->assertOk();
    ContractShapes::check($res->json('data'), FACE_RESULT_SHAPE, 'FaceVerificationResult');
    expect($res->json('data.result'))->toBe('match')
        ->and($res->json('data.expires_at'))->toBe('2026-11-17T16:02:00Z');

    $issued = FaceChallenge::query()->where('uuid', $challenge['id'])->firstOrFail();
    expect($issued->used_at)->not->toBeNull();

    Http::assertSent(function (HttpRequest $r) use ($enrollment, $challenge, $issued) {
        if ($r->url() !== self::SERVICE.'/verify') {
            return false;
        }
        $t0 = $issued->created_at->getTimestampMs();

        return $r->hasHeader('Authorization', 'Bearer '.self::SERVICE_TOKEN)
            && $r['template'] === $enrollment->template
            && count($r['frames']) === 6
            && str_starts_with(base64_decode($r['frames'][0]), "\xFF\xD8\xFF")
            && $r['timestamps_ms'][0] === $t0 + 400
            && $r['challenge']['steps'] === array_column($challenge['steps'], 'code')
            && $r['challenge']['expires_at_ms'] === $t0 + 30_000
            && $r['mirrored'] === true;
    });

    $verification = FaceVerification::query()->where('uuid', $res->json('data.id'))->firstOrFail();
    expect($verification->provider_metadata)->toHaveKeys(['score', 'liveness_score', 'challenge_passed'])
        ->and(json_encode($verification->provider_metadata))->not->toContain($enrollment->template);

    // PunchRecorder exige la verificación cuando la política lo pide.
    AttendancePolicy::query()->where('is_default', true)->update(['face_verification_required' => true]);
    $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge()))->assertStatus(422);
    $this->mobile('POST', '/attendance/punches', $this->signedPunch('clock_in', $this->challenge(), ['face_verification_id' => $verification->uuid]))->assertCreated();
    expect(Punch::query()->where('employee_id', $this->emp->id)->value('face_verification_id'))->toBe($verification->uuid)
        ->and($verification->fresh()->consumed_at)->not->toBeNull();
});

it('el reto es de un solo uso', function () {
    $this->enrollDirectly($this->emp, $this->user);
    $id = $this->mobile('POST', '/face-verification/challenge')->json('data.id');

    $this->faceVerify($id)->assertOk();
    $this->assertApiError($this->faceVerify($id), 422, 'face_challenge_invalid');
});

it('rechaza un reto expirado', function () {
    $this->enrollDirectly($this->emp, $this->user);
    $id = $this->mobile('POST', '/face-verification/challenge')->json('data.id');

    $this->travel(31)->seconds();
    $this->assertApiError($this->faceVerify($id), 422, 'face_challenge_expired');
});

it('rechaza timestamps no crecientes sin llamar al motor', function () {
    $this->enrollDirectly($this->emp, $this->user);
    $id = $this->mobile('POST', '/face-verification/challenge')->json('data.id');

    $this->faceVerify($id, timestamps: [0, 100, 100, 200, 300, 400])->assertOk()->assertJsonPath('data.result', 'liveness_failed');
    Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/verify'));
});

it('valida número y tipo de cuadros', function () {
    $this->enrollDirectly($this->emp, $this->user);
    $id = $this->mobile('POST', '/face-verification/challenge')->json('data.id');

    $this->assertApiError($this->faceVerify($id, frames: 5), 422, 'validation_failed');
    $this->assertApiError($this->faceVerify($id, frames: 6, timestamps: [1, 2, 3]), 422, 'validation_failed');
});

it('no permite usar el reto de otra persona', function () {
    $this->enrollDirectly($this->emp, $this->user);
    $other = $this->employee($this->org);
    $foreign = FaceChallenge::query()->create([
        'uuid' => (string) str()->uuid(), 'organization_id' => $this->org->id, 'employee_id' => $other->id,
        'steps' => ['turn_left', 'move_closer'], 'expires_at' => now()->addSeconds(30),
    ]);

    $this->assertApiError($this->faceVerify($foreign->uuid), 422, 'face_challenge_invalid');
});

it('bloquea tras 5 fallos en 15 minutos y genera alerta', function () {
    $this->fakeFaceService(verify: ['result' => 'no_match', 'score' => 0.12, 'liveness_score' => 0.95, 'challenge_passed' => true]);
    $this->enrollDirectly($this->emp, $this->user);

    foreach (range(1, 5) as $_) {
        $id = $this->mobile('POST', '/face-verification/challenge')->assertCreated()->json('data.id');
        $this->faceVerify($id)->assertOk()->assertJsonPath('data.result', 'no_match');
    }

    $this->assertApiError($this->mobile('POST', '/face-verification/challenge'), 429, 'face_locked');
    expect(ComplianceAlert::query()->where('employee_id', $this->emp->id)->where('rule_code', 'face_verification_lockout')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'biometrics.face.locked')->count())->toBe(1);

    $this->travel(16)->minutes();
    $this->mobile('POST', '/face-verification/challenge')->assertCreated();
});

it('responde 503 si el motor cae y no cuenta como fallo', function () {
    $this->enrollDirectly($this->emp, $this->user);
    $id = $this->mobile('POST', '/face-verification/challenge')->json('data.id');

    $this->refakeHttp([self::SERVICE.'/health' => Http::response(['status' => 'ok', 'provider' => 'opencv-open-source']), self::SERVICE.'/verify' => Http::failedConnection()]);

    $this->assertApiError($this->faceVerify($id), 503, 'face_unavailable');
    expect(FaceVerification::query()->where('employee_id', $this->emp->id)->value('result'))->toBe('error')
        ->and(app(FaceVerificationService::class)->recentFailures($this->emp))->toBe(0);
});

it('devuelve los resultados del motor tal cual el contrato', function (string $result) {
    $this->fakeFaceService(verify: ['result' => $result, 'score' => null, 'liveness_score' => null, 'challenge_passed' => false]);
    $this->enrollDirectly($this->emp, $this->user);
    $id = $this->mobile('POST', '/face-verification/challenge')->json('data.id');

    $this->faceVerify($id)->assertOk()->assertJsonPath('data.result', $result);
})->with(['no_match', 'liveness_failed', 'inconclusive', 'no_face', 'multiple_faces', 'poor_quality']);
