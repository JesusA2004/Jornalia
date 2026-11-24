<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Security\PermissionCatalog;
use App\Domain\Workforce\EmployeeAccountService;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuenta de usuario vinculada al expediente.
 */
class EmployeeAccountController extends AdminController
{
    public function __construct(private readonly EmployeeAccountService $accounts) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('manageAccount', $employee);

        // Sin escalamiento: desde el expediente sólo se otorga «Colaborador» salvo a quien
        // administra roles (`users.roles`); la autoridad laboral nunca (exige vigencia).
        $grantable = $request->user()?->can('users.roles')
            ? Role::query()->where('organization_id', $this->organizationId())->where('name', '!=', PermissionCatalog::ROLE_AUTHORITY)->pluck('name')->all()
            : [PermissionCatalog::ROLE_EMPLOYEE];
        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'mode' => ['required', Rule::in(['password', 'reset'])],
            'role' => ['nullable', 'string', Rule::in($grantable)],
        ], [], ['email' => 'correo', 'mode' => 'método de acceso', 'role' => 'rol']);

        $result = $this->accounts->createFor($this->actor($request), $employee, $data['email'], $data['mode'], $data['role'] ?? 'Colaborador');

        if ($result['temporary_password'] !== null) {
            // Se muestra una sola vez (sesión flash) y nunca se registra en bitácora.
            $request->session()->flash('temporary_password', $result['temporary_password']);
            $this->toast('Cuenta creada. Comparte la contraseña temporal de forma segura.');
        } else {
            $this->toast($result['reset_link_sent'] ? 'Cuenta vinculada. Se envió el enlace para definir contraseña.' : 'Cuenta vinculada. No fue posible enviar el enlace; inténtalo de nuevo.', $result['reset_link_sent'] ? 'success' : 'warning');
        }

        return back();
    }

    public function reset(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('manageAccount', $employee);
        $sent = $this->accounts->sendReset($employee);
        $this->toast($sent ? 'Enlace de restablecimiento enviado.' : 'No fue posible enviar el enlace (espera unos minutos antes de reintentar).', $sent ? 'success' : 'warning');

        return back();
    }

    public function unlink(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('manageAccount', $employee);
        $this->accounts->unlink($employee);
        $this->toast('La cuenta se desvinculó del expediente.');

        return back();
    }
}
