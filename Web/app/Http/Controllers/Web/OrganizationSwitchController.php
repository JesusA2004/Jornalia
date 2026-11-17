<?php

namespace App\Http\Controllers\Web;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Cambia la organización activa del usuario (selector de empresa del encabezado).
 * Sólo permite organizaciones a las que el usuario pertenece de forma activa
 * (o cualquiera si es super administrador). El cambio queda auditado en la
 * organización de origen y en la de destino.
 */
final class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ], [], ['organization_id' => 'organización']);

        /** @var User $user */
        $user = $request->user();
        $targetId = (int) $validated['organization_id'];

        abort_unless(
            $user->is_super_admin || $user->belongsToOrganization($targetId),
            403,
            'No perteneces a esa organización.',
        );

        $target = Organization::query()->findOrFail($targetId);
        $previousId = $user->current_organization_id;

        if ($previousId === $target->id) {
            return redirect()->back();
        }

        $user->forceFill(['current_organization_id' => $target->id])->save();

        $context = ['from_organization_id' => $previousId, 'to_organization_id' => $target->id];
        $description = "Cambio de organización activa a {$target->name}";

        if ($previousId !== null) {
            $audit->log('organization.switched_out', $user, $description, $context, $previousId);
        }
        $audit->log('organization.switched_in', $user, $description, $context, $target->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Ahora trabajas en {$target->name}."]);

        return redirect()->route('dashboard');
    }
}
