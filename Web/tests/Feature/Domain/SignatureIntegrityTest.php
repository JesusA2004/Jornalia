<?php

use App\Domain\Attendance\HashChain;
use App\Domain\Attendance\IntegrityAnchorService;
use App\Domain\Attendance\IntegrityChains;
use App\Domain\Attendance\IntegrityVerifier;
use App\Domain\Compliance\IntegrityMonitor;
use App\Domain\Signatures\ElectronicSignatureService;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Signatures\SignaturePinService;
use App\Models\ComplianceAlert;
use App\Models\ElectronicSignature;
use App\Models\IntegrityAnchor;
use App\Models\PolicyDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/*
| La cadena de firmas electrónicas forma parte de la verificación de
| integridad y del anclaje diario, igual que marcajes y ajustes.
*/

beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->employee = $this->employee($this->org);
    app(SignaturePinService::class)->set($this->employee->user, '4826');

    $this->signDocument = function (string $code): ElectronicSignature {
        $body = "# {$code}\n\nTexto del documento.";
        $doc = PolicyDocument::query()->create([
            'code' => $code, 'title' => $code, 'version' => '1.0', 'body_markdown' => $body,
            'sha256' => hash('sha256', $body), 'requires_acknowledgement' => true, 'published_at' => now()->subMinute(),
        ]);
        $purpose = $code === RegistrationDocuments::AGREEMENT
            ? ElectronicSignature::PURPOSE_REGISTRATION_AGREEMENT
            : ElectronicSignature::PURPOSE_PRIVACY_NOTICE;

        return app(ElectronicSignatureService::class)->sign(
            $this->employee->user, $doc, $purpose, ElectronicSignature::METHOD_PIN, '4826', null, "Acepto {$code} versión 1.0",
        );
    };
});

it('encadena las firmas y las ancla junto con marcajes y ajustes', function () {
    $first = ($this->signDocument)(RegistrationDocuments::AGREEMENT);
    $second = ($this->signDocument)(RegistrationDocuments::PRIVACY);

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($second->previous_hash)->toBe($first->hash);

    $created = app(IntegrityAnchorService::class)->anchor($this->org->id);
    $anchor = collect($created)->firstWhere('chain', IntegrityChains::SIGNATURES);
    expect($anchor)->not->toBeNull()
        ->and((int) $anchor->sequence)->toBe(2)
        ->and($anchor->hash)->toBe($second->hash);

    $result = app(IntegrityVerifier::class)->verify($this->org->id, IntegrityChains::SIGNATURES);
    expect($result['valid'])->toBeTrue()
        ->and($result['checked'])->toBe(2)
        ->and($result['anchors_checked'])->toBe(1);

    $daily = app(IntegrityMonitor::class)->verify($this->org, automatic: true);
    expect(collect($daily['chains'])->pluck('chain')->all())->toContain(IntegrityChains::SIGNATURES)
        ->and($daily['valid'])->toBeTrue();
});

it('impide modificar o eliminar firmas a nivel de base de datos (triggers)', function () {
    $signature = ($this->signDocument)(RegistrationDocuments::AGREEMENT);

    expect(fn () => DB::table('electronic_signatures')->where('id', $signature->id)->update(['statement' => 'Otro texto']))
        ->toThrow(QueryException::class, 'Registro inmutable')
        ->and(fn () => DB::table('electronic_signatures')->where('id', $signature->id)->delete())
        ->toThrow(QueryException::class, 'Registro inmutable');
});

it('detecta una firma fabricada con acceso directo a la base y alerta en Cumplimiento', function () {
    $signature = ($this->signDocument)(RegistrationDocuments::AGREEMENT);
    app(IntegrityAnchorService::class)->anchor($this->org->id);

    // Sin la clave de sellado, el atacante sólo puede calcular un sha256 plano.
    $forged = (array) DB::table('electronic_signatures')->where('id', $signature->id)->first();
    unset($forged['id']);
    DB::table('electronic_signatures')->insert(array_merge($forged, [
        'uuid' => (string) Str::uuid(),
        'sequence' => $signature->sequence + 1,
        'previous_hash' => $signature->hash,
        'hash' => hash('sha256', 'falsificado'),
    ]));

    $result = app(IntegrityVerifier::class)->verify($this->org->id, IntegrityChains::SIGNATURES);
    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('error'))->toContain('Contenido alterado: el hash no coincide');

    $daily = app(IntegrityMonitor::class)->verify($this->org, automatic: true);
    expect($daily['valid'])->toBeFalse()
        ->and(ComplianceAlert::query()->withoutGlobalScopes()->where('rule_code', 'integrity_failed')->exists())->toBeTrue();
});

it('detecta que el texto firmado no corresponde a su SHA-256 aunque el sello cuadre', function () {
    $signature = ($this->signDocument)(RegistrationDocuments::AGREEMENT);

    // Eslabón con sello HMAC correcto pero cuyo texto no es el que se firmó.
    $row = (array) DB::table('electronic_signatures')->where('id', $signature->id)->first();
    unset($row['id']);
    $tampered = new ElectronicSignature(array_merge($row, [
        'uuid' => (string) Str::uuid(),
        'statement' => 'Texto distinto al que vio el colaborador',
        'sequence' => $signature->sequence + 1,
        'previous_hash' => $signature->hash,
        'hash' => null,
    ]));
    $tampered->hash = app(HashChain::class)->hash($signature->hash, HashChain::signaturePayload($tampered));
    DB::table('electronic_signatures')->insert(array_merge($row, [
        'uuid' => $tampered->uuid,
        'statement' => $tampered->statement,
        'sequence' => $tampered->sequence,
        'previous_hash' => $tampered->previous_hash,
        'hash' => $tampered->hash,
    ]));

    $result = app(IntegrityVerifier::class)->verify($this->org->id, IntegrityChains::SIGNATURES);
    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('error')->all())->toBe(['Texto firmado alterado: el SHA-256 no coincide']);
});

it('rechaza verificar una cadena desconocida', function () {
    app(IntegrityVerifier::class)->verify($this->org->id, 'inexistente');
})->throws(InvalidArgumentException::class);

it('muestra la cadena de firmas y sus anclas en Cumplimiento › Integridad', function () {
    $this->withoutVite();
    ($this->signDocument)(RegistrationDocuments::AGREEMENT);
    app(IntegrityAnchorService::class)->anchor($this->org->id);
    $hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);

    expect(IntegrityAnchor::query()->where('chain', IntegrityChains::SIGNATURES)->count())->toBe(1);

    $this->actingAs($hr)->get('/cumplimiento/integridad')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('cumplimiento/Integridad')
            ->where('chains.2.chain', IntegrityChains::SIGNATURES)
            ->where('chains.2.label', 'Firmas electrónicas')
            ->where('chains.2.records', 1)
            ->where('chains.2.unanchored', 0)
            ->where('anchors.data.0.chain_label', 'Firmas'));
});
