<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del módulo `communications` para los roles base de organizaciones
 * existentes (las nuevas los reciben de PermissionCatalog). Idempotente.
 */
return new class extends Migration
{
    private const ROLES = ['Administrador de empresa', 'Recursos Humanos', 'Responsable regional', 'Gerente de sucursal'];

    public function up(): void
    {
        $now = now();
        $ids = [];
        foreach (['communications.view', 'communications.send'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            if ($id === null) {
                $id = DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }
            $ids[] = $id;
        }

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversa: los permisos asignados se conservan.
    }
};
