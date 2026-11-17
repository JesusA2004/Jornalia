<?php

use App\Enums\ApprovalStatus;
use App\Models\Device;
use App\Notifications\RequestStatusChanged;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Concerns\ContractShapes;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

beforeEach(function () {
    $this->setUpMobile();
});

function enableFcm(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config([
        'fcm.enabled' => true,
        'fcm.project_id' => 'proyecto-prueba',
        'fcm.credentials_json' => json_encode([
            'type' => 'service_account',
            'client_email' => 'push@proyecto-prueba.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]),
    ]);
}

const FCM_TOKEN = 'fcm-token_ABCDEFGHIJKLMNOPQRSTUVWXYZ:0123456789';

it('registra el token FCM del dispositivo', function () {
    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->device->device_uuid, 'push_token' => FCM_TOKEN, 'provider' => 'fcm'])
        ->assertNoContent();

    $device = $this->device->fresh();
    expect($device->push_token)->toBe(FCM_TOKEN)
        ->and($device->push_provider)->toBe('fcm')
        ->and($device->push_token_invalidated_at)->toBeNull();

    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->device->device_uuid, 'push_token' => FCM_TOKEN, 'provider' => 'apns'])
        ->assertUnprocessable();
});

it('sin credenciales no llama a FCM y la bandeja sigue funcionando', function () {
    Http::fake();
    $this->device->forceFill(['push_token' => FCM_TOKEN, 'push_provider' => 'fcm'])->save();

    $this->user->notify(new RequestStatusChanged('vacation', 7, ApprovalStatus::Approved, null));

    Http::assertNothingSent();
    $items = $this->mobile('GET', '/notifications')->assertOk()->json('data');
    expect($items)->toHaveCount(1);
    ContractShapes::assert($items[0], 'AppNotification');
    expect($items[0]['category'])->toBe('request')
        ->and($items[0]['data'])->toBe(['subject' => 'vacation', 'subject_id' => '7', 'status' => 'approved']);
});

it('envía el push con texto neutro y data de texto', function () {
    enableFcm();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.prueba', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/proyecto-prueba/messages/1']),
    ]);
    $this->device->forceFill(['push_token' => FCM_TOKEN, 'push_provider' => 'fcm'])->save();

    $this->user->notify(new RequestStatusChanged('vacation', 7, ApprovalStatus::Rejected, 'Fechas cubiertas por otro compañero'));

    $notification = $this->user->notifications()->sole();
    Http::assertSent(function (Request $request) use ($notification) {
        if (! str_contains($request->url(), 'fcm.googleapis.com/v1/projects/proyecto-prueba/messages:send')) {
            return false;
        }
        $m = $request->data()['message'];

        return $request->hasHeader('Authorization', 'Bearer ya29.prueba')
            && $m['token'] === FCM_TOKEN
            && $m['notification'] === ['title' => 'MR LANA Asistencias', 'body' => 'Una de tus solicitudes cambió de estado.']
            && ! str_contains(json_encode($m), 'Fechas cubiertas')
            && $m['data']['notification_id'] === $notification->id
            && $m['data']['category'] === 'request'
            && $m['data']['subject_id'] === '7'
            && collect($m['data'])->every(fn ($v) => is_string($v));
    });
});

it('desactiva (sin borrar) el token cuando FCM responde UNREGISTERED', function () {
    enableFcm();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.prueba', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['error' => [
            'code' => 404, 'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
        ]], 404),
    ]);
    $this->device->forceFill(['push_token' => FCM_TOKEN, 'push_provider' => 'fcm'])->save();

    $this->user->notify(new RequestStatusChanged('incidence', 3, ApprovalStatus::Approved, null));

    $device = Device::query()->withoutGlobalScopes()->find($this->device->id);
    expect($device->push_token)->toBe(FCM_TOKEN)
        ->and($device->push_token_invalidated_at)->not->toBeNull()
        ->and($device->push_token_error)->toBe('UNREGISTERED')
        ->and($device->status->value)->toBe('approved');

    // Ya no se intenta con ese token.
    Http::fake();
    $this->user->notify(new RequestStatusChanged('incidence', 4, ApprovalStatus::Approved, null));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'));

    // Al registrarlo de nuevo, se reactiva.
    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->device->device_uuid, 'push_token' => FCM_TOKEN, 'provider' => 'fcm'])->assertNoContent();
    expect($this->device->fresh()->push_token_invalidated_at)->toBeNull();
});

it('no envía push a dispositivos revocados', function () {
    enableFcm();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.prueba', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'x']),
    ]);
    $this->device->forceFill(['push_token' => FCM_TOKEN, 'push_provider' => 'fcm', 'status' => 'revoked'])->save();

    expect($this->user->notifications()->where('data->kind', 'device.revoked')->count())->toBe(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('acepta aún el token Expo de apps anteriores sin enviarle FCM', function () {
    $this->mobile('POST', '/devices/push-token', ['device_uuid' => $this->device->device_uuid, 'expo_push_token' => 'ExponentPushToken[abc]'])
        ->assertNoContent();

    expect($this->device->fresh()->push_provider)->toBe('expo');
});
