<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\DeviceIntegrityMonitor;
use App\Domain\Security\DeviceService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Controllers\Api\V1\Support\OrganizationResolver;
use App\Http\Controllers\Api\V1\Support\ProfilePresenter;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\ApiFormat;
use App\Http\Resources\V1\DeviceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticación de la app móvil con tokens Sanctum ligados al dispositivo.
 */
final class AuthController extends ApiController
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly OrganizationResolver $organizations,
        private readonly ProfilePresenter $profiles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /auth/login → LoginResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->lower()->toString())->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
            $this->audit->log('auth.login_failed', null, 'Intento de inicio de sesión fallido (app móvil)', ['channel' => 'mobile'], $user?->current_organization_id);

            // Mensaje genérico: no revela si el correo existe.
            throw ValidationException::withMessages(['email' => 'Las credenciales proporcionadas no son válidas.']);
        }

        if (! $user->isActive()) {
            abort(403, 'Tu cuenta está desactivada.');
        }

        $organization = $this->organizations->resolveAndActivate($user);
        if ($organization === null) {
            abort(403, 'Tu usuario no está vinculado a ninguna organización activa.');
        }

        Auth::setUser($user);
        $info = $request->deviceInfo();
        $this->devices->assertHardwareAvailable($user, $organization->id, $info);

        [$registration, $token] = DB::transaction(function () use ($user, $organization, $info) {
            $registration = $this->devices->register($user, $organization->id, $info);

            // UNA sola sesión móvil por colaborador: iniciar sesión cierra cualquier otra.
            $user->tokens()->where('name', 'like', ApiContext::TOKEN_PREFIX.'%')->delete();
            $token = $this->issueToken($user, $info['device_uuid'], $organization->timezone);

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            return [$registration, $token];
        });

        $device = $registration['device'];
        // Señales de integridad (root/jailbreak/emulador) reportadas por la app ⇒ alerta en Cumplimiento.
        app(DeviceIntegrityMonitor::class)->inspectDevice($device, $request->integritySignals());
        $this->audit->log('auth.login', $device, 'Inicio de sesión en la app móvil', [
            'channel' => 'mobile',
            'device_status' => $device->status->value,
            'device_secret_issued' => $registration['secret'] !== null,
        ], $organization->id);

        return $this->data([
            'token' => $token->plainTextToken,
            'token_expires_at' => ApiFormat::dateTime($token->accessToken->expires_at),
            'device_secret' => $registration['secret'],
            'device' => (new DeviceResource($device))->current($info['device_uuid'])->resolve($request),
            'profile' => $this->profiles->present($user, $organization),
        ]);
    }

    /**
     * POST /auth/refresh → RefreshResponse (rota el token actual).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();
        $deviceUuid = ApiContext::tokenDeviceUuid($request);

        if (! $current instanceof PersonalAccessToken || $deviceUuid === null) {
            abort(403, 'Este token no está ligado a un dispositivo.');
        }

        $token = DB::transaction(function () use ($user, $current, $deviceUuid) {
            $current->delete();

            return $this->issueToken($user, $deviceUuid);
        });

        $this->audit->log('auth.refresh', null, 'Renovación de token de la app móvil', ['channel' => 'mobile']);

        return $this->data([
            'token' => $token->plainTextToken,
            'token_expires_at' => ApiFormat::dateTime($token->accessToken->expires_at),
        ]);
    }

    /**
     * POST /auth/logout → 204
     */
    public function logout(Request $request): Response
    {
        $current = $this->user($request)->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }
        $this->audit->log('auth.logout', null, 'Cierre de sesión en la app móvil', ['channel' => 'mobile']);

        return response()->noContent();
    }

    /**
     * La sesión móvil vence al terminar el día (zona de la organización): el
     * colaborador inicia sesión una vez al día. Renovar no la extiende más allá.
     */
    private function issueToken(User $user, string $deviceUuid, ?string $timezone = null): NewAccessToken
    {
        $timezone ??= $user->currentOrganization->timezone ?? config('app.timezone');
        $endOfDay = now($timezone)->endOfDay()->utc();
        $ttl = now()->addMinutes((int) config('integrity.mobile_token_ttl'));

        return $user->createToken(
            ApiContext::TOKEN_PREFIX.$deviceUuid,
            ['mobile'],
            $ttl->lt($endOfDay) ? $ttl : $endOfDay,
        );
    }
}
