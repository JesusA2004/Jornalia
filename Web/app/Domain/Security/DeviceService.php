<?php

namespace App\Domain\Security;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\DeviceSignature;
use App\Domain\Audit\AuditLogger;
use App\Enums\AlertSeverity;
use App\Enums\DeviceStatus;
use App\Enums\EmployeeStatus;
use App\Models\ComplianceAlert;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PunchChallenge;
use App\Models\User;
use App\Notifications\DeviceSecurityAlert;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vinculación, autorización y revocación de dispositivos móviles.
 *
 * Controles contra préstamo de credenciales (petición expresa de la empresa):
 *  1. UN teléfono autorizado por colaborador. Iniciar sesión en otro teléfono lo deja
 *     "pendiente por cambio de teléfono" y avisa a Sistemas / RH; al autorizarlo, el
 *     teléfono anterior se revoca automáticamente.
 *  2. Un teléfono físico (hardware_id) no puede quedar vinculado a dos personas: un
 *     segundo usuario en el mismo teléfono se bloquea, se alerta y se audita.
 *  3. La autenticación de cada marcaje exige la biometría del propio teléfono (secreto
 *     del dispositivo) y, si la política lo pide, verificación facial 1:1.
 */
final class DeviceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * Registra o reconoce el dispositivo. Devuelve el secreto en claro sólo
     * cuando se emite (alta o re-vinculación tras revocación).
     *
     * @param  array{device_uuid:string, hardware_id?:string|null, name:string, platform:string, os_version:?string, app_version:string, model:?string, biometric_available:bool}  $info
     * @return array{device: Device, secret: string|null}
     */
    public function register(User $user, int $organizationId, array $info): array
    {
        $this->assertHardwareAvailable($user, $organizationId, $info);
        $hardwareId = isset($info['hardware_id']) && $info['hardware_id'] !== '' ? (string) $info['hardware_id'] : null;

        $device = Device::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('device_uuid', $info['device_uuid'])
            ->first();

        $meta = [
            'name' => Str::limit($info['name'], 115, ''),
            'platform' => $info['platform'],
            'os_version' => $info['os_version'],
            'app_version' => $info['app_version'],
            'model' => $info['model'],
            'biometric_available' => $info['biometric_available'],
            'last_seen_at' => now(),
        ];
        if ($hardwareId !== null) {
            $meta['hardware_id'] = $hardwareId;
        }

        if ($device !== null && $device->status !== DeviceStatus::Revoked) {
            $device->forceFill($meta)->save();

            return ['device' => $device, 'secret' => null];
        }

        $secret = DeviceSignature::generateSecret();
        $employeeId = Employee::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('organization_id', $organizationId)->value('id');

        // ¿Ya tiene (o tuvo) otro teléfono activo? Entonces es un CAMBIO de teléfono.
        $hasOtherActive = Device::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('device_uuid', '!=', $info['device_uuid'])
            ->whereIn('status', [DeviceStatus::Approved, DeviceStatus::Pending])
            ->exists();
        $everHadDevice = Device::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $user->id)->exists();

        $autoApprove = (bool) config('integrity.auto_approve_first_device') && ! $everHadDevice;
        $reason = $hasOtherActive ? 'device_change' : ($device !== null ? 'relink' : 'new_device');

        if ($device !== null) {
            // Re-vinculación tras revocación: nuevo secreto y nueva autorización.
            $device->forceFill($meta + [
                'secret' => $secret,
                'status' => DeviceStatus::Pending,
                'pending_reason' => $reason,
                'approved_at' => null, 'approved_by' => null,
                'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null,
            ])->save();
        } else {
            $device = Device::query()->create($meta + [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'employee_id' => $employeeId,
                'device_uuid' => $info['device_uuid'],
                'secret' => $secret,
                'status' => DeviceStatus::Pending,
                'pending_reason' => $reason,
            ]);
        }

        if ($autoApprove) {
            $device->forceFill(['status' => DeviceStatus::Approved, 'approved_at' => now(), 'pending_reason' => null])->save();
        }

        $this->audit->log('device.registered', $device, 'Dispositivo vinculado: '.$device->name, [
            'auto_approved' => $autoApprove,
            'reason' => $reason,
            'model' => $device->model,
        ], $organizationId);

        if (! $autoApprove) {
            $this->notifyApprovers($organizationId, $employeeId, new DeviceSecurityAlert(
                $hasOtherActive ? 'device_change' : 'new_device',
                $user->name,
                null,
                $device->id,
                $device->model ?? $device->name,
            ));
        }

        return ['device' => $device, 'secret' => $secret];
    }

    /**
     * Autoriza el dispositivo. Si el colaborador tenía otro teléfono activo, éste se
     * revoca (un solo teléfono por colaborador).
     */
    public function approve(User $by, Device $device): Device
    {
        // Vínculo uno a uno sólo con personal activo: tras la baja no se autorizan teléfonos.
        $employee = $device->employee_id !== null ? Employee::query()->withoutGlobalScopes()->find($device->employee_id) : null;
        if ($employee !== null && $employee->status === EmployeeStatus::Terminated) {
            throw new AttendanceException('employee_terminated', 'El colaborador está dado de baja: no se pueden autorizar dispositivos.', 422);
        }

        $device->forceFill([
            'status' => DeviceStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $by->id,
            'pending_reason' => null,
        ])->save();
        $this->audit->log('device.approved', $device, 'Dispositivo autorizado: '.$device->name);

        $others = Device::query()->withoutGlobalScopes()
            ->where('user_id', $device->user_id)
            ->whereKeyNot($device->id)
            ->whereIn('status', [DeviceStatus::Approved, DeviceStatus::Pending])
            ->get();
        foreach ($others as $old) {
            $this->revoke($by, $old, 'Reemplazado por cambio de teléfono autorizado', $device);
        }

        return $device;
    }

    public function revoke(User $by, Device $device, string $reason, ?Device $replacement = null): Device
    {
        $device->forceFill([
            'status' => DeviceStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => $by->id,
            'revoke_reason' => $reason,
            'replaced_by_device_id' => $replacement?->id,
            'pending_reason' => null,
        ])->save();

        // Cierra las sesiones de API emitidas para este dispositivo.
        $device->user?->tokens()->where('name', 'device:'.$device->device_uuid)->delete();

        $this->audit->log('device.revoked', $device, 'Dispositivo revocado: '.$device->name, ['reason' => $reason]);

        return $device;
    }

    /**
     * Baja del colaborador: revoca el mismo día todos sus dispositivos (autorizados o
     * pendientes) y cierra todas las sesiones de API de su usuario (PDF pantallas 09 y 11).
     *
     * @return int número de dispositivos revocados
     */
    public function revokeAllForEmployee(User $by, Employee $employee, string $reason): int
    {
        $devices = Device::query()->withoutGlobalScopes()
            ->where('organization_id', $employee->organization_id)
            ->where(function ($q) use ($employee) {
                $q->where('employee_id', $employee->id);
                if ($employee->user_id !== null) {
                    $q->orWhere('user_id', $employee->user_id);
                }
            })
            ->whereIn('status', [DeviceStatus::Approved, DeviceStatus::Pending])
            ->get();

        foreach ($devices as $device) {
            $this->revoke($by, $device, $reason);
        }

        if ($employee->user_id !== null) {
            User::query()->find($employee->user_id)?->tokens()->delete();
        }

        return $devices->count();
    }

    public function challenge(Device $device): PunchChallenge
    {
        PunchChallenge::query()->where('device_id', $device->id)->where('expires_at', '<', now()->subHour())->delete();

        return PunchChallenge::query()->create([
            'device_id' => $device->id,
            'nonce' => bin2hex(random_bytes(24)),
            'expires_at' => now()->addSeconds((int) config('integrity.challenge_ttl', 120)),
        ]);
    }

    /**
     * Un teléfono físico sólo puede pertenecer a una persona. Llamar FUERA de
     * transacciones para que la alerta y la auditoría persistan al rechazar.
     *
     * @param  array<string, mixed>  $info
     */
    public function assertHardwareAvailable(User $user, int $organizationId, array $info): void
    {
        $hardwareId = isset($info['hardware_id']) && $info['hardware_id'] !== '' ? (string) $info['hardware_id'] : null;
        if ($hardwareId === null) {
            return;
        }

        $owner = Device::query()->withoutGlobalScopes()
            ->where('hardware_id', $hardwareId)
            ->where('user_id', '!=', $user->id)
            ->whereIn('status', [DeviceStatus::Approved, DeviceStatus::Pending])
            ->with('user:id,name')
            ->first();

        if ($owner === null) {
            return;
        }

        $ownerName = $owner->user?->name ?? 'otro colaborador';
        $this->audit->log('device.in_use_blocked', $owner, "{$user->name} intentó iniciar sesión en el teléfono de {$ownerName}", [
            'attempted_user_id' => $user->id,
            'model' => $info['model'] ?? null,
        ], $organizationId);

        $employeeId = Employee::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('organization_id', $organizationId)->value('id');
        if ($employeeId !== null) {
            ComplianceAlert::query()->withoutGlobalScopes()->firstOrCreate(
                ['employee_id' => $employeeId, 'date' => now()->toDateString(), 'rule_code' => 'device_in_use'],
                [
                    'organization_id' => $organizationId,
                    'severity' => AlertSeverity::Critical,
                    'message' => "Intento de iniciar sesión en el teléfono de {$ownerName}.",
                    'legal_basis' => 'Política interna de uso de dispositivos',
                    'data' => ['owner_user_id' => $owner->user_id, 'device_id' => $owner->id],
                    'status' => 'open',
                ],
            );
        }

        $this->notifyApprovers($organizationId, $employeeId, new DeviceSecurityAlert('device_in_use', $ownerName, $user->name, $owner->id, $owner->model));

        throw new AttendanceException(
            'device_in_use',
            'Este teléfono ya está registrado a nombre de otra persona. Acude con Sistemas o Recursos Humanos.',
            409,
        );
    }

    /**
     * Avisa a quienes pueden autorizar dispositivos (Sistemas / RH) con alcance sobre el colaborador.
     */
    private function notifyApprovers(int $organizationId, ?int $employeeId, DeviceSecurityAlert $notification): void
    {
        $organization = Organization::query()->find($organizationId);
        if ($organization === null) {
            return;
        }

        $this->current->runAs($organization, function () use ($organization, $employeeId, $notification) {
            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($organization->id);

            /** @var Collection<int, User> $candidates */
            $candidates = $organization->users()->wherePivot('status', 'active')->get();
            $scope = app(AccessScope::class);
            foreach ($candidates as $candidate) {
                $candidate->unsetRelation('roles')->unsetRelation('permissions');
                if (! $candidate->can('devices.approve')) {
                    continue;
                }
                if ($employeeId !== null && ! $scope->canAccessEmployee($candidate, $employeeId)) {
                    continue;
                }
                $candidate->notify($notification->forOrganization($organization->id));
            }

            $registrar->setPermissionsTeamId($previous);
        });
    }
}
