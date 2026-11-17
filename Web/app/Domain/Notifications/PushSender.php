<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\Fcm\FcmResult;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Envía un push a los dispositivos autorizados de un usuario con token FCM
 * activo. Un token muerto (UNREGISTERED) se DESACTIVA (no se borra).
 */
final class PushSender
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function toUser(User $user, ?int $organizationId, PushMessage $message): PushOutcome
    {
        if (! $this->fcm->enabled()) {
            return new PushOutcome(PushOutcome::DISABLED, 0, 'sin_configuracion');
        }

        $devices = Device::query()->withoutGlobalScope('organization')
            ->where('user_id', $user->id)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', DeviceStatus::Approved)
            ->where('push_provider', 'fcm')
            ->whereNotNull('push_token')
            ->whereNull('push_token_invalidated_at')
            ->get(['id', 'push_token']);

        if ($devices->isEmpty()) {
            return new PushOutcome(PushOutcome::NO_DEVICE);
        }

        $sent = 0;
        $transient = null;
        $permanent = null;
        foreach ($devices as $device) {
            $result = $this->fcm->send((string) $device->push_token, $message->title, $message->body, $message->data, $message->highPriority);

            match ($result->status) {
                FcmResult::SENT => $sent++,
                FcmResult::DEAD_TOKEN => $this->invalidate($device, (string) $result->code),
                FcmResult::TRANSIENT => $transient = $result->code,
                default => $permanent = $result->code,
            };
        }

        if ($sent > 0) {
            return new PushOutcome(PushOutcome::SENT, $sent);
        }
        if ($transient !== null) {
            return new PushOutcome(PushOutcome::TRANSIENT, 0, $transient);
        }
        if ($permanent !== null) {
            Log::warning('fcm_push_rejected', ['user_id' => $user->id, 'code' => $permanent]);

            return new PushOutcome(PushOutcome::FAILED, 0, $permanent);
        }

        return new PushOutcome(PushOutcome::NO_DEVICE, 0, 'token_desactivado');
    }

    private function invalidate(Device $device, string $code): void
    {
        Device::query()->withoutGlobalScope('organization')->whereKey($device->id)->update([
            'push_token_invalidated_at' => now(),
            'push_token_error' => mb_substr($code, 0, 40),
        ]);
    }
}
