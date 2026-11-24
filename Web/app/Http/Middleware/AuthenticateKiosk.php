<?php

namespace App\Http\Middleware;

use App\Domain\Security\KioskService;
use App\Models\Kiosk;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica la tableta de kiosco por su token (Bearer) y fija la organización
 * del kiosco como organización activa (aislamiento multiempresa).
 */
final class AuthenticateKiosk
{
    public const ATTRIBUTE = 'kiosk';

    public function __construct(
        private readonly KioskService $kiosks,
        private readonly CurrentOrganization $current,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $kiosk = $this->kiosks->authenticate($request->bearerToken());
        if ($kiosk === null) {
            return response()->json(['message' => 'Kiosco no activado o revocado.', 'code' => 'kiosk_unauthenticated'], 401);
        }

        $organization = Organization::query()->find($kiosk->organization_id);
        $this->current->set($organization);
        app(PermissionRegistrar::class)->setPermissionsTeamId($kiosk->organization_id);
        $request->attributes->set(self::ATTRIBUTE, $kiosk);

        return $next($request);
    }

    public static function kiosk(Request $request): Kiosk
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get(self::ATTRIBUTE);

        return $kiosk;
    }
}
