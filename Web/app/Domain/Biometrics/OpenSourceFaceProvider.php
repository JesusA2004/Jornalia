<?php

namespace App\Domain\Biometrics;

use App\Models\FaceChallenge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cliente HTTP del microservicio interno `services/face` (FastAPI, 127.0.0.1).
 * Modelos: YuNet (MIT), SFace (Apache-2.0), MiniFASNet (Apache-2.0).
 */
final class OpenSourceFaceProvider implements FaceVerificationProvider
{
    public const NAME = 'opencv-open-source';

    public const TEMPLATE_VERSION = 'sface-2021dec';

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 2,
        private readonly int $healthCacheSeconds = 30,
    ) {}

    public function available(): bool
    {
        return (bool) Cache::remember('biometrics:face-service:health', $this->healthCacheSeconds, function (): bool {
            try {
                $response = $this->http()->timeout(3)->get('/health');

                return $response->ok() && $response->json('status') === 'ok' && $response->json('provider') === self::NAME;
            } catch (Throwable) {
                return false;
            }
        });
    }

    public function name(): ?string
    {
        return self::NAME;
    }

    public function templateVersion(): ?string
    {
        return self::TEMPLATE_VERSION;
    }

    public function challengeSteps(): array
    {
        return ChallengeStep::cases();
    }

    public function enroll(array $jpegFrames): EnrollmentOutcome
    {
        $response = $this->post('/enroll', ['frames' => array_map(base64_encode(...), $jpegFrames)]);

        if ($response->ok() && is_string($response->json('template'))) {
            return new EnrollmentOutcome(true, 'enrolled', $response->json('template'), (array) $response->json('quality', []));
        }
        if ($response->status() === 422) {
            $result = (string) ($response->json('result') ?? 'invalid_image');

            return new EnrollmentOutcome(false, $result, reasons: array_values(array_map('strval', (array) $response->json('reasons', []))));
        }

        throw new FaceServiceException('Respuesta inesperada del motor facial ('.$response->status().').');
    }

    public function verify(string $template, array $jpegFrames, array $offsetsMs, FaceChallenge $challenge, bool $mirrored): VerificationOutcome
    {
        $issued = (int) $challenge->created_at->getTimestampMs();
        $response = $this->post('/verify', [
            'template' => $template,
            'frames' => array_map(base64_encode(...), $jpegFrames),
            'timestamps_ms' => array_map(fn (int $o) => $issued + $o, $offsetsMs),
            'challenge' => [
                'steps' => array_values($challenge->steps),
                'issued_at_ms' => $issued,
                'expires_at_ms' => (int) $challenge->expires_at->getTimestampMs(),
            ],
            'mirrored' => $mirrored,
        ]);

        if ($response->ok() && in_array($response->json('result'), VerificationOutcome::RESULTS, true)) {
            return new VerificationOutcome(
                result: (string) $response->json('result'),
                score: is_numeric($response->json('score')) ? (float) $response->json('score') : null,
                livenessScore: is_numeric($response->json('liveness_score')) ? (float) $response->json('liveness_score') : null,
                challengePassed: (bool) $response->json('challenge_passed'),
                reason: is_string($response->json('reason')) ? $response->json('reason') : null,
            );
        }
        if ($response->status() === 422) {
            return new VerificationOutcome('poor_quality', reason: 'invalid_image');
        }

        throw new FaceServiceException('Respuesta inesperada del motor facial ('.$response->status().').');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): Response
    {
        try {
            return $this->http()->post($path, $payload);
        } catch (ConnectionException $e) {
            Cache::forget('biometrics:face-service:health');

            throw new FaceServiceException('El motor facial no responde.', previous: $e);
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/'))
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }
}
