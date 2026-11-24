<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Security\AccessScope;
use App\Domain\Security\KioskService;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Branch;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kioscos de sucursal (`devices.kiosk`). El código de activación se muestra una
 * sola vez (flash de sesión); en BD sólo queda su hash. Sin borrado físico.
 */
class KioskController extends AdminController
{
    use OrganizationRules;

    public function __construct(
        private readonly KioskService $kiosks,
        private readonly AccessScope $scope,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'branch_id' => ['required', 'integer', $this->existsInOrg('branches')],
        ], [], ['name' => 'nombre', 'branch_id' => 'sucursal']);

        $branch = Branch::query()->findOrFail($data['branch_id']);
        $this->assertBranchInScope($user, $branch->id);

        $result = $this->kiosks->create($user, $branch, $data['name']);
        $this->flashCode($request, $result['kiosk'], $result['activation_code']);
        $this->toast('Kiosco creado. Usa el código en la tableta.');

        return back();
    }

    public function code(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $user = $this->actor($request);
        $this->assertBranchInScope($user, $kiosk->branch_id);

        $code = $this->kiosks->regenerateCode($user, $kiosk);
        $this->flashCode($request, $kiosk, $code);
        $this->toast('Nuevo código generado. La tableta debe activarse de nuevo.');

        return back();
    }

    public function revoke(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $user = $this->actor($request);
        $this->assertBranchInScope($user, $kiosk->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);

        $this->kiosks->revoke($user, $kiosk, $data['reason']);
        $this->toast("Kiosco «{$kiosk->name}» revocado.");

        return back();
    }

    private function flashCode(Request $request, Kiosk $kiosk, string $code): void
    {
        $request->session()->flash('kiosk_activation', [
            'kiosk_id' => $kiosk->id,
            'name' => $kiosk->name,
            'code' => $code,
            'expires_at' => $kiosk->activation_expires_at?->toIso8601String(),
        ]);
    }

    private function assertBranchInScope(User $user, int $branchId): void
    {
        if ($this->scope->hasFullAccess($user)) {
            return;
        }
        abort_unless($user->branches()->where('branches.id', $branchId)->exists(), 404);
    }
}
