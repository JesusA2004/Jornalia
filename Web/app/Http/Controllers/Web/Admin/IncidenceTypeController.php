<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Incidences\IncidenceCatalog;
use App\Domain\Integrations\People\HttpPeopleIncidenceSource;
use App\Domain\Integrations\People\PeopleIncidenceReceiver;
use App\Domain\Rectifications\RectificationSettings;
use App\Models\IncidenceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración › Incidencias: catálogo CERRADO (PDF pantalla 06). Sólo RH
 * (`settings.incidences`) ajusta evidencia, folio, ruta de autorización,
 * vigencia y código de People; no se crean ni se borran tipos. También guarda
 * los parámetros de rectificaciones (plazo de firma y cierre de nómina).
 */
class IncidenceTypeController extends AdminController
{
    public const CATEGORIES = [
        'absence' => 'Ausencia', 'leave' => 'Permiso', 'disability' => 'Incapacidad',
        'vacation' => 'Vacaciones', 'time' => 'Omisión de checada', 'other' => 'Otra',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, RectificationSettings $settings, PeopleIncidenceReceiver $people, HttpPeopleIncidenceSource $source): Response
    {
        $order = array_flip(IncidenceCatalog::codes());
        $user = $request->user();

        return Inertia::render('configuracion/Incidences', [
            'types' => IncidenceType::query()->withTrashed()->withCount('incidences')->whereIn('code', IncidenceCatalog::codes())->get()
                ->sortBy(fn (IncidenceType $t) => $order[$t->code] ?? 99)->values()
                ->map(fn (IncidenceType $t) => [
                    'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'category' => $t->category,
                    'category_label' => self::CATEGORIES[$t->category] ?? $t->category, 'description' => $t->description,
                    'requires_evidence' => (bool) $t->requires_evidence, 'evidence_kind' => $t->evidence_kind,
                    'requires_folio' => (bool) $t->requires_folio, 'approval_route' => $t->approval_route,
                    'employee_requestable' => (bool) $t->employee_requestable, 'external_code' => $t->external_code,
                    'is_omission' => $t->isOmission(), 'active' => (bool) $t->active && ! $t->trashed(),
                    'incidences_count' => $t->incidences_count,
                ]),
            'evidence_kinds' => $this->pairs(IncidenceCatalog::EVIDENCE_KINDS),
            'approval_routes' => $this->pairs(IncidenceCatalog::APPROVAL_ROUTES),
            'rectifications' => $settings->all(),
            'people' => $people->status($source),
            'can' => [
                'manage' => $user?->can('settings.incidences') ?? false,
                'sync' => $user?->can('integrations.sync') ?? false,
            ],
        ]);
    }

    public function update(Request $request, IncidenceType $incidenceType): RedirectResponse
    {
        $this->ensureOwned($incidenceType);
        $this->ensureCatalog($incidenceType);

        $data = $request->validate([
            'requires_evidence' => ['required', 'boolean'],
            'evidence_kind' => ['nullable', Rule::in(array_keys(IncidenceCatalog::EVIDENCE_KINDS))],
            'requires_folio' => ['required', 'boolean'],
            'approval_route' => ['required', Rule::in(array_keys(IncidenceCatalog::APPROVAL_ROUTES))],
            'employee_requestable' => ['required', 'boolean'],
            'external_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ], [], ['evidence_kind' => 'evidencia', 'approval_route' => 'autorización', 'external_code' => 'código en People']);

        if ($data['requires_evidence'] && blank($data['evidence_kind'])) {
            throw ValidationException::withMessages(['evidence_kind' => 'Indica el tipo de evidencia.']);
        }
        // La omisión de checada siempre exige evidencia: se resuelve con rectificación firmada.
        if ($incidenceType->isOmission()) {
            $data['requires_evidence'] = true;
            $data['requires_folio'] = false;
        }

        $incidenceType->fill($data);
        $changes = $incidenceType->getDirty();
        $original = array_intersect_key($incidenceType->getOriginal(), $changes);
        $incidenceType->save();
        $this->audit->log('incidence_type.updated', $incidenceType, "Tipo de incidencia ajustado: {$incidenceType->name}", ['before' => $original, 'after' => $changes]);
        $this->toast('Tipo de incidencia actualizado.');

        return back();
    }

    public function deactivate(Request $request, IncidenceType $incidenceType): RedirectResponse
    {
        $this->ensureOwned($incidenceType);
        $this->ensureCatalog($incidenceType);
        $incidenceType->forceFill(['active' => false])->save();
        $this->audit->log('incidence_type.deactivated', $incidenceType, "Tipo de incidencia desactivado: {$incidenceType->name}");
        $this->toast('Tipo desactivado. El historial se conserva.');

        return back();
    }

    public function restore(Request $request, IncidenceType $incidenceType): RedirectResponse
    {
        $this->ensureOwned($incidenceType);
        $this->ensureCatalog($incidenceType);
        if ($incidenceType->trashed()) {
            $incidenceType->restore();
        }
        $incidenceType->forceFill(['active' => true, 'valid_to' => null])->save();
        $this->audit->log('incidence_type.restored', $incidenceType, "Tipo de incidencia reactivado: {$incidenceType->name}");
        $this->toast('Tipo reactivado.');

        return back();
    }

    /**
     * Plazo de firma (días hábiles) y fecha de cierre de nómina.
     */
    public function updateRectifications(Request $request, RectificationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'business_days' => ['required', 'integer', 'min:1', 'max:15'],
            'confirmed_by_hr' => ['required', 'boolean'],
            'closed_through' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [], ['business_days' => 'plazo de firma', 'closed_through' => 'cierre de nómina']);

        $before = $settings->all();
        $settings->save([
            'business_days' => (int) $data['business_days'],
            'confirmed_by_hr' => (bool) $data['confirmed_by_hr'],
            'closed_through' => $data['closed_through'] ?? null,
        ], $request->user()?->id);
        $this->audit->log('rectifications.settings', null, 'Parámetros de rectificaciones actualizados', ['before' => $before, 'after' => $data]);
        $this->toast('Parámetros guardados.');

        return back();
    }

    public function syncPeople(Request $request, PeopleIncidenceReceiver $people, HttpPeopleIncidenceSource $source): RedirectResponse
    {
        $run = $people->run($source, $request->user());
        if ($run === null) {
            $this->toast('People sin configurar.', 'warning');

            return back();
        }
        $stats = $run->stats ?? [];
        $this->toast(match ($run->status) {
            'failed' => 'No se pudo conectar con People.',
            'partial' => sprintf('Recibidas %d; %d con error.', $stats['created'] ?? 0, $stats['errors'] ?? 0),
            default => sprintf('Recibidas %d nuevas.', $stats['created'] ?? 0),
        }, in_array($run->status, ['failed', 'partial'], true) ? 'warning' : 'success');

        return back();
    }

    private function ensureCatalog(IncidenceType $type): void
    {
        if (! in_array($type->code, IncidenceCatalog::codes(), true)) {
            throw ValidationException::withMessages(['type' => 'El tipo no pertenece al catálogo vigente.']);
        }
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value:string, label:string}>
     */
    private function pairs(array $map): array
    {
        return array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($map), array_values($map));
    }
}
