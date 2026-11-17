<?php

namespace App\Domain\Organization;

use App\Domain\Notifications\LocationNotice;
use App\Domain\Security\PermissionCatalog;
use App\Enums\LocationMode;
use App\Models\AttendancePolicy;
use App\Models\IncidenceType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\VacationPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta de una organización con su configuración inicial legítima: roles,
 * política de asistencia base, política de vacaciones con mínimos legales y
 * catálogo base de incidencias. No crea colaboradores ni datos ficticios.
 */
final class OrganizationProvisioner
{
    /**
     * Incidencias del sistema. Efectos según LFT; la configuración puede ampliarlas.
     *
     * @var list<array{code:string,name:string,category:string,effect:string,evidence:bool,approval:bool,payroll:bool,requestable:bool,description:string}>
     */
    public const SYSTEM_INCIDENCES = [
        ['code' => 'RETARDO', 'name' => 'Retardo', 'category' => 'time', 'effect' => 'informative', 'evidence' => false, 'approval' => false, 'payroll' => false, 'requestable' => false, 'description' => 'Generada por el sistema a partir de marcajes y tolerancia.'],
        ['code' => 'FALTA_INJUSTIFICADA', 'name' => 'Falta injustificada', 'category' => 'absence', 'effect' => 'unjustified', 'evidence' => false, 'approval' => true, 'payroll' => true, 'requestable' => false, 'description' => 'Ausencia sin justificación aprobada.'],
        ['code' => 'FALTA_JUSTIFICADA', 'name' => 'Falta justificada', 'category' => 'absence', 'effect' => 'justified', 'evidence' => true, 'approval' => true, 'payroll' => true, 'requestable' => true, 'description' => 'Ausencia justificada con evidencia.'],
        ['code' => 'OMISION_ENTRADA', 'name' => 'Omisión de entrada', 'category' => 'time', 'effect' => 'informative', 'evidence' => false, 'approval' => false, 'payroll' => false, 'requestable' => false, 'description' => 'Se atiende mediante rectificación.'],
        ['code' => 'OMISION_SALIDA', 'name' => 'Omisión de salida', 'category' => 'time', 'effect' => 'informative', 'evidence' => false, 'approval' => false, 'payroll' => false, 'requestable' => false, 'description' => 'Se atiende mediante rectificación.'],
        ['code' => 'PERMISO_CON_GOCE', 'name' => 'Permiso con goce de sueldo', 'category' => 'leave', 'effect' => 'paid_absence', 'evidence' => false, 'approval' => true, 'payroll' => true, 'requestable' => true, 'description' => 'Permiso autorizado con pago.'],
        ['code' => 'PERMISO_SIN_GOCE', 'name' => 'Permiso sin goce de sueldo', 'category' => 'leave', 'effect' => 'unpaid_absence', 'evidence' => false, 'approval' => true, 'payroll' => true, 'requestable' => true, 'description' => 'Permiso autorizado sin pago.'],
        ['code' => 'INCAPACIDAD', 'name' => 'Incapacidad', 'category' => 'disability', 'effect' => 'justified', 'evidence' => true, 'approval' => true, 'payroll' => true, 'requestable' => true, 'description' => 'Incapacidad emitida por la institución de seguridad social.'],
        ['code' => 'DESCANSO_TRABAJADO', 'name' => 'Descanso trabajado', 'category' => 'time', 'effect' => 'informative', 'evidence' => false, 'approval' => true, 'payroll' => true, 'requestable' => false, 'description' => 'Trabajo en día de descanso (LFT art. 73).'],
        ['code' => 'PATERNIDAD', 'name' => 'Permiso de paternidad', 'category' => 'leave', 'effect' => 'paid_absence', 'evidence' => true, 'approval' => true, 'payroll' => true, 'requestable' => true, 'description' => 'LFT art. 132 fr. XXVII Bis.'],
    ];

    public function __construct(private readonly CurrentOrganization $current) {}

    public function provision(string $name, string $timezone = 'America/Mexico_City'): Organization
    {
        return DB::transaction(function () use ($name, $timezone) {
            $slug = Str::slug($name);
            $organization = Organization::query()->create([
                'name' => $name,
                'slug' => Organization::query()->where('slug', $slug)->exists() ? $slug.'-'.Str::lower(Str::random(4)) : $slug,
                'timezone' => $timezone,
            ]);

            $this->current->runAs($organization, function () use ($organization) {
                $this->syncRoles($organization);

                AttendancePolicy::query()->create([
                    'name' => 'Política general',
                    'location_mode' => LocationMode::Record,
                    'allowed_auth_methods' => ['device_biometric', 'device_credential'],
                    'require_strong_biometric' => false,
                    'allow_offline' => true,
                    'offline_max_hours' => 72,
                    'is_default' => true,
                ]);

                VacationPolicy::query()->create([
                    'name' => 'Mínimo legal',
                    'extra_days' => [],
                    'premium_percent' => '25.00',
                    'valid_from' => '2023-01-01',
                    'is_default' => true,
                ]);

                foreach (self::SYSTEM_INCIDENCES as $i) {
                    IncidenceType::query()->create([
                        'code' => $i['code'], 'name' => $i['name'], 'category' => $i['category'], 'description' => $i['description'],
                        'operational_effect' => $i['effect'], 'requires_evidence' => $i['evidence'], 'requires_approval' => $i['approval'],
                        'affects_payroll' => $i['payroll'], 'employee_requestable' => $i['requestable'], 'is_system' => true,
                    ]);
                }

                // Aviso al colaborador de que su ubicación se registra durante la jornada (con acuse).
                LocationNotice::ensureFor($organization);
            });

            return $organization;
        });
    }

    public function syncRoles(Organization $organization): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organization->id);

        foreach (PermissionCatalog::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web', 'organization_id' => $organization->id]);
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions === ['*'] ? PermissionCatalog::all() : $permissions);
            } elseif ($permissions === ['*']) {
                // El rol comodín siempre recibe los permisos de módulos agregados después del alta.
                $role->givePermissionTo(PermissionCatalog::all());
            }
        }

        $registrar->setPermissionsTeamId($previous);
        $registrar->forgetCachedPermissions();
    }
}
