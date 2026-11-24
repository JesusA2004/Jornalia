<?php

namespace Tests\Concerns;

use App\Domain\Attendance\DeviceSignature;
use App\Domain\Attendance\PolicyResolver;
use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchRecorder;
use App\Domain\Organization\OrganizationProvisioner;
use App\Domain\Security\PermissionCatalog;
use App\Enums\AuthMethod;
use App\Enums\DeviceStatus;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Enums\ShiftKind;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\Organization;
use App\Models\Punch;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Construye estructuras reales y aisladas para pruebas (sólo en entorno de test).
 */
trait BuildsWorkforce
{
    protected function seedLegal(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    protected function organization(string $name = 'Empresa de Prueba'): Organization
    {
        $org = app(OrganizationProvisioner::class)->provision($name.' '.Str::random(4));
        $this->actingInOrganization($org);

        return $org;
    }

    protected function actingInOrganization(Organization $org): void
    {
        app(CurrentOrganization::class)->set($org);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    }

    protected function userIn(Organization $org, ?string $role = null, bool $allBranches = false): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->organizations()->attach($org->id, ['status' => 'active', 'access_all_branches' => $allBranches]);
        if ($role !== null) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            // Compatibilidad de pruebas con los nombres de rol anteriores (ver PermissionCatalog::LEGACY_ROLES).
            // «Administrador de empresa» equivalía a todos los permisos: en pruebas se modela como
            // plataforma + RH (en producción la separación de funciones impide asignar ambos).
            $roles = match ($role) {
                'Administrador de empresa' => [PermissionCatalog::ROLE_PLATFORM_ADMIN, PermissionCatalog::ROLE_HR_ADMIN],
                default => [PermissionCatalog::LEGACY_ROLES[$role] ?? $role],
            };
            $user->assignRole($roles);
            // Los roles administrativos exigen doble factor: en pruebas se da por configurado.
            if ($role !== PermissionCatalog::ROLE_EMPLOYEE) {
                $user->forceFill(['two_factor_secret' => encrypt('TESTSECRET'), 'two_factor_confirmed_at' => now()])->save();
            }
        }

        return $user;
    }

    /**
     * Horario semanal fijo lunes a viernes (0..4) o días indicados.
     *
     * @param  list<int>  $days  índices 0=lunes … 6=domingo
     */
    protected function schedule(string $start = '09:00', string $end = '18:00', int $break = 60, array $days = [0, 1, 2, 3, 4], ShiftKind $kind = ShiftKind::Day, int $tolerance = 10): Schedule
    {
        $schedule = Schedule::query()->create([
            'name' => "Horario {$start}-{$end}",
            'code' => 'H'.Str::upper(Str::random(6)),
            'type' => 'fixed',
            'shift_kind' => $kind,
            'tolerance_minutes' => $tolerance,
        ]);
        foreach ($days as $d) {
            $schedule->segments()->create(['day_index' => $d, 'ordinal' => 1, 'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $break]);
        }

        return $schedule;
    }

    protected function employee(Organization $org, ?User $user = null, ?Schedule $schedule = null, string $hiredOn = '2024-01-15', ?Branch $branch = null, ?string $birthDate = null): Employee
    {
        $user ??= $this->userIn($org, 'Colaborador');
        $branch ??= Branch::query()->firstOrCreate(['code' => 'MATRIZ'], ['name' => 'Matriz']);

        $employee = Employee::query()->create([
            'user_id' => $user->id,
            'employee_number' => 'E'.Str::upper(Str::random(6)),
            'first_name' => 'Ana',
            'last_name' => 'Prueba',
            'status' => 'active',
            'birth_date' => $birthDate,
        ]);
        /** @var Employment $employment */
        $employment = $employee->employments()->create([
            'organization_id' => $org->id,
            'starts_on' => $hiredOn,
            'seniority_starts_on' => $hiredOn,
            'contract_type' => 'indefinite',
            'work_modality' => 'onsite',
        ]);
        $employment->assignments()->create([
            'organization_id' => $org->id,
            'effective_from' => $hiredOn,
            'branch_id' => $branch->id,
            'schedule_id' => $schedule?->id,
        ]);

        return $employee->refresh();
    }

    /**
     * @return array{device: Device, secret: string}
     */
    protected function approvedDevice(User $user, Employee $employee): array
    {
        $secret = DeviceSignature::generateSecret();
        $device = Device::query()->create([
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'device_uuid' => (string) Str::uuid(),
            'name' => 'Teléfono de prueba',
            'platform' => 'android',
            'app_version' => '1.0.0',
            'secret' => $secret,
            'status' => DeviceStatus::Approved,
            'approved_at' => now(),
        ]);

        return ['device' => $device, 'secret' => $secret];
    }

    /**
     * Marcaje directo vía dominio (web_session; la política lo permite en pruebas).
     */
    protected function punchAt(Employee $employee, PunchType $type, string $utcTime): Punch
    {
        $this->travelTo(CarbonImmutable::parse($utcTime, 'UTC'));
        $policy = app(PolicyResolver::class)->for($employee);
        if ($policy->exists && ! $policy->web_punch_allowed) {
            $policy->update(['web_punch_allowed' => true, 'location_mode' => 'free']);
        }

        $result = app(PunchRecorder::class)->record($employee->user, $employee, new PunchInput(
            clientUuid: (string) Str::uuid(),
            type: $type,
            source: PunchSource::Web,
            authMethod: AuthMethod::WebSession,
            deviceTime: null, deviceTimeRaw: null, timezone: 'America/Mexico_City',
            deviceUuid: null, nonce: null, signature: null, capturedOffline: false,
            latitude: null, longitude: null, accuracyMeters: null, locationCapturedAt: null, locationMocked: null,
            faceVerificationId: null, ipAddress: '127.0.0.1', userAgent: 'tests',
        ), null);

        return $result['punch'];
    }
}
