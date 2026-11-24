<?php

use App\Domain\Security\RoleStructure;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;

/**
 * Roles mínimos del PDF (pantalla 18) para instalaciones existentes, sin perder
 * asignaciones. Equivalencias documentadas en PermissionCatalog::LEGACY_ROLES:
 *   «Administrador de empresa» → «Administrador de plataforma» (sin permisos operativos),
 *   «Recursos Humanos» → «Administrador RH», «Auditor de cumplimiento» → «Contraloría».
 * Idempotente (también disponible como `php artisan asistencias:roles`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $structure = app(RoleStructure::class);
        Organization::query()->orderBy('id')->each(fn (Organization $organization) => $structure->apply($organization));
    }

    public function down(): void
    {
        // Sin reversa automática: los cambios quedan en la bitácora con valor anterior y nuevo.
    }
};
