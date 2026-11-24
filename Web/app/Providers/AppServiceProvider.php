<?php

namespace App\Providers;

use App\Domain\Biometrics\FaceProviderFactory;
use App\Domain\Biometrics\FaceVerificationProvider;
use App\Domain\Security\PermissionCatalog;
use App\Models\User;
use App\Support\NoPhysicalDeletes;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Schema::defaultStringLength(191);

        // Organización activa por petición / job (aislamiento multiempresa).
        $this->app->scoped(CurrentOrganization::class);

        // Motor facial open source si FACE_SERVICE_URL/TOKEN están configurados (docs/seguridad/BIOMETRIA.md).
        $this->app->singleton(
            FaceVerificationProvider::class,
            fn () => FaceProviderFactory::make(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        NoPhysicalDeletes::register();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureAuthorization(): void
    {
        // El superadministrador NO obtiene autorización operativa sobre asistencia
        // (aprobar, validar, aplicar, rectificar): regla dura de separación de funciones.
        // Sólo se le conceden permisos del catálogo NO operativos; las habilidades de policy
        // (`approve`, `update`…) se evalúan en la policy, que a su vez pide el permiso real
        // (p. ej. DevicePolicy::approve exige `devices.approve`, operativo) y la organización.
        // Lo operativo se le NIEGA (false) aunque tenga un rol que lo incluya.
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_super_admin) {
                return null;
            }
            if (PermissionCatalog::isOperational($ability)) {
                return false;
            }

            return in_array($ability, PermissionCatalog::all(), true) ? true : null;
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('location', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('punches', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
