<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Domain\Attendance\AttendanceException;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resuelve el colaborador y el dispositivo de la petición autenticada.
 * Todas las consultas pasan por el scope de organización activa.
 */
final class ApiContext
{
    public const TOKEN_PREFIX = 'device:';

    public function employeeOrNull(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    public function employee(User $user): Employee
    {
        return $this->employeeOrNull($user) ?? throw AttendanceException::noEmployment();
    }

    /**
     * UUID del dispositivo ligado al token Sanctum actual (`device:{uuid}`).
     */
    public static function tokenDeviceUuid(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! str_starts_with((string) $token->name, self::TOKEN_PREFIX)) {
            return null;
        }

        return substr((string) $token->name, strlen(self::TOKEN_PREFIX));
    }

    public function deviceByUuid(User $user, ?string $uuid): ?Device
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return Device::query()->where('user_id', $user->id)->where('device_uuid', $uuid)->first();
    }

    /**
     * Dispositivo actual autorizado. Lanza el error de contrato correspondiente.
     */
    public function approvedCurrentDevice(Request $request): Device
    {
        /** @var User $user */
        $user = $request->user();
        $device = $this->deviceByUuid($user, self::tokenDeviceUuid($request)) ?? throw AttendanceException::deviceUnknown();

        return match ($device->status) {
            DeviceStatus::Pending => throw AttendanceException::devicePending(),
            DeviceStatus::Revoked => throw AttendanceException::deviceRevoked(),
            DeviceStatus::Approved => $device,
        };
    }
}
