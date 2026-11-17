<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\IncidenceType;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de incidencias. Las del sistema (`is_system`) son de sólo lectura;
 * las nuevas se registran con vigencia.
 */
class IncidenceTypeController extends AdminController
{
    use OrganizationRules;

    public const CATEGORIES = [
        'absence' => 'Ausencia', 'leave' => 'Permiso', 'disability' => 'Incapacidad',
        'vacation' => 'Vacaciones', 'time' => 'Tiempo', 'other' => 'Otra',
    ];

    public const EFFECTS = [
        'paid_absence' => 'Ausencia con goce', 'unpaid_absence' => 'Ausencia sin goce', 'justified' => 'Justificada',
        'unjustified' => 'Injustificada', 'informative' => 'Informativa', 'time_adjustment' => 'Ajuste de tiempo',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        return Inertia::render('configuracion/Incidences', [
            'types' => IncidenceType::query()->withTrashed()->withCount('incidences')->orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (IncidenceType $t) => [
                    'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'category' => $t->category, 'description' => $t->description,
                    'operational_effect' => $t->operational_effect, 'requires_evidence' => $t->requires_evidence, 'requires_approval' => $t->requires_approval,
                    'affects_payroll' => $t->affects_payroll, 'employee_requestable' => $t->employee_requestable, 'is_system' => $t->is_system,
                    'active' => $t->active, 'valid_from' => $t->valid_from?->toDateString(), 'valid_to' => $t->valid_to?->toDateString(),
                    'incidences_count' => $t->incidences_count,
                    'deleted' => $t->trashed(),
                ]),
            'categories' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(self::CATEGORIES), self::CATEGORIES),
            'effects' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(self::EFFECTS), self::EFFECTS),
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $type = IncidenceType::query()->create($data + ['is_system' => false]);
        $this->audit->log('incidence_type.created', $type, "Tipo de incidencia creado: {$type->name}", $data);
        $this->toast("Incidencia «{$type->name}» agregada al catálogo.");

        return back();
    }

    public function update(Request $request, IncidenceType $incidenceType): RedirectResponse
    {
        $this->ensureOwned($incidenceType);
        $this->ensureEditable($incidenceType);
        $data = $this->validated($request, $incidenceType);

        $incidenceType->fill($data);
        $changes = $incidenceType->getDirty();
        $incidenceType->save();
        $this->audit->log('incidence_type.updated', $incidenceType, "Tipo de incidencia actualizado: {$incidenceType->name}", ['changed' => $changes]);
        $this->toast('Tipo de incidencia actualizado.');

        return back();
    }

    public function deactivate(Request $request, IncidenceType $incidenceType): RedirectResponse
    {
        $this->ensureOwned($incidenceType);
        $this->ensureEditable($incidenceType);

        $incidenceType->forceFill(['active' => false, 'valid_to' => $incidenceType->valid_to ?? CarbonImmutable::today()])->save();
        $incidenceType->delete();
        $this->audit->log('incidence_type.deactivated', $incidenceType, "Tipo de incidencia desactivado: {$incidenceType->name}");
        $this->toast('Incidencia desactivada: se cerró su vigencia y el historial se conserva.');

        return back();
    }

    public function restore(Request $request, IncidenceType $incidenceType): RedirectResponse
    {
        $this->ensureOwned($incidenceType);
        $this->ensureEditable($incidenceType);
        $incidenceType->restore();
        $incidenceType->forceFill(['active' => true, 'valid_to' => null])->save();
        $this->audit->log('incidence_type.restored', $incidenceType, "Tipo de incidencia reactivado: {$incidenceType->name}");
        $this->toast('Incidencia reactivada.');

        return back();
    }

    private function ensureEditable(IncidenceType $type): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages(['type' => 'Las incidencias del sistema son de sólo lectura.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?IncidenceType $type): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/', $this->uniqueInOrg('incidence_types', 'code', $type?->id)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'operational_effect' => ['required', Rule::in(array_keys(self::EFFECTS))],
            'requires_evidence' => ['required', 'boolean'],
            'requires_approval' => ['required', 'boolean'],
            'affects_payroll' => ['required', 'boolean'],
            'employee_requestable' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ], [
            'code.regex' => 'La clave sólo admite mayúsculas, números y guion bajo.',
            'code.unique' => 'Ya existe una incidencia con esa clave.',
        ], [
            'code' => 'clave', 'name' => 'nombre', 'category' => 'categoría', 'operational_effect' => 'efecto',
            'valid_from' => 'inicio de vigencia', 'valid_to' => 'fin de vigencia',
        ]);
    }
}
