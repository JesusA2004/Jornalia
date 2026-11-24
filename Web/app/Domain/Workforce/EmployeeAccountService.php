<?php

namespace App\Domain\Workforce;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\PermissionCatalog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cuenta de usuario vinculada a un expediente. Nunca usa contraseñas fijas:
 * genera una contraseña temporal aleatoria (mostrada una sola vez) o envía un
 * enlace de restablecimiento.
 */
final class EmployeeAccountService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * @return array{user: User, temporary_password: string|null, reset_link_sent: bool}
     */
    public function createFor(User $by, Employee $employee, string $email, string $mode, ?string $role = 'Colaborador'): array
    {
        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['email' => 'El colaborador ya tiene una cuenta vinculada.']);
        }

        $email = Str::lower(trim($email));
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null && Employee::query()->withoutGlobalScopes()->where('user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ese correo ya está vinculado a otro expediente.']);
        }

        $organization = $this->current->require();
        $temporary = null;

        $user = DB::transaction(function () use ($existing, $email, $employee, $organization, $role, $mode, &$temporary) {
            $user = $existing;
            if ($user === null) {
                $temporary = $mode === 'password' ? $this->temporaryPassword() : null;
                $user = User::query()->create([
                    'name' => $employee->fullName(),
                    'email' => $email,
                    'password' => $temporary ?? Str::password(40),
                    'status' => 'active',
                    'current_organization_id' => $organization->id,
                ]);
                // La cuenta la crea un administrador que ya verificó la identidad.
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif ($mode === 'password') {
                throw ValidationException::withMessages(['email' => 'El correo ya tiene una cuenta: se vinculará y podrá usar su contraseña actual o restablecerla. Elige «Enviar enlace».']);
            }

            // Un acceso desactivado sólo se reactiva en Usuarios (con sus validaciones), no aquí.
            $inactive = $user->organizations()->where('organizations.id', $organization->id)->wherePivot('status', '!=', 'active')->exists();
            if ($inactive) {
                throw ValidationException::withMessages(['email' => 'Esa cuenta tiene el acceso desactivado en la organización. Reactívala en Usuarios y roles.']);
            }
            $user->organizations()->syncWithoutDetaching([$organization->id => ['status' => 'active', 'access_all_branches' => false]]);
            if ($role !== null && Role::query()->where('organization_id', $organization->id)->where('name', $role)->exists()) {
                $registrar = app(PermissionRegistrar::class);
                $registrar->setPermissionsTeamId($organization->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                // Separación de funciones: no combinar administración de usuarios con operación.
                $resulting = $user->getAllPermissions()->pluck('name')
                    ->merge(Role::query()->where('organization_id', $organization->id)->where('name', $role)->firstOrFail()->permissions->pluck('name'))
                    ->unique()->all();
                if (PermissionCatalog::segregationConflicts($resulting) !== []) {
                    throw ValidationException::withMessages(['role' => 'Ese rol combinaría administración de usuarios con permisos operativos (separación de funciones).']);
                }
                if (! $user->hasRole($role)) {
                    $user->assignRole($role);
                }
            }

            $employee->forceFill(['user_id' => $user->id, 'email' => $employee->email ?? $email])->save();
            Device::query()->where('user_id', $user->id)->whereNull('employee_id')->update(['employee_id' => $employee->id]);

            return $user;
        });

        $sent = false;
        if ($mode === 'reset') {
            $sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        }

        $this->audit->log('employee.account_created', $employee, 'Cuenta de usuario vinculada a '.$employee->fullName(), [
            'user_id' => $user->id,
            'mode' => $mode,
            'reset_link_sent' => $sent,
        ]);

        return ['user' => $user, 'temporary_password' => $temporary, 'reset_link_sent' => $sent];
    }

    public function sendReset(Employee $employee): bool
    {
        $user = $employee->user ?? throw ValidationException::withMessages(['email' => 'El colaborador no tiene cuenta vinculada.']);
        $sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        $this->audit->log('employee.password_reset_sent', $employee, 'Enlace de restablecimiento enviado a '.$employee->fullName(), ['user_id' => $user->id, 'sent' => $sent]);

        return $sent;
    }

    public function unlink(Employee $employee): void
    {
        $userId = $employee->user_id ?? throw ValidationException::withMessages(['email' => 'El colaborador no tiene cuenta vinculada.']);
        $employee->forceFill(['user_id' => null])->save();
        $this->audit->log('employee.account_unlinked', $employee, 'Cuenta desvinculada de '.$employee->fullName(), ['user_id' => $userId]);
    }

    private function temporaryPassword(): string
    {
        // 16 caracteres con mayúsculas, minúsculas, números y símbolos.
        return Str::password(16);
    }
}
