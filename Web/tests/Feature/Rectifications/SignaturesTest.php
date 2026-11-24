<?php

use App\Domain\Signatures\RegistrationDocuments;
use App\Models\Attachment;
use App\Models\DocumentAcknowledgement;
use App\Models\ElectronicSignature;
use App\Models\PolicyDocument;
use Carbon\CarbonImmutable;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

/*
| NIP de firma, convenio de registro electrónico y aviso de privacidad.
*/

beforeEach(function () {
    $this->setUpMobile();
});

function a2Publish(string $code, string $version = '1.0', ?CarbonImmutable $at = null): PolicyDocument
{
    $body = "# {$code}\n\nTexto del documento versión {$version}.";

    return PolicyDocument::query()->create([
        'code' => $code, 'title' => $code === RegistrationDocuments::AGREEMENT ? 'Convenio de registro electrónico' : 'Aviso de privacidad',
        'version' => $version, 'body_markdown' => $body, 'sha256' => hash('sha256', $body),
        'requires_acknowledgement' => true, 'published_at' => $at ?? now()->subMinute(),
    ]);
}

it('define el NIP con reglas y exige el actual para cambiarlo', function () {
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '12'])->assertStatus(422)->assertJsonValidationErrors(['pin']);
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '1111'])->assertStatus(422)->assertJsonValidationErrors(['pin']);
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '1234'])->assertStatus(422)->assertJsonValidationErrors(['pin']);
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '4826'])->assertOk();

    expect($this->user->fresh()->signature_pin_hash)->not->toBe('4826')
        ->and($this->user->fresh()->toArray())->not->toHaveKey('signature_pin_hash');

    $this->mobile('POST', '/profile/signature-pin', ['pin' => '9371'])->assertStatus(422)->assertJsonValidationErrors(['current_pin']);
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '9371', 'current_pin' => '0000'])->assertStatus(422)->assertJsonValidationErrors(['current_pin']);
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '9371', 'current_pin' => '4826'])->assertOk();
});

it('bloquea el NIP 15 minutos tras 5 intentos fallidos', function () {
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '4826'])->assertOk();
    $doc = a2Publish(RegistrationDocuments::PRIVACY);

    foreach (range(1, 5) as $i) {
        $this->mobile('POST', "/documents/{$doc->id}/sign", ['method' => 'pin', 'pin' => '9999'])->assertStatus(422);
    }
    expect($this->user->fresh()->signature_pin_locked_until)->not->toBeNull();
    // Bloqueado: ni el NIP correcto funciona.
    $this->mobile('POST', "/documents/{$doc->id}/sign", ['method' => 'pin', 'pin' => '4826'])->assertStatus(422);

    $this->travel(16)->minutes();
    $token = $this->tokenFor($this->user, $this->device);
    $this->mobile('POST', "/documents/{$doc->id}/sign", ['method' => 'pin', 'pin' => '4826'], $token)->assertOk();
});

it('lista y firma el convenio y el aviso vigentes; una versión nueva vuelve a quedar pendiente', function () {
    $agreement = a2Publish(RegistrationDocuments::AGREEMENT);
    $privacy = a2Publish(RegistrationDocuments::PRIVACY);
    $this->mobile('POST', '/profile/signature-pin', ['pin' => '4826'])->assertOk();

    $pending = $this->mobile('GET', '/documents/pending')->assertOk()->assertJsonCount(2, 'data');
    expect($pending->json('data.0'))->toHaveKeys(['id', 'code', 'purpose', 'title', 'version', 'body_markdown', 'sha256', 'pdf_url', 'signature_statement', 'signature_payload_sha256']);
    expect($this->emp->hasSignedRegistrationAgreement())->toBeFalse()->and($this->emp->hasAcceptedPrivacyNotice())->toBeFalse();

    $this->mobile('POST', "/documents/{$agreement->id}/sign", ['method' => 'pin', 'pin' => '4826'])->assertOk()
        ->assertJsonPath('data.code', 'CONVENIO_REGISTRO');
    // Idempotente: una segunda firma no duplica.
    $this->mobile('POST', "/documents/{$agreement->id}/sign", ['method' => 'pin', 'pin' => '4826'])->assertOk();
    expect(ElectronicSignature::query()->count())->toBe(1)
        ->and(DocumentAcknowledgement::query()->where('policy_document_id', $agreement->id)->count())->toBe(1)
        ->and($this->emp->hasSignedRegistrationAgreement())->toBeTrue();

    $this->mobile('GET', '/documents/pending')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AVISO_PRIVACIDAD');
    $this->mobile('GET', "/documents/{$privacy->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

    // Versión nueva del convenio: debe firmarse otra vez; la anterior ya no se acepta.
    $this->travel(1)->minutes();
    $v2 = a2Publish(RegistrationDocuments::AGREEMENT, '2.0');
    expect($this->emp->hasSignedRegistrationAgreement())->toBeFalse();
    $token = $this->tokenFor($this->user, $this->device);
    $this->mobile('POST', "/documents/{$agreement->id}/sign", ['method' => 'pin', 'pin' => '4826'], $token)->assertStatus(422);
    $this->mobile('GET', '/documents/pending', [], $token)->assertJsonFragment(['id' => $v2->id]);
});

it('cuenta el convenio firmado en papel adjunto al expediente', function () {
    a2Publish(RegistrationDocuments::AGREEMENT);
    Attachment::query()->create([
        'organization_id' => $this->org->id, 'attachable_type' => $this->emp->getMorphClass(), 'attachable_id' => $this->emp->id,
        'category' => RegistrationDocuments::PAPER_AGREEMENT_CATEGORY, 'disk' => 'local', 'path' => 'x/convenio.pdf',
        'original_name' => 'convenio.pdf', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64),
    ]);

    expect($this->emp->hasSignedRegistrationAgreement())->toBeTrue();
    $this->mobile('GET', '/documents/pending')->assertJsonCount(0, 'data');
});

it('conserva el acuse anterior por compatibilidad sin contarlo como firma', function () {
    $privacy = a2Publish(RegistrationDocuments::PRIVACY);
    $this->mobile('POST', "/acknowledgements/{$privacy->id}", ['accepted' => true, 'device_uuid' => $this->device->device_uuid])->assertNoContent();

    expect(DocumentAcknowledgement::query()->count())->toBe(1)
        ->and($this->emp->hasAcceptedPrivacyNotice())->toBeFalse();
    $this->mobile('GET', '/documents/pending')->assertJsonCount(1, 'data');
});
