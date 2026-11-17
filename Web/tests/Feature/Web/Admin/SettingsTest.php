<?php

use App\Domain\Biometrics\FaceVerificationProvider;
use App\Models\Holiday;
use App\Models\IncidenceType;
use App\Models\PolicyDocument;
use App\Models\VacationPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
});

it('rechaza prestaciones de vacaciones inferiores a la ley', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $base = ['name' => 'Superior', 'extra_days' => [], 'premium_percent' => '25.00', 'min_notice_days' => 0, 'allow_half_days' => false, 'valid_from' => '2026-01-01', 'is_default' => false];

    $this->actingAs($admin)->post('/configuracion/vacaciones', ['premium_percent' => '20'] + $base)->assertSessionHasErrors('premium_percent');
    $this->actingAs($admin)->post('/configuracion/vacaciones', ['extra_days' => [['from_year' => 1, 'to_year' => 5, 'days' => -2]]] + $base)->assertSessionHasErrors('extra_days');
    $this->actingAs($admin)->post('/configuracion/vacaciones', ['extra_days' => [['from_year' => 1, 'to_year' => 5, 'days' => 2], ['from_year' => 4, 'to_year' => null, 'days' => 3]]] + $base)->assertSessionHasErrors('extra_days');

    $this->actingAs($admin)->post('/configuracion/vacaciones', ['premium_percent' => '50', 'extra_days' => [['from_year' => 1, 'to_year' => 5, 'days' => 2]]] + $base)->assertSessionHasNoErrors();
    expect(VacationPolicy::query()->where('name', 'Superior')->value('premium_percent'))->toBe('50.00');
});

it('protege el catálogo de incidencias del sistema y versiona las nuevas', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $system = IncidenceType::query()->where('is_system', true)->firstOrFail();
    $payload = ['code' => 'capacitacion', 'name' => 'Capacitación externa', 'category' => 'leave', 'operational_effect' => 'paid_absence', 'requires_evidence' => true, 'requires_approval' => true, 'affects_payroll' => false, 'employee_requestable' => true, 'active' => true, 'valid_from' => '2026-11-01'];

    $this->actingAs($admin)->put("/configuracion/incidencias/{$system->id}", ['code' => $system->code] + $payload)->assertSessionHasErrors('type');
    $this->actingAs($admin)->post("/configuracion/incidencias/{$system->id}/desactivar")->assertSessionHasErrors('type');

    $this->actingAs($admin)->post('/configuracion/incidencias', $payload)->assertSessionHasNoErrors();
    $type = IncidenceType::query()->where('code', 'CAPACITACION')->firstOrFail();
    expect($type->valid_from->toDateString())->toBe('2026-11-01');

    $this->actingAs($admin)->post("/configuracion/incidencias/{$type->id}/desactivar")->assertSessionHasNoErrors();
    expect(IncidenceType::withTrashed()->find($type->id)->trashed())->toBeTrue();
});

it('los festivos oficiales son de sólo lectura', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $official = Holiday::query()->whereNull('organization_id')->where('kind', 'official')->first();

    $this->actingAs($admin)->get('/configuracion/festivos?year=2026')->assertOk();
    if ($official !== null) {
        $this->actingAs($admin)->put("/configuracion/festivos/{$official->id}", ['date' => '2026-12-26', 'name' => 'X', 'kind' => 'company', 'is_mandatory_rest' => true])->assertNotFound();
    }

    $this->actingAs($admin)->post('/configuracion/festivos', ['date' => '2026-12-24', 'name' => 'Nochebuena', 'kind' => 'company', 'is_mandatory_rest' => true])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/configuracion/festivos', ['date' => '2026-12-12', 'name' => 'Fiesta local', 'kind' => 'local', 'is_mandatory_rest' => true])->assertSessionHasErrors('work_center_id');
});

it('publica documentos inmutables con sha256 y exige nueva versión para cambios', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);
    $body = "# Sistema de registro\n\nLos marcajes se registran con fecha y hora del servidor.";

    $this->actingAs($admin)->post('/configuracion/documentos', ['code' => 'registro', 'title' => 'Sistema de registro', 'version' => '1.0', 'body_markdown' => $body, 'requires_acknowledgement' => true])
        ->assertSessionHasNoErrors();
    $doc = PolicyDocument::query()->firstOrFail();
    expect($doc->sha256)->toBe(hash('sha256', $body));

    $this->actingAs($admin)->post("/configuracion/documentos/{$doc->id}/publicar")->assertSessionHasNoErrors();
    expect($doc->fresh()->published_at)->not->toBeNull();

    $this->actingAs($admin)->put("/configuracion/documentos/{$doc->id}", ['code' => 'REGISTRO', 'title' => 'Cambiado', 'version' => '1.0', 'body_markdown' => 'otro', 'requires_acknowledgement' => true])
        ->assertSessionHasErrors('document');
    expect($doc->fresh()->body_markdown)->toBe($body);

    $this->actingAs($admin)->post("/configuracion/documentos/{$doc->id}/nueva-version", ['version' => '1.1'])->assertSessionHasNoErrors();
    expect(PolicyDocument::query()->where('code', 'REGISTRO')->count())->toBe(2);
});

it('sube el logotipo validando imagen y audita cambios de empresa', function () {
    Storage::fake('public');
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/configuracion/empresa/logotipo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
    $this->actingAs($admin)->post('/configuracion/empresa/logotipo', ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)])->assertSessionHasNoErrors();

    $path = $org->fresh()->brand_logo_path;
    expect($path)->toStartWith('brand/');
    Storage::disk('public')->assertExists($path);

    $this->actingAs($admin)->put('/configuracion/empresa', ['name' => 'Nueva Razón', 'timezone' => 'America/Monterrey', 'privacy_contact_email' => 'privacidad@example.com'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.updated', 'organization_id' => $org->id]);
});

it('no permite exigir verificación facial sin proveedor', function () {
    $org = $this->organization();
    $admin = $this->userIn($org, 'Administrador de empresa', allBranches: true);

    $this->actingAs($admin)->post('/configuracion/asistencia', [
        'name' => 'Con rostro', 'location_mode' => 'record', 'allowed_auth_methods' => ['face_verification'], 'require_strong_biometric' => false,
        'allow_offline' => true, 'offline_max_hours' => 24, 'face_verification_required' => true, 'web_punch_allowed' => false,
        'max_clock_skew_seconds' => 300, 'min_location_accuracy_meters' => 100, 'is_default' => false,
    ])->assertSessionHasErrors('face_verification_required');
})->skip(fn () => app(FaceVerificationProvider::class)->available(), 'Hay un proveedor facial configurado.');

it('niega configuración sin permiso', function () {
    $org = $this->organization();
    $user = $this->userIn($org, 'Supervisor');

    $this->actingAs($user)->get('/configuracion')->assertForbidden();
    $this->actingAs($user)->put('/configuracion/empresa', ['name' => 'X', 'timezone' => 'UTC'])->assertForbidden();
});
