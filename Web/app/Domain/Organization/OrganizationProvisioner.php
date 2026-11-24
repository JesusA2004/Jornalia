<?php

namespace App\Domain\Organization;

use App\Domain\Incidences\IncidenceCatalog;
use App\Domain\Notifications\LocationNotice;
use App\Domain\Security\RoleStructure;
use App\Enums\LocationMode;
use App\Models\AttendancePolicy;
use App\Models\Organization;
use App\Models\VacationPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta de una organización con su configuración inicial legítima: roles,
 * política de asistencia base, política de vacaciones con mínimos legales y
 * catálogo base de incidencias. No crea colaboradores ni datos ficticios.
 */
final class OrganizationProvisioner
{
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

                // Catálogo cerrado de incidencias (PDF pantalla 06).
                IncidenceCatalog::syncOrganization($organization->id);

                // Aviso al colaborador de que su ubicación se registra durante la jornada (con acuse).
                LocationNotice::ensureFor($organization);
            });

            return $organization;
        });
    }

    /**
     * Roles mínimos (PDF pantalla 18). Idempotente: ver RoleStructure.
     */
    public function syncRoles(Organization $organization): void
    {
        app(RoleStructure::class)->apply($organization);
    }
}
