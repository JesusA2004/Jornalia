<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Biometrics\ChallengeStep;
use App\Domain\Biometrics\FaceVerificationProvider;
use App\Domain\Biometrics\FaceVerificationService;
use App\Domain\Security\KioskService;
use App\Enums\PunchType;
use App\Http\Middleware\AuthenticateKiosk;
use App\Http\Requests\Api\V1\FaceVerifyRequest;
use App\Http\Resources\V1\ApiFormat;
use App\Models\Kiosk;
use App\Models\Organization;
use App\Models\Punch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API del kiosco de sucursal (contrato: `Kiosk*` en packages/contracts/src/api.ts).
 * Los marcajes son append-only; no hay rutas de modificación.
 */
final class KioskController extends ApiController
{
    /** Intentos de identificación por kiosco + número de empleado por minuto (además del bloqueo de NIP). */
    private const ATTEMPTS_PER_MINUTE = 10;

    public function __construct(private readonly KioskService $kiosks) {}

    /**
     * POST /kiosk/activate → KioskActivation.
     */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'activation_code' => ['required', 'string', 'max:20'],
            'device' => ['required', 'array'],
            'device.device_uuid' => ['required', 'uuid'],
            'device.platform' => ['required', 'in:android,ios,web'],
            'device.os_version' => ['nullable', 'string', 'max:40'],
            'device.app_version' => ['required', 'string', 'max:40'],
            'device.model' => ['nullable', 'string', 'max:120'],
        ]);
        $d = (array) $data['device'];

        $result = $this->kiosks->activate((string) $data['activation_code'], [
            'device_uuid' => (string) $d['device_uuid'],
            'platform' => (string) $d['platform'],
            'os_version' => $d['os_version'] ?? null,
            'app_version' => (string) $d['app_version'],
            'model' => $d['model'] ?? null,
        ]);

        return $this->data(['token' => $result['token'], 'kiosk' => $this->present($result['kiosk'])], 201);
    }

    /**
     * GET /kiosk/status → KioskStatus.
     */
    public function status(Request $request): JsonResponse
    {
        $kiosk = AuthenticateKiosk::kiosk($request);
        $this->kiosks->touch($kiosk);

        return $this->data($this->present($kiosk));
    }

    /**
     * POST /kiosk/punches → KioskPunchReceipt (201; 200 si duplicado idempotente).
     */
    public function punch(Request $request): JsonResponse
    {
        $kiosk = AuthenticateKiosk::kiosk($request);
        $data = Validator::make($request->all(), self::rules())->validate();
        $this->throttle($kiosk, (string) $data['employee_number']);

        try {
            $result = $this->kiosks->punch($kiosk, self::punchData($data, offline: false), $request->ip(), $request->userAgent());
        } catch (AttendanceException $e) {
            if ($e->errorCode !== KioskService::FACE_REQUIRED) {
                throw $e;
            }

            // Código propio + `legacy_code` para apps que aún esperan `policy_violation`.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'legacy_code' => 'policy_violation'], $e->status);
        }

        return $this->data($this->receipt($result['punch'], $result['employee']->fullName(), $result['duplicate']), $result['duplicate'] ? 200 : 201);
    }

    /**
     * POST /kiosk/sync → KioskSyncResponse. Cola offline: cada elemento por separado.
     * Sin conexión no hay verificación facial: si la política la exige, el elemento se rechaza.
     */
    public function sync(Request $request): JsonResponse
    {
        $kiosk = AuthenticateKiosk::kiosk($request);
        $request->validate(['punches' => ['required', 'array', 'min:1', 'max:200']]);

        $results = [];
        foreach ((array) $request->input('punches') as $item) {
            $item = (array) $item;
            $clientUuid = is_string($item['client_uuid'] ?? null) ? $item['client_uuid'] : '';
            try {
                $data = Validator::make($item, self::rules())->validate();
                $this->throttle($kiosk, (string) $data['employee_number']);
                $result = $this->kiosks->punch($kiosk, self::punchData($data, offline: true), $request->ip(), $request->userAgent());
                $results[] = [
                    'client_uuid' => $clientUuid,
                    'result' => $result['duplicate'] ? 'duplicate' : 'created',
                    'punch' => $this->receipt($result['punch'], $result['employee']->fullName(), $result['duplicate']),
                ];
            } catch (AttendanceException $e) {
                $error = ['message' => $e->getMessage(), 'code' => $e->errorCode];
                if ($e->errorCode === KioskService::FACE_REQUIRED) {
                    $error['legacy_code'] = 'policy_violation';
                }
                $results[] = ['client_uuid' => $clientUuid, 'result' => 'rejected', 'error' => $error];
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $clientUuid, 'result' => 'rejected', 'error' => ['message' => $e->getMessage(), 'code' => 'validation_failed', 'errors' => $e->errors()]];
            }
        }

        return $this->data(['results' => $results]);
    }

    /**
     * POST /kiosk/face/challenge {employee_number, pin} → FaceChallenge.
     * Reutiliza el microservicio facial cuando la política exige verificación facial.
     */
    public function faceChallenge(Request $request, FaceVerificationProvider $provider, FaceVerificationService $faces): JsonResponse
    {
        $kiosk = AuthenticateKiosk::kiosk($request);
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:40'], 'pin' => ['required', 'string', 'max:12']]);
        $this->throttle($kiosk, (string) $data['employee_number']);
        if (! $provider->available()) {
            throw new AttendanceException('face_unavailable', 'La verificación facial no está disponible en este momento.', 503);
        }
        $employee = $this->kiosks->identify($kiosk, (string) $data['employee_number'], (string) $data['pin']);
        $challenge = $faces->issueChallenge($employee);

        return $this->data([
            'id' => $challenge->uuid,
            'steps' => array_map(fn (string $code) => ['code' => $code, 'label' => ChallengeStep::from($code)->label()], $challenge->steps),
            'expires_at' => ApiFormat::dateTime($challenge->expires_at),
        ], 201);
    }

    /**
     * POST /kiosk/face/verify (multipart: employee_number, pin, challenge_id, frames[], timestamps[]) → FaceVerificationResult.
     */
    public function faceVerify(FaceVerifyRequest $request, FaceVerificationService $faces): JsonResponse
    {
        $kiosk = AuthenticateKiosk::kiosk($request);
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:40'], 'pin' => ['required', 'string', 'max:12']]);
        $this->throttle($kiosk, (string) $data['employee_number']);
        $employee = $this->kiosks->identify($kiosk, (string) $data['employee_number'], (string) $data['pin']);

        $verification = $faces->verify($employee, (string) $request->input('challenge_id'), $request->jpegFrames(), $request->offsets(), $request->boolean('mirrored'));

        return $this->data([
            'id' => $verification->uuid,
            'result' => $verification->result,
            'expires_at' => ApiFormat::dateTime($verification->expires_at),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'max:12'],
            'type' => ['required', Rule::enum(PunchType::class)],
            'client_uuid' => ['required', 'uuid'],
            'device_time' => ['required', 'string', 'max:40', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'face_verification_id' => ['nullable', 'uuid'],
            'location' => ['nullable', 'array'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'location.captured_at' => ['nullable', 'date'],
            'location.is_mocked' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Datos validados del marcaje de kiosco con la forma que espera el dominio.
     *
     * @param  array<string, mixed>  $data  salida de `rules()` ya validada
     * @return array{employee_number:string, pin:string, type:string, client_uuid:string, device_time:string, timezone:string|null, captured_offline:bool, face_verification_id:string|null, location:array<string, mixed>|null}
     */
    private static function punchData(array $data, bool $offline): array
    {
        return [
            'employee_number' => (string) $data['employee_number'],
            'pin' => (string) $data['pin'],
            'type' => (string) $data['type'],
            'client_uuid' => (string) $data['client_uuid'],
            'device_time' => (string) $data['device_time'],
            'timezone' => isset($data['timezone']) ? (string) $data['timezone'] : null,
            'captured_offline' => $offline,
            'face_verification_id' => isset($data['face_verification_id']) ? (string) $data['face_verification_id'] : null,
            'location' => isset($data['location']) && is_array($data['location']) ? $data['location'] : null,
        ];
    }

    private function throttle(Kiosk $kiosk, string $employeeNumber): void
    {
        $key = 'kiosk:'.$kiosk->id.':'.mb_strtolower($employeeNumber);
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            throw new AttendanceException('rate_limited', 'Demasiados intentos. Espera un momento.', 429);
        }
        RateLimiter::hit($key, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Kiosk $kiosk): array
    {
        $kiosk->loadMissing('branch:id,name,code');
        $organization = Organization::query()->find($kiosk->organization_id);

        return [
            'id' => $kiosk->uuid,
            'name' => $kiosk->name,
            'status' => $kiosk->status,
            'branch' => $kiosk->branch ? ['id' => $kiosk->branch->id, 'name' => $kiosk->branch->name] : null,
            'organization' => ['id' => $kiosk->organization_id, 'name' => $organization?->name, 'timezone' => $organization?->timezone],
            'activated_at' => ApiFormat::dateTime($kiosk->activated_at),
            'last_seen_at' => ApiFormat::dateTime($kiosk->last_seen_at),
            'server_time' => ApiFormat::dateTime(now()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(Punch $punch, string $employeeName, bool $duplicate): array
    {
        return [
            'id' => $punch->uuid,
            'client_uuid' => $punch->client_uuid,
            'type' => $punch->type->value,
            'server_time' => ApiFormat::dateTime($punch->server_time),
            'status' => $punch->status->value,
            'captured_offline' => (bool) $punch->captured_offline,
            'employee_name' => $employeeName,
            'duplicate' => $duplicate,
        ];
    }
}
