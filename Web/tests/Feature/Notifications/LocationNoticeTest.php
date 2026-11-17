<?php

use App\Domain\Notifications\LocationNotice;
use App\Models\PolicyDocument;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

it('publica una sola vez el aviso de ubicación que requiere acuse', function () {
    $doc = LocationNotice::ensureFor($this->org);
    LocationNotice::ensureFor($this->org);
    Artisan::call('asistencias:aviso-ubicacion');

    expect(PolicyDocument::query()->where('code', 'UBICACION')->count())->toBe(1)
        ->and($doc->requires_acknowledgement)->toBeTrue()
        ->and($doc->published_at)->not->toBeNull()
        ->and($doc->sha256)->toBe(hash('sha256', $doc->body_markdown))
        ->and($doc->body_markdown)->toContain('Fuera de tu turno no se registra');

    $orgB = $this->organization('Empresa B');
    Artisan::call('asistencias:aviso-ubicacion');
    expect(PolicyDocument::query()->withoutGlobalScopes()->where('code', 'UBICACION')->where('organization_id', $orgB->id)->count())->toBe(1);
});

it('el colaborador lo ve pendiente en la app y lo acusa', function () {
    $doc = LocationNotice::ensureFor($this->org);

    $pending = collect($this->mobile('GET', '/profile')->assertOk()->json('data.pending_acknowledgements'));
    expect($pending->pluck('id'))->toContain($doc->id);

    $this->mobile('POST', "/acknowledgements/{$doc->id}", ['accepted' => true, 'device_uuid' => $this->device->device_uuid])->assertSuccessful();
    $pending = collect($this->mobile('GET', '/profile')->json('data.pending_acknowledgements'));
    expect($pending->pluck('id'))->not->toContain($doc->id);
});
