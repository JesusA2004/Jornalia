<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\OfficialCompanyData;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\WorkCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carga de datos oficiales y alta de cada sucursal como centro de trabajo
 * (revisión RH, pantalla 17). Nunca inventa valores: sólo usa los datos
 * oficiales de la Constancia de Situación Fiscal y los de la propia sucursal.
 */
class OrganizationSetupController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Llena los datos oficiales vacíos. Si hay valores capturados distintos, avisa
     * y sólo los sobrescribe con confirmación explícita (`force`).
     */
    public function officialData(Request $request, OfficialCompanyData $official): RedirectResponse
    {
        $force = $request->boolean('force');
        $result = $official->apply(Organization::query()->findOrFail($this->organizationId()), $force);

        if ($result['status'] === 'skipped') {
            throw ValidationException::withMessages(['official' => $result['message']]);
        }
        if ($result['status'] === 'conflict') {
            $fields = implode(', ', array_map(fn ($c) => $this->label($c['field']), $result['conflicts']));
            $this->toast("Hay datos capturados distintos ({$fields}). Revísalos y confirma para reemplazarlos.", 'warning');

            return back();
        }

        $this->toast($result['message']);

        return back();
    }

    /**
     * Registra la sucursal como centro de trabajo propio (mismo nombre, clave y
     * domicilio) y la vincula. Si ya tiene centro, no hace nada.
     */
    public function branchWorkCenter(Request $request, int $id): RedirectResponse
    {
        $branch = Branch::query()->findOrFail($id);
        $this->ensureOwned($branch);

        if ($branch->work_center_id !== null) {
            $this->toast('La sucursal ya es un centro de trabajo.');

            return back();
        }

        $legalEntityId = LegalEntity::query()->where('rfc', OfficialCompanyData::RFC)->value('id')
            ?? (LegalEntity::query()->count() === 1 ? LegalEntity::query()->value('id') : null);

        $center = DB::transaction(function () use ($branch, $legalEntityId) {
            $code = $branch->code;
            if (WorkCenter::query()->withTrashed()->where('code', $code)->exists()) {
                $code = mb_substr($branch->code, 0, 36).'-CT';
                if (WorkCenter::query()->withTrashed()->where('code', $code)->exists()) {
                    throw ValidationException::withMessages(['work_center' => "Ya existe un centro de trabajo con la clave {$branch->code}: vincúlalo editando la sucursal."]);
                }
            }
            $center = WorkCenter::query()->create([
                'name' => $branch->name,
                'code' => $code,
                'address' => $branch->address,
                'legal_entity_id' => $legalEntityId,
                'timezone' => Organization::query()->whereKey($this->organizationId())->value('timezone') ?? 'America/Mexico_City',
                'active' => true,
            ]);
            $branch->forceFill(['work_center_id' => $center->id])->save();

            return $center;
        });

        $this->audit->log('organization.branches.work_center_created', $branch, "Sucursal registrada como centro de trabajo: {$branch->name}", ['work_center_id' => $center->id]);
        $this->toast('Sucursal registrada como centro de trabajo. Completa entidad federativa y registro patronal.');

        return back();
    }

    private function label(string $field): string
    {
        return match ($field) {
            'legal_name' => 'razón social',
            'rfc' => 'RFC',
            'fiscal_address' => 'domicilio fiscal',
            'name' => 'nombre comercial',
            default => $field,
        };
    }
}
