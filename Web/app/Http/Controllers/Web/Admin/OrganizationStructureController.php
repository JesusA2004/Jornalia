<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Workforce\MexicanStates;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Geofence;
use App\Models\LegalEntity;
use App\Models\Position;
use App\Models\WorkCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Alta, edición y baja de catálogos de estructura. Los registros con
 * historial (asignaciones, sucursales, políticas) no se eliminan: se
 * desactivan para conservar la trazabilidad.
 */
class OrganizationStructureController extends AdminController
{
    use OrganizationRules;

    /** Radio permitido de geocerca (m) y rango sugerido por RH (PDF pantalla 17). */
    public const RADIUS_MIN = 30;

    public const RADIUS_MAX = 1000;

    public const RADIUS_SUGGESTED_MIN = 100;

    public const RADIUS_SUGGESTED_MAX = 150;

    public const ENTITY_PATTERN = 'razones-sociales|centros-trabajo|sucursales|departamentos|puestos|geocercas';

    /** @var array<string, array{model: class-string<Model>, label: string, tab: string, title: string}> */
    private const ENTITIES = [
        'razones-sociales' => ['model' => LegalEntity::class, 'label' => 'Razón social', 'tab' => 'legal-entities', 'title' => 'legal_name'],
        'centros-trabajo' => ['model' => WorkCenter::class, 'label' => 'Centro de trabajo', 'tab' => 'work-centers', 'title' => 'name'],
        'sucursales' => ['model' => Branch::class, 'label' => 'Sucursal', 'tab' => 'branches', 'title' => 'name'],
        'departamentos' => ['model' => Department::class, 'label' => 'Departamento', 'tab' => 'departments', 'title' => 'name'],
        'puestos' => ['model' => Position::class, 'label' => 'Puesto', 'tab' => 'positions', 'title' => 'name'],
        'geocercas' => ['model' => Geofence::class, 'label' => 'Geocerca', 'tab' => 'geofences', 'title' => 'name'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, string $entity): RedirectResponse
    {
        $def = self::ENTITIES[$entity];
        $data = $this->validated($request, $entity, null);

        /** @var Model $model */
        $model = $def['model']::query()->create($data);
        $this->audit->log('organization.'.$def['tab'].'.created', $model, "{$def['label']} registrado(a): {$model->getAttribute($def['title'])}", $this->safeContext($data));
        $this->toastWithWarnings($entity, $model, "«{$model->getAttribute($def['title'])}» se registró correctamente.");

        return back();
    }

    public function update(Request $request, string $entity, int $id): RedirectResponse
    {
        $def = self::ENTITIES[$entity];
        /** @var Model $model */
        $model = $def['model']::query()->findOrFail($id);
        $this->ensureOwned($model);
        $data = $this->validated($request, $entity, $model);

        $model->fill($data);
        $changes = $model->getDirty();
        $model->save();

        $this->audit->log('organization.'.$def['tab'].'.updated', $model, "{$def['label']} actualizado(a): {$model->getAttribute($def['title'])}", ['changed' => $this->safeContext($changes)]);
        $this->toastWithWarnings($entity, $model, "Cambios guardados en «{$model->getAttribute($def['title'])}».");

        return back();
    }

    public function deactivate(Request $request, string $entity, int $id): RedirectResponse
    {
        $def = self::ENTITIES[$entity];
        /** @var Model $model */
        $model = $def['model']::query()->findOrFail($id);
        $this->ensureOwned($model);
        $title = (string) $model->getAttribute($def['title']);

        if (($blocker = $this->activeDependents($entity, $id)) !== null) {
            throw ValidationException::withMessages(['record' => "No se puede desactivar «{$title}»: {$blocker}."]);
        }

        DB::transaction(function () use ($model) {
            $model->forceFill(['active' => false])->save();
            $model->delete();
        });
        $this->audit->log('organization.'.$def['tab'].'.deactivated', $model, "{$def['label']} desactivado(a): {$title}");
        $this->toast("«{$title}» se desactivó. Su historial se conserva y puedes reactivarlo.");

        return back();
    }

    public function restore(Request $request, string $entity, int $id): RedirectResponse
    {
        $def = self::ENTITIES[$entity];
        /** @var Model $model */
        $model = $def['model']::query()->withTrashed()->findOrFail($id);
        $this->ensureOwned($model);

        DB::transaction(function () use ($model) {
            $model->restore();
            $model->forceFill(['active' => true])->save();
        });
        $title = (string) $model->getAttribute($def['title']);
        $this->audit->log('organization.'.$def['tab'].'.restored', $model, "{$def['label']} reactivado(a): {$title}");
        $this->toast("«{$title}» se reactivó.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $entity, ?Model $model): array
    {
        $ignore = $model?->getKey();
        $code = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'];

        $rules = match ($entity) {
            'razones-sociales' => [
                'legal_name' => ['required', 'string', 'max:200'],
                'rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $this->uniqueInOrg('legal_entities', 'rfc', $ignore)],
                'employer_registration' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]{8,20}$/'],
                'fiscal_address' => ['nullable', 'string', 'max:255'],
                'active' => ['boolean'],
            ],
            'centros-trabajo' => [
                'name' => ['required', 'string', 'max:160'],
                'code' => [...$code, $this->uniqueInOrg('work_centers', 'code', $ignore)],
                'legal_entity_id' => ['nullable', 'integer', $this->existsInOrg('legal_entities')],
                'address' => ['nullable', 'string', 'max:255'],
                'state_code' => ['nullable', Rule::in(array_keys(MexicanStates::ALL))],
                'timezone' => ['required', 'timezone:all'],
                'active' => ['boolean'],
            ],
            'sucursales' => [
                'name' => ['required', 'string', 'max:160'],
                'code' => [...$code, $this->uniqueInOrg('branches', 'code', $ignore)],
                'work_center_id' => ['nullable', 'integer', $this->existsInOrg('work_centers')],
                'region' => ['nullable', 'string', 'max:80'],
                'address' => ['nullable', 'string', 'max:255'],
                'employer_registration' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]{8,20}$/'],
                'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
                'active' => ['boolean'],
            ],
            'departamentos' => [
                'name' => ['required', 'string', 'max:160'],
                'code' => [...$code, $this->uniqueInOrg('departments', 'code', $ignore)],
                'parent_id' => ['nullable', 'integer', $this->existsInOrg('departments')],
                'active' => ['boolean'],
            ],
            'puestos' => [
                'name' => ['required', 'string', 'max:160'],
                'code' => [...$code, $this->uniqueInOrg('positions', 'code', $ignore)],
                'department_id' => ['nullable', 'integer', $this->existsInOrg('departments')],
                'active' => ['boolean'],
            ],
            'geocercas' => [
                'name' => ['required', 'string', 'max:120'],
                'branch_id' => ['nullable', 'integer', $this->existsInOrg('branches')],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'radius_meters' => ['required', 'integer', 'min:'.self::RADIUS_MIN, 'max:'.self::RADIUS_MAX],
                'active' => ['boolean'],
            ],
        };

        if (in_array($entity, ['razones-sociales', 'sucursales', 'departamentos', 'puestos', 'centros-trabajo'], true)) {
            $request->merge(array_filter([
                'rfc' => $request->filled('rfc') ? strtoupper(trim((string) $request->input('rfc'))) : null,
                'code' => $request->filled('code') ? strtoupper(trim((string) $request->input('code'))) : null,
                'employer_registration' => $request->filled('employer_registration') ? strtoupper(trim((string) $request->input('employer_registration'))) : null,
            ], fn ($v) => $v !== null));
        }

        $data = $request->validate($rules, [
            'rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres para persona moral, 13 para física).',
            'rfc.unique' => 'Ya existe una razón social con ese RFC.',
            'code.unique' => 'La clave ya está en uso.',
            'code.regex' => 'La clave sólo admite letras, números, punto, guion y guion bajo.',
            'radius_meters.min' => 'El radio mínimo es de '.self::RADIUS_MIN.' m: la precisión del GPS en teléfonos rara vez es mejor.',
            'radius_meters.max' => 'El radio máximo es de '.self::RADIUS_MAX.' m.',
            'employer_registration.regex' => 'El registro patronal sólo admite letras y números (8 a 20).',
        ], [
            'legal_name' => 'razón social', 'employer_registration' => 'registro patronal', 'fiscal_address' => 'domicilio fiscal',
            'name' => 'nombre', 'code' => 'clave', 'legal_entity_id' => 'razón social', 'address' => 'domicilio', 'state_code' => 'entidad federativa',
            'timezone' => 'zona horaria', 'work_center_id' => 'centro de trabajo', 'region' => 'región', 'latitude' => 'latitud', 'longitude' => 'longitud',
            'parent_id' => 'departamento superior', 'department_id' => 'departamento', 'branch_id' => 'sucursal', 'radius_meters' => 'radio',
        ]);

        if ($entity === 'departamentos' && $model !== null && ! empty($data['parent_id'])) {
            $this->assertNoCycle((int) $model->getKey(), (int) $data['parent_id']);
        }

        $data['active'] = (bool) ($data['active'] ?? true);

        return $data;
    }

    /**
     * Geocerca fuera del radio sugerido por RH (100–150 m): se permite, pero se advierte.
     */
    private function toastWithWarnings(string $entity, Model $model, string $message): void
    {
        $radius = (int) $model->getAttribute('radius_meters');
        $warning = $entity === 'geocercas' && ($radius < self::RADIUS_SUGGESTED_MIN || $radius > self::RADIUS_SUGGESTED_MAX)
            ? 'el radio sugerido es de '.self::RADIUS_SUGGESTED_MIN.' a '.self::RADIUS_SUGGESTED_MAX.' m.'
            : null;
        $this->toast($warning ? $message.' Atención: '.$warning : $message, $warning ? 'warning' : 'success');
    }

    private function assertNoCycle(int $id, int $parentId): void
    {
        $seen = [];
        $current = $parentId;
        while ($current !== 0) {
            if ($current === $id || isset($seen[$current])) {
                throw ValidationException::withMessages(['parent_id' => 'El departamento superior no puede ser el mismo departamento ni uno de sus subdepartamentos.']);
            }
            $seen[$current] = true;
            $current = (int) Department::query()->whereKey($current)->value('parent_id');
        }
    }

    /**
     * Describe por qué no puede desactivarse (dependencias vigentes) o null.
     */
    private function activeDependents(string $entity, int $id): ?string
    {
        $today = now()->toDateString();
        $assignments = fn (string $column) => DB::table('employment_assignments')->where($column, $id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->count();
        $active = fn (string $table, string $column) => DB::table($table)->where($column, $id)->whereNull('deleted_at')->count();

        $checks = match ($entity) {
            'razones-sociales' => [[$active('work_centers', 'legal_entity_id'), 'tiene centros de trabajo activos'], [DB::table('employments')->where('legal_entity_id', $id)->where('status', 'active')->count(), 'tiene relaciones laborales vigentes']],
            'centros-trabajo' => [[$active('branches', 'work_center_id'), 'tiene sucursales activas'], [$assignments('work_center_id'), 'tiene colaboradores asignados']],
            'sucursales' => [[$assignments('branch_id'), 'tiene colaboradores asignados']],
            'departamentos' => [[$assignments('department_id'), 'tiene colaboradores asignados'], [$active('departments', 'parent_id'), 'tiene subdepartamentos activos']],
            'puestos' => [[$assignments('position_id'), 'tiene colaboradores asignados']],
            'geocercas' => [[DB::table('attendance_policy_geofence as pg')->join('attendance_policies as ap', 'ap.id', '=', 'pg.attendance_policy_id')->whereNull('ap.deleted_at')->where('pg.geofence_id', $id)->count(), 'está asociada a políticas de asistencia activas']],
        };

        foreach ($checks as [$count, $message]) {
            if ($count > 0) {
                return $message." ({$count})";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function safeContext(array $data): array
    {
        return array_map(fn ($v) => is_scalar($v) || $v === null ? $v : (string) json_encode($v), $data);
    }
}
