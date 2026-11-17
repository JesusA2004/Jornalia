<?php

namespace App\Domain\Attendance;

use App\Models\Device;

/**
 * Firma HMAC-SHA256 de marcajes con el secreto vinculado al dispositivo.
 *
 * El secreto se entrega una sola vez al vincular el dispositivo y la app lo
 * guarda en el almacén seguro del SO protegido por autenticación biométrica o
 * credencial del dispositivo. Así, la API no confía en un booleano
 * `biometric_success`: exige una prueba criptográfica de que el secreto fue
 * desbloqueado en ese dispositivo.
 *
 * canonical = client_uuid|type|device_time|nonce|device_uuid
 */
final class DeviceSignature
{
    public static function generateSecret(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function canonical(string $clientUuid, string $type, string $deviceTime, ?string $nonce, string $deviceUuid): string
    {
        return implode('|', [$clientUuid, $type, $deviceTime, $nonce ?? '', $deviceUuid]);
    }

    public static function sign(string $secretBase64, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, (string) base64_decode($secretBase64, true));
    }

    public function verify(Device $device, string $canonical, string $signature): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }

        return hash_equals(self::sign((string) $device->secret, $canonical), $signature);
    }
}
