<?php

namespace App\Console\Commands\Testing;

use Database\Seeders\Testing\E2EDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Prepara la base de DEMOSTRACIÓN para pruebas E2E: la recrea desde cero, siembra
 * permisos y normativa (DatabaseSeeder) y después el entorno ficticio de
 * `E2EDemoSeeder`. Escribe las cuentas en `tests/e2e/.auth/cuentas.json`
 * (carpeta ignorada por git).
 *
 * Candados: se niega en producción y si la base de datos de la conexión por
 * defecto no termina en `_e2e`. Nunca toca la base de desarrollo.
 *
 * Uso: `APP_ENV=e2e php artisan e2e:preparar` (usa `.env.e2e`) o
 *      `DB_DATABASE=jornalia_e2e php artisan e2e:preparar`.
 */
#[Signature('e2e:preparar {--salida= : Ruta del JSON de cuentas (por omisión tests/e2e/.auth/cuentas.json)}')]
#[Description('Recrea la base *_e2e con datos de demostración para pruebas E2E y escribe el JSON de cuentas')]
final class PrepareE2ECommand extends Command
{
    public function handle(): int
    {
        $connection = (string) config('database.default');
        $configured = (string) config("database.connections.{$connection}.database");

        if ($this->laravel->environment('production')) {
            $this->error('e2e:preparar no puede ejecutarse en producción.');

            return self::FAILURE;
        }
        if (! str_ends_with($configured, '_e2e')) {
            $this->error("La base de datos «{$configured}» no es de pruebas E2E (debe terminar en «_e2e», p. ej. jornalia_e2e). No se modificó nada.");

            return self::FAILURE;
        }

        try {
            $actual = (string) (DB::connection($connection)->selectOne('SELECT DATABASE() AS name')->name ?? '');
        } catch (Throwable $e) {
            $this->error("No fue posible conectar a «{$configured}»: {$e->getMessage()}");

            return self::FAILURE;
        }
        if ($actual !== $configured || ! str_ends_with($actual, '_e2e')) {
            $this->error("La conexión apunta a «{$actual}» y no a «{$configured}». No se modificó nada.");

            return self::FAILURE;
        }

        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $password = $this->password();
        $this->isolateAnchors();

        $this->info("Preparando entorno E2E en «{$actual}» (entorno: {$this->laravel->environment()})…");
        $started = microtime(true);

        $this->call('migrate:fresh', ['--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->call('db:seed', ['--force' => true]);

        $seeder = $this->laravel->make(E2EDemoSeeder::class);
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->withPassword($password);
        $seeder->__invoke();

        $ctx = $seeder->context();
        $path = $this->writeAccounts($seeder, $password);

        $this->newLine();
        $this->info(sprintf('Entorno E2E listo en %.0f s.', microtime(true) - $started));
        $this->line("Cuentas: {$path}");
        $this->table(['Clave', 'Correo', 'Rol', '2FA'], collect($seeder->accounts())->map(fn ($a, $k) => [$k, $a['email'], $a['role'], $a['totpSecret'] ? 'sí' : 'no'])->values()->all());
        $this->table(['Contador', 'Valor'], collect($ctx->counters)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $integrity = (array) ($ctx->fixtures['integrity'] ?? []);
        $this->line('Integridad: '.(($integrity['valid'] ?? 0) === 1 ? 'ÍNTEGRA' : 'CON HALLAZGOS ('.($integrity['findings'] ?? '?').')'));
        foreach (array_slice($ctx->warnings, 0, 30) as $warning) {
            $this->warn('  · '.$warning);
        }
        if (count($ctx->warnings) > 30) {
            $this->warn('  · … '.(count($ctx->warnings) - 30).' avisos más.');
        }

        return ($integrity['valid'] ?? 0) === 1 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Contraseña común: `E2E_PASSWORD` si existe; si no, aleatoria fuerte en cada corrida.
     */
    private function password(): string
    {
        $fromEnv = getenv('E2E_PASSWORD');
        $fromEnv = $fromEnv !== false && $fromEnv !== '' ? $fromEnv : (string) ($_SERVER['E2E_PASSWORD'] ?? $_ENV['E2E_PASSWORD'] ?? '');
        if ($fromEnv !== '') {
            if (strlen($fromEnv) < 12) {
                $this->warn('E2E_PASSWORD tiene menos de 12 caracteres.');
            }

            return $fromEnv;
        }

        do {
            $password = Str::password(20);
        } while (! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/\d/', $password) || ! preg_match('/[^A-Za-z0-9]/', $password));

        return $password;
    }

    /**
     * Las anclas externas de integridad del entorno E2E no deben mezclarse con las
     * de desarrollo: si no se configuró INTEGRITY_ANCHOR_PATH se usan las de
     * `storage/app/e2e/anchors` (se limpian en cada corrida: la BD se recrea).
     */
    private function isolateAnchors(): void
    {
        $default = storage_path('app/anchors');
        $root = (string) config('filesystems.disks.anchors.root');
        if (rtrim(str_replace('\\', '/', $root), '/') === rtrim(str_replace('\\', '/', $default), '/')) {
            $root = storage_path('app/e2e/anchors');
            config(['filesystems.disks.anchors.root' => $root]);
            Storage::forgetDisk('anchors');
            $this->warn('INTEGRITY_ANCHOR_PATH no está definido: las anclas E2E se escriben en '.$root);
        }

        $e2eRoot = str_replace('\\', '/', storage_path('app/e2e'));
        if (str_starts_with(str_replace('\\', '/', $root), $e2eRoot.'/') && File::isDirectory($root)) {
            File::cleanDirectory($root);
        }
        File::cleanDirectory(storage_path('app/e2e/tmp'));
    }

    private function writeAccounts(E2EDemoSeeder $seeder, string $password): string
    {
        $ctx = $seeder->context();
        $path = (string) ($this->option('salida') ?: base_path('tests/e2e/.auth/cuentas.json'));
        File::ensureDirectoryExists(dirname($path));

        $payload = [
            'password' => $password,
            'baseOrganization' => $ctx->organization->name,
            'emptyOrganization' => $ctx->emptyOrganization?->name,
            'employeePin' => $ctx->pin,
            'generatedAt' => now('UTC')->toIso8601String(),
            'today' => $ctx->today->toDateString(),
            'timezone' => 'America/Mexico_City',
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
            'accounts' => $seeder->accounts(),
            'fixtures' => $ctx->fixtures,
        ];
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }
}
