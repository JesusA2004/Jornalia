<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Domain\Attendance\PunchInput;
use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas y transformación de `PunchRequest` (contrato) a `PunchInput` (dominio).
 * Compartido por `POST /attendance/punches` y por cada elemento de `/sync/punches`.
 */
final class PunchPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', Rule::enum(PunchType::class)],
            'device_time' => ['required', 'string', 'max:40', 'date'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'device_uuid' => ['required', 'uuid'],
            'auth_method' => ['required', Rule::in([AuthMethod::DeviceBiometric->value, AuthMethod::DeviceCredential->value, AuthMethod::FaceVerification->value])],
            'nonce' => ['nullable', 'string', 'max:64'],
            'signature' => ['required', 'string', 'max:128'],
            'captured_offline' => ['required', 'boolean'],
            'location' => ['nullable', 'array'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'location.captured_at' => ['required_with:location', 'date'],
            'location.is_mocked' => ['nullable', 'boolean'],
            'face_verification_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Regla adicional: en línea el nonce es obligatorio.
     *
     * @param  array<string, mixed>  $data
     */
    public static function assertNonce(array $data): void
    {
        $offline = filter_var($data['captured_offline'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $offline && blank($data['nonce'] ?? null)) {
            throw ValidationException::withMessages(['nonce' => 'El desafío (nonce) es obligatorio para marcajes en línea.']);
        }
    }

    /**
     * Valida un elemento suelto (lotes de sincronización).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function validate(array $item): array
    {
        $data = Validator::make($item, self::rules())->validate();
        self::assertNonce($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  datos validados
     */
    public static function toInput(array $data, Request $request): PunchInput
    {
        $location = $data['location'] ?? null;
        $raw = (string) $data['device_time'];

        return new PunchInput(
            // Sin normalizar: la firma se calcula sobre los valores exactos enviados.
            clientUuid: (string) $data['client_uuid'],
            type: PunchType::from($data['type']),
            source: PunchSource::Mobile,
            authMethod: AuthMethod::from($data['auth_method']),
            deviceTime: CarbonImmutable::parse($raw)->utc(),
            deviceTimeRaw: $raw,
            timezone: (string) $data['timezone'],
            deviceUuid: (string) $data['device_uuid'],
            nonce: $data['nonce'] ?? null,
            signature: strtolower((string) $data['signature']),
            capturedOffline: (bool) filter_var($data['captured_offline'], FILTER_VALIDATE_BOOLEAN),
            latitude: is_array($location) ? (float) $location['latitude'] : null,
            longitude: is_array($location) ? (float) $location['longitude'] : null,
            accuracyMeters: is_array($location) && isset($location['accuracy_meters']) ? (int) round((float) $location['accuracy_meters']) : null,
            locationCapturedAt: is_array($location) ? CarbonImmutable::parse($location['captured_at'])->utc() : null,
            locationMocked: is_array($location) && array_key_exists('is_mocked', $location) && $location['is_mocked'] !== null ? (bool) $location['is_mocked'] : null,
            faceVerificationId: $data['face_verification_id'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
