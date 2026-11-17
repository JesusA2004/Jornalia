<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceException;
use App\Enums\DeviceStatus;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /devices/push-token — registro del token push del dispositivo.
 *
 * Contrato `PushTokenRequest`: `{device_uuid, push_token, provider: 'fcm'}`.
 * Compatibilidad: se acepta aún `{device_uuid, expo_push_token}` (apps previas);
 * ese token se guarda como proveedor `expo` y no recibe push FCM.
 */
final class PushTokenController extends ApiController
{
    public function __construct(private readonly ApiContext $context) {}

    public function __invoke(Request $request): Response
    {
        $legacy = $request->has('expo_push_token') && ! $request->has('push_token');

        $data = $request->validate($legacy
            ? [
                'device_uuid' => ['required', 'uuid'],
                'expo_push_token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[^\]]+\]$/'],
            ]
            : [
                'device_uuid' => ['required', 'uuid'],
                'push_token' => ['required', 'string', 'min:20', 'max:512', 'regex:/^[A-Za-z0-9_:\-\.]+$/'],
                'provider' => ['required', 'in:fcm'],
            ]);

        $device = $this->context->deviceByUuid($this->user($request), (string) $data['device_uuid']);
        abort_if($device === null, 404);

        if ($device->status === DeviceStatus::Revoked) {
            throw AttendanceException::deviceRevoked();
        }

        $token = $legacy ? (string) $data['expo_push_token'] : (string) $data['push_token'];
        $provider = $legacy ? 'expo' : 'fcm';

        // El mismo token no debe quedar activo en otro registro del usuario (reinstalación).
        Device::query()->withoutGlobalScope('organization')
            ->where('user_id', $device->user_id)
            ->whereKeyNot($device->id)
            ->where('push_token', $token)
            ->whereNull('push_token_invalidated_at')
            ->update(['push_token_invalidated_at' => now(), 'push_token_error' => 'reemplazado']);

        $device->forceFill([
            'push_token' => $token,
            'push_provider' => $provider,
            'push_token_updated_at' => now(),
            'push_token_invalidated_at' => null,
            'push_token_error' => null,
        ] + ($legacy ? ['expo_push_token' => $token] : []))->save();

        return response()->noContent();
    }
}
