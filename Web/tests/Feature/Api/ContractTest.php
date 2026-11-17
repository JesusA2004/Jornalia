<?php

use App\Models\IncidenceType;
use App\Models\PolicyDocument;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\ContractShapes;
use Tests\Feature\Api\Concerns\MobileApi;

/*
| Valida claves y tipos de las respuestas principales contra
| packages/contracts/src/api.ts (espejo en ContractShapes).
*/

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

it('cumple el contrato en autenticación, perfil y dispositivos', function () {
    PolicyDocument::query()->create([
        'code' => 'AVISO', 'title' => 'Aviso de privacidad', 'version' => '1.0', 'body_markdown' => '# Aviso',
        'sha256' => hash('sha256', '# Aviso'), 'requires_acknowledgement' => true, 'published_at' => now()->subDay(),
    ]);

    $this->app['auth']->forgetGuards();
    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email, 'password' => 'password',
        'device' => ['device_uuid' => (string) Str::uuid(), 'name' => 'iPhone', 'platform' => 'ios', 'os_version' => '19', 'app_version' => '1.0.0', 'model' => null, 'biometric_available' => true],
    ])->assertOk();
    ContractShapes::assert($login->json('data'), 'LoginResponse');
    // Documento de prueba + aviso de ubicación publicado al crear la organización.
    expect(collect($login->json('data.profile.pending_acknowledgements'))->pluck('code')->all())->toContain('UBICACION')->toHaveCount(2);

    // Iniciar sesión cierra cualquier otra sesión móvil: se usa el token recién emitido.
    $token = $login->json('data.token');
    ContractShapes::assert($this->mobile('GET', '/profile', [], $token)->assertOk()->json('data'), 'Profile');
    ContractShapes::assert($this->mobile('POST', '/auth/refresh', [], $token)->assertOk()->json('data'), 'RefreshResponse');
});

it('cumple el contrato en marcajes, jornada, historial y sincronización', function () {
    $nonce = $this->mobile('POST', '/attendance/challenge')->assertOk()->json('data');
    ContractShapes::assert($nonce, 'ChallengeResponse');

    $payload = $this->signedPunch('clock_in', $nonce['nonce'], [
        'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_meters' => 12.5, 'captured_at' => '2026-11-17T15:59:58.000Z', 'is_mocked' => false],
    ]);
    $receipt = $this->mobile('POST', '/attendance/punches', $payload)->assertCreated()->json('data');
    ContractShapes::assert($receipt, 'PunchReceipt');
    expect($receipt['punch']['location']['accuracy_meters'])->toBe(13);

    ContractShapes::assert($this->mobile('GET', '/attendance/punches/'.$receipt['punch']['uuid'])->assertOk()->json('data'), 'PunchReceipt');

    $today = $this->mobile('GET', '/attendance/today')->assertOk()->json('data');
    ContractShapes::assert($today, 'TodayResponse');
    expect($today['workday']['status'])->toBe('working')
        ->and($today['week']['week_start'])->toBe('2026-11-16')
        ->and($today['week']['days'])->toHaveCount(7)
        ->and($today['week']['legal_max_minutes'])->toBeGreaterThan(0)
        ->and($today['workday']['shift']['starts_at'])->toBe('2026-11-17T15:00:00Z');

    ContractShapes::assert($this->mobile('GET', '/attendance/policy')->assertOk()->json('data'), 'AttendancePolicy');

    $history = $this->mobile('GET', '/attendance/history?from=2026-11-01&to=2026-11-17&per_page=10')->assertOk();
    ContractShapes::check($history->json('data'), ['list', ContractShapes::shapes()['HistoryDay']], 'HistoryDay[]');
    ContractShapes::assert($history->json('meta'), 'PaginationMeta');
    expect($history->json('meta'))->toBe(['current_page' => 1, 'per_page' => 10, 'total' => 17, 'last_page' => 2])
        ->and($history->json('data.0.date'))->toBe('2026-11-17')
        ->and($history->json('data.0.punches'))->toHaveCount(1);

    $sync = $this->mobile('POST', '/sync/punches', ['punches' => [
        $this->signedPunch('clock_out', null),
        ['client_uuid' => 'no-es-uuid'],
    ]])->assertOk()->json('data');
    expect(array_keys($sync))->toBe(['results']);
    foreach ($sync['results'] as $i => $item) {
        ContractShapes::check($item, ContractShapes::shapes()['SyncItem'], "SyncItem[{$i}]");
    }
});

it('cumple el contrato en solicitudes, calendario, notificaciones y verificación facial', function () {
    $rect = $this->mobile('POST', '/rectifications', [
        'date' => '2026-11-16', 'punch_uuid' => null, 'kind' => 'missing_punch', 'proposed_type' => 'clock_in',
        'proposed_time' => '2026-11-16T15:00:00Z', 'reason' => 'Olvidé marcar la entrada',
    ])->assertCreated()->json('data');
    ContractShapes::assert($rect, 'Rectification');

    $list = $this->mobile('GET', '/rectifications')->assertOk();
    ContractShapes::check($list->json('data'), ['list', ContractShapes::shapes()['Rectification']], 'Rectification[]');
    ContractShapes::assert($list->json('meta'), 'PaginationMeta');

    $types = $this->mobile('GET', '/incidence-types')->assertOk()->json('data');
    ContractShapes::check($types, ['list', ContractShapes::shapes()['IncidenceType']], 'IncidenceType[]');

    $type = IncidenceType::query()->where('code', 'PERMISO_CON_GOCE')->firstOrFail();
    $incidence = $this->mobile('POST', '/incidences', [
        'incidence_type_id' => $type->id, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'reason' => 'Trámite personal',
    ])->assertCreated()->json('data');
    ContractShapes::assert($incidence, 'Incidence');
    ContractShapes::check($this->mobile('GET', '/incidences')->assertOk()->json('data'), ['list', ContractShapes::shapes()['Incidence']], 'Incidence[]');

    ContractShapes::assert($this->mobile('GET', '/vacations/balance')->assertOk()->json('data'), 'VacationBalance');
    $vacation = $this->mobile('POST', '/vacations', ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-02', 'comment' => null])->assertCreated()->json('data');
    ContractShapes::assert($vacation, 'VacationRequest');
    ContractShapes::assert($this->mobile('POST', "/vacations/{$vacation['id']}/cancel")->assertOk()->json('data'), 'VacationRequest');
    ContractShapes::check($this->mobile('GET', '/vacations')->assertOk()->json('data'), ['list', ContractShapes::shapes()['VacationRequest']], 'VacationRequest[]');

    $calendar = $this->mobile('GET', '/calendar?month=2026-11')->assertOk()->json('data');
    ContractShapes::assert($calendar, 'CalendarResponse');
    expect(collect($calendar['entries'])->pluck('kind')->unique()->values()->all())->toContain('shift', 'rest_day', 'holiday', 'leave');

    $this->user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test',
        'data' => ['kind' => 'request.approved', 'title' => 'Solicitud aprobada', 'body' => 'Consulta el detalle.'],
    ]);
    $notifications = $this->mobile('GET', '/notifications')->assertOk();
    ContractShapes::check($notifications->json('data'), ['list', ContractShapes::shapes()['AppNotification']], 'AppNotification[]');
    ContractShapes::assert($notifications->json('meta'), 'PaginationMeta');

    ContractShapes::check($this->mobile('GET', '/devices')->assertOk()->json('data'), ['list', ContractShapes::shapes()['Device']], 'Device[]');
    ContractShapes::assert($this->mobile('GET', '/face-verification/status')->assertOk()->json('data'), 'FaceVerificationStatus');
    ContractShapes::assert($this->getJson('/api/v1/health')->assertOk()->json('data'), 'Health');
});

it('cumple el contrato de ApiError', function () {
    ContractShapes::assert($this->mobile('GET', '/attendance/punches/'.Str::uuid())->assertNotFound()->json(), 'ApiError');
    ContractShapes::assert($this->mobile('POST', '/rectifications', [])->assertStatus(422)->json(), 'ApiError');
    $this->app['auth']->forgetGuards();
    ContractShapes::assert($this->withoutToken()->getJson('/api/v1/profile')->assertUnauthorized()->json(), 'ApiError');
});
