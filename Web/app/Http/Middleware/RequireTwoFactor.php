<?php

namespace App\Http\Middleware;

use App\Domain\Security\TwoFactorPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirige a «Seguridad» a quien tiene un rol administrativo y aún no configura el
 * doble factor. Debe ejecutarse después de `organization` (los permisos dependen
 * de la organización activa).
 */
final class RequireTwoFactor
{
    public function __construct(private readonly TwoFactorPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || ! $this->policy->missingFor($user)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Configura el doble factor para continuar.');
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => 'Tu rol requiere doble factor. Configúralo para continuar.']);

        return redirect()->route('security.edit');
    }
}
