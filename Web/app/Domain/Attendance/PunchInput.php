<?php

namespace App\Domain\Attendance;

use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use Carbon\CarbonImmutable;

/**
 * Datos de entrada de un marcaje (ya validados sintácticamente por la capa HTTP).
 */
final readonly class PunchInput
{
    public function __construct(
        public string $clientUuid,
        public PunchType $type,
        public PunchSource $source,
        public AuthMethod $authMethod,
        public ?CarbonImmutable $deviceTime,
        public ?string $deviceTimeRaw,
        public string $timezone,
        public ?string $deviceUuid,
        public ?string $nonce,
        public ?string $signature,
        public bool $capturedOffline,
        public ?float $latitude,
        public ?float $longitude,
        public ?int $accuracyMeters,
        public ?CarbonImmutable $locationCapturedAt,
        public ?bool $locationMocked,
        public ?string $faceVerificationId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?int $kioskId = null,
        /** Hora de envío/sincronización según el reloj del dispositivo (opcional). */
        public ?CarbonImmutable $syncedAt = null,
    ) {}

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
