<?php

use App\Models\AuditLog;
use App\Models\LegalEntity;
use App\Models\PolicyDocument;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/*
| Configuración (PDF pantalla 19): datos fiscales, contacto de privacidad sin
| datos de ejemplo, paleta institucional, aviso de privacidad en PDF
| obligatorio y versionado, ubicación por puesto y políticas del sistema.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 18:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->admin = $this->userIn($this->org, 'Administrador de empresa', allBranches: true);
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => storage_path('framework/testing/b2/'.Str::random(12))]));
});

function companyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Mr. Lana',
        'legal_name' => 'PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. de C.V.',
        'rfc' => 'psm2311289j2',
        'fiscal_address' => 'Subida del Club 114, Col. Reforma, C.P. 62260, Cuernavaca, Morelos',
        'timezone' => 'America/Mexico_City',
        'privacy_contact_email' => null,
        'brand_primary_color' => '#225C54',
    ], $overrides);
}

it('guarda razón social, RFC, domicilio fiscal y nombre comercial con bitácora', function () {
    $this->actingAs($this->admin)->put('/configuracion/empresa', companyPayload())->assertSessionHasNoErrors();

    $entity = LegalEntity::query()->sole();
    expect($entity->legal_name)->toBe('PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. de C.V.')
        ->and($entity->rfc)->toBe('PSM2311289J2')
        ->and($this->org->fresh()->name)->toBe('Mr. Lana')
        ->and($this->org->fresh()->privacy_contact_email)->toBeNull();

    $log = AuditLog::query()->where('action', 'organization.updated')->latest('id')->firstOrFail();
    expect($log->new_values['legal_entity']['company_rfc'])->toBe('PSM2311289J2');

    $this->actingAs($this->admin)->get('/configuracion')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('configuracion/Company')
            ->where('legal_entity.rfc', 'PSM2311289J2')
            ->where('organization.brand_primary_color', '#225C54')
            ->where('organization.official_logo_url', '/brand/logo.svg')
            ->where('privacy_notice', null));
});

it('rechaza colores fuera de la paleta institucional y correos de privacidad de ejemplo', function () {
    $this->actingAs($this->admin)->put('/configuracion/empresa', companyPayload(['brand_primary_color' => '#1F6FEB']))
        ->assertSessionHasErrors('brand_primary_color');
    $this->actingAs($this->admin)->put('/configuracion/empresa', companyPayload(['privacy_contact_email' => 'privacidad@empresa.mx']))
        ->assertSessionHasErrors('privacy_contact_email');
    $this->actingAs($this->admin)->put('/configuracion/empresa', companyPayload(['rfc' => 'NO-VALIDO']))
        ->assertSessionHasErrors('rfc');

    $this->actingAs($this->admin)->put('/configuracion/empresa', companyPayload(['brand_primary_color' => '#AF8B51', 'privacy_contact_email' => 'datos.personales@mrlana.com.mx']))
        ->assertSessionHasNoErrors();
    expect($this->org->fresh()->brand_primary_color)->toBe('#AF8B51');
});

it('el aviso de privacidad exige PDF para publicarse y queda versionado e inmutable', function () {
    $this->actingAs($this->admin)->post('/configuracion/documentos', [
        'code' => 'AVISO_PRIVACIDAD', 'title' => 'Aviso de privacidad para colaboradores', 'version' => '1.0',
        'body_markdown' => 'Resumen del aviso de privacidad.', 'requires_acknowledgement' => true,
    ])->assertSessionHasNoErrors();
    $doc = PolicyDocument::query()->where('code', 'AVISO_PRIVACIDAD')->sole();

    $this->actingAs($this->admin)->post("/configuracion/documentos/{$doc->id}/publicar")->assertSessionHasErrors('file');
    expect($doc->fresh()->published_at)->toBeNull();

    $this->actingAs($this->admin)->post("/configuracion/documentos/{$doc->id}/pdf", ['file' => UploadedFile::fake()->create('aviso.txt', 10, 'text/plain')])
        ->assertSessionHasErrors('file');
    $this->actingAs($this->admin)->post("/configuracion/documentos/{$doc->id}/pdf", ['file' => UploadedFile::fake()->create('aviso.pdf', 120, 'application/pdf')])
        ->assertSessionHasNoErrors();

    $doc->refresh();
    expect($doc->file_path)->not->toBeNull()->and($doc->file_sha256)->toHaveLength(64);
    Storage::disk('local')->assertExists($doc->file_path);

    $this->actingAs($this->admin)->post("/configuracion/documentos/{$doc->id}/publicar")->assertSessionHasNoErrors();
    expect($doc->fresh()->published_at)->not->toBeNull();

    // Publicado: el PDF ya no se reemplaza; se crea otra versión.
    $this->actingAs($this->admin)->post("/configuracion/documentos/{$doc->id}/pdf", ['file' => UploadedFile::fake()->create('otro.pdf', 50, 'application/pdf')])
        ->assertSessionHasErrors('document');
    $this->actingAs($this->admin)->get("/configuracion/documentos/{$doc->id}/pdf")->assertOk();

    $this->actingAs($this->admin)->get('/configuracion/documentos')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('documents.0.file_sha256', $doc->file_sha256)
            ->where('registration_codes.0.code', 'AVISO_PRIVACIDAD')
            ->where('registration_codes.0.pdf_required', true));
});

it('configura la ubicación por puesto con bitácora', function () {
    $position = Position::query()->create(['name' => 'Asesor de campo', 'code' => 'CAMPO']);
    expect($position->fresh()->location_tracking)->toBe('on_punch');

    $this->actingAs($this->admin)->put("/configuracion/ubicacion/puestos/{$position->id}", ['location_tracking' => 'during_shift'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put("/configuracion/ubicacion/puestos/{$position->id}", ['location_tracking' => 'siempre'])->assertSessionHasErrors('location_tracking');

    expect($position->fresh()->location_tracking)->toBe('during_shift');
    $log = AuditLog::query()->where('action', 'location.position_mode')->sole();
    expect($log->old_values['location_tracking'])->toBe('on_punch')->and($log->new_values['location_tracking'])->toBe('during_shift');

    $this->actingAs($this->admin)->get('/configuracion/ubicacion')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('configuracion/Location')
            ->where('positions.0.location_tracking', 'during_shift')
            ->where('settings.retention_days', 90)
            ->where('settings.retention_confirmed', false));

    $collaborator = $this->userIn($this->org, 'Colaborador');
    $this->actingAs($collaborator)->put("/configuracion/ubicacion/puestos/{$position->id}", ['location_tracking' => 'on_punch'])->assertForbidden();
});

it('muestra políticas de conservación, notificaciones e integraciones con los parámetros de rectificación', function () {
    $this->actingAs($this->admin)->get('/configuracion/sistema')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('configuracion/System')
            ->where('retention.attendance_years', 5)
            ->where('retention.location.retention_days', 90)
            ->where('rectifications.business_days', 3)
            ->has('integrations.people_configured'));
});
