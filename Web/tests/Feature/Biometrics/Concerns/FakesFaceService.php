<?php

namespace Tests\Feature\Biometrics\Concerns;

use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Microservicio facial simulado con Http::fake según su contrato
 * (services/face/app/main.py). Nunca se usan fotos reales: los "cuadros" son
 * JPEG sintéticos generados con GD.
 */
trait FakesFaceService
{
    protected const SERVICE = 'http://face.test';

    protected const SERVICE_TOKEN = 'token-servicio-prueba';

    protected function configureFaceService(): void
    {
        config([
            'biometrics.service.url' => self::SERVICE,
            'biometrics.service.token' => self::SERVICE_TOKEN,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $verify
     * @param  array<string, mixed>|null  $enroll
     */
    protected function fakeFaceService(?array $verify = null, ?array $enroll = null, int $enrollStatus = 200): void
    {
        $this->refakeHttp([
            self::SERVICE.'/health' => Http::response(['status' => 'ok', 'provider' => 'opencv-open-source', 'version' => '1.0.0']),
            self::SERVICE.'/verify' => Http::response($verify ?? ['result' => 'match', 'score' => 0.71, 'liveness_score' => 0.97, 'challenge_passed' => true]),
            self::SERVICE.'/enroll' => Http::response($enroll ?? ['template' => self::fakeTemplate(), 'quality' => ['frames' => 3, 'liveness_score' => 0.98]], $enrollStatus),
        ]);
    }

    /**
     * Reemplaza (no acumula) las respuestas simuladas.
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function refakeHttp(array $stubs): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake($stubs);
    }

    protected static function fakeTemplate(): string
    {
        return base64_encode(pack('g*', ...array_fill(0, 128, 0.0883883)));
    }

    /**
     * @return list<UploadedFile>
     */
    public function jpegFrames(int $count): array
    {
        return array_map(fn (int $i) => UploadedFile::fake()->image("cuadro{$i}.jpg", 64, 64), range(1, $count));
    }

    protected function enrollDirectly(Employee $employee, User $by): FaceEnrollment
    {
        return FaceEnrollment::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'provider' => 'opencv-open-source',
            'external_subject_id' => (string) Str::uuid(),
            'template' => self::fakeTemplate(),
            'template_version' => 'sface-2021dec',
            'consent_at' => now(),
            'identity_verified_by' => $by->id,
            'status' => 'active',
        ]);
    }

    /**
     * POST multipart /face-verification/verify como la app (requiere MobileApi).
     *
     * @param  list<int>|null  $timestamps
     * @param  array<string, mixed>  $extra
     */
    protected function faceVerify(string $challengeId, int $frames = 6, ?array $timestamps = null, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->post('/api/v1/face-verification/verify', array_merge([
            'challenge_id' => $challengeId,
            'frames' => $this->jpegFrames($frames),
            'timestamps' => $timestamps ?? array_map(fn ($i) => 400 + $i * 450, range(0, $frames - 1)),
            'mirrored' => '1',
        ], $extra), ['Accept' => 'application/json']);
    }
}
