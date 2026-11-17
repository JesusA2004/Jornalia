<?php

namespace Database\Seeders;

use App\Domain\Security\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Sólo datos legítimos: permisos y normativa verificada. No crea usuarios,
 * colaboradores, marcajes ni métricas. El primer administrador se crea con
 * `php artisan asistencias:instalar` (contraseña capturada interactivamente).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->call(LegalFrameworkSeeder::class);
    }
}
