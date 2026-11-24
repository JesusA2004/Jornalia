<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Workforce\WorkforceService;
use App\Http\Requests\Web\Admin\EmploymentActionRequest;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * Baja, reingreso y movimientos de personal (relación laboral con vigencias).
 */
class EmploymentController extends AdminController
{
    public function __construct(private readonly WorkforceService $workforce) {}

    public function terminate(EmploymentActionRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();
        $this->workforce->terminate($this->actor($request), $employee, $data['ends_on'], $data['reason']);
        $this->toast('Baja registrada con fecha '.CarbonImmutable::parse($data['ends_on'])->format('d/m/Y').'. El historial se conserva.');

        return back();
    }

    public function rehire(EmploymentActionRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();
        $this->workforce->rehire(
            $this->actor($request),
            $employee,
            Arr::only($data, ['starts_on', 'seniority_starts_on', 'contract_type', 'work_modality', 'legal_entity_id']),
            Arr::only($data, WorkforceService::ASSIGNMENT_FIELDS),
        );
        $this->toast('Reingreso registrado como nueva relación laboral.');

        return back();
    }

    public function move(EmploymentActionRequest $request, Employee $employee): RedirectResponse
    {
        $changes = $request->changes();
        // Quien sólo puede asignar horarios no puede mover otros datos.
        if (! $request->user()?->can('update', $employee)) {
            abort_unless(array_keys($changes) === ['schedule_id'], 403);
        }

        $this->workforce->move($this->actor($request), $employee, (string) $request->validated('effective_from'), $changes, $request->validated('reason'));
        $message = 'Movimiento registrado con vigencia a partir del '.CarbonImmutable::parse((string) $request->validated('effective_from'))->format('d/m/Y').'.';
        $this->toast($message);

        return back();
    }
}
