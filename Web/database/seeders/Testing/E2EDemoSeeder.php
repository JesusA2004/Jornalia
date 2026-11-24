<?php

namespace Database\Seeders\Testing;

use App\Domain\Security\PermissionCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\Testing\E2E\AttendanceStage;
use Database\Seeders\Testing\E2E\ClosingStage;
use Database\Seeders\Testing\E2E\Context;
use Database\Seeders\Testing\E2E\OrganizationStage;
use Database\Seeders\Testing\E2E\PeopleStage;
use Database\Seeders\Testing\E2E\WorkflowStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Entorno de DEMOSTRACIÓN exclusivo para pruebas E2E (base `*_e2e`).
 *
 * NUNCA se llama desde DatabaseSeeder ni en producción: lo ejecuta
 * `php artisan e2e:preparar`, que valida el entorno y la base de datos.
 * Datos ficticios (correos `@e2e.test`) salvo los datos oficiales permitidos
 * de la empresa. Todo se registra por los servicios de dominio reales con un
 * reloj simulado relativo a hoy (America/Mexico_City), de modo que las cadenas
 * de integridad, proyecciones, alertas y bitácora sean coherentes.
 */
final class E2EDemoSeeder extends Seeder
{
    private ?string $password = null;

    private ?Context $context = null;

    public function withPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function run(): void
    {
        $password = $this->password ?? throw new RuntimeException('E2EDemoSeeder requiere la contraseña común (usa php artisan e2e:preparar).');
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (app()->environment('production') || ! str_ends_with($database, '_e2e')) {
            throw new RuntimeException('E2EDemoSeeder sólo corre en una base *_e2e fuera de producción.');
        }

        // Autorizar teléfonos es una decisión explícita de RH en este entorno.
        config(['integrity.auto_approve_first_device' => false]);

        $ctx = $this->context = new Context;
        $ctx->pin = self::pin();
        $current = app(CurrentOrganization::class);

        try {
            $this->step('Organización, estructura, horarios y usuarios web', fn () => app(OrganizationStage::class)->run($ctx, $password));

            $workflows = app(WorkflowStage::class);
            $workflows->prepare($ctx);
            app(PeopleStage::class)->schedule($ctx, $password);
            app(AttendanceStage::class)->schedule($ctx);
            $workflows->schedule($ctx);

            $this->step('Línea de tiempo (marcajes y flujos de las últimas semanas)', function () use ($ctx) {
                $ctx->runTimeline(function (int $done, int $total) {
                    $this->command?->getOutput()->write("\r    {$done}/{$total} acciones");
                });
                $this->command?->getOutput()->writeln('');
            });

            $this->step('Cierre: recálculo, decisiones, alertas, ubicación e integridad', fn () => app(ClosingStage::class)->run($ctx));
        } finally {
            $ctx->backToPresent();
            Auth::guard('web')->forgetUser();
            $current->set(null);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    public function context(): Context
    {
        return $this->context ?? throw new RuntimeException('El seeder E2E aún no se ejecuta.');
    }

    /**
     * Cuentas para el JSON de pruebas E2E.
     *
     * @return array<string, array{email:string, name:string, role:string, totpSecret:string|null, organization:string}>
     */
    public function accounts(): array
    {
        $ctx = $this->context();
        $roles = [
            'plataforma' => PermissionCatalog::ROLE_PLATFORM_ADMIN,
            'rh' => PermissionCatalog::ROLE_HR_ADMIN,
            'rh_nomina' => PermissionCatalog::ROLE_HR_ADMIN,
            'direccion' => PermissionCatalog::ROLE_DIRECTION,
            'regional' => PermissionCatalog::ROLE_REGIONAL,
            'gerente' => PermissionCatalog::ROLE_BRANCH_MANAGER,
            'contraloria' => PermissionCatalog::ROLE_COMPTROLLER,
            'autoridad' => PermissionCatalog::ROLE_AUTHORITY,
            'rh_vacia' => PermissionCatalog::ROLE_HR_ADMIN,
        ];

        $accounts = [];
        foreach ($roles as $key => $role) {
            $user = $ctx->user($key);
            $accounts[$key] = [
                'email' => $user->email,
                'name' => $user->name,
                'role' => $role,
                'totpSecret' => $ctx->totp[$key] ?? null,
                'organization' => $key === 'rh_vacia' ? (string) $ctx->emptyOrganization?->name : $ctx->organization->name,
            ];
        }

        // Colaborador con app (nombre largo): útil para vistas propias y estados vacíos de permisos.
        $employeeUser = $ctx->employeeUser(E2E\Roster::LONG_NAME);
        $accounts['colaborador'] = [
            'email' => $employeeUser->email,
            'name' => $employeeUser->name,
            'role' => PermissionCatalog::ROLE_EMPLOYEE,
            'totpSecret' => null,
            'organization' => $ctx->organization->name,
        ];

        return $accounts;
    }

    private function step(string $label, \Closure $callback): void
    {
        $start = microtime(true);
        $this->command?->getOutput()->writeln("  · {$label}…");
        $callback();
        $this->command?->getOutput()->writeln(sprintf('    listo en %.1f s', microtime(true) - $start));
    }

    /**
     * NIP de firma aleatorio de 6 dígitos que no sea trivial (ni repetido ni consecutivo).
     */
    private static function pin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        } while (count(array_unique(str_split($pin))) < 3 || str_contains('0123456789', $pin) || str_contains('9876543210', $pin));

        return $pin;
    }
}
