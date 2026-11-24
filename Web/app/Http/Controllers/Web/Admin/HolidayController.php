<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Workforce\MexicanStates;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Holiday;
use App\Models\WorkCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Días festivos (Revisión RH, pantalla 08: «editable por RH»).
 *
 *  - Oficiales precargados del art. 74 (globales): NUNCA se eliminan ni se desactivan.
 *    RH puede anotarlos, ajustar el nombre o registrar el día movido por la ley; el
 *    cambio es un AJUSTE propio de la organización (`replaces_holiday_id`) con motivo
 *    obligatorio y bitácora. El global queda intacto para las demás organizaciones.
 *  - Descansos obligatorios variables (jornada electoral, art. 74 fr. IX, u otro que
 *    ordene la ley): RH los agrega por año como obligatorios; se editan y se dan de
 *    baja lógica con motivo.
 *  - Días de la empresa o locales: se agregan, editan, desactivan y reactivan.
 *
 * Los días obligatorios (oficiales) exigen `settings.legal_manage` (RH, normativa);
 * los de empresa y locales, `settings.manage`.
 */
class HolidayController extends AdminController
{
    use OrganizationRules;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $year = (int) $request->integer('year', (int) CarbonImmutable::today()->year);
        $year = max(2000, min(2100, $year));
        $org = $this->organizationId();

        $holidays = Holiday::query()->withTrashed()
            ->with('workCenter:id,name,state_code', 'createdBy:id,name', 'updatedBy:id,name', 'replaces:id,date,name')
            ->whereYear('date', $year)
            ->effectiveFor($org)
            ->orderBy('date')->get()
            ->map(fn (Holiday $h) => [
                'id' => $h->id, 'date' => $h->date->toDateString(), 'code' => $h->code, 'name' => $h->name, 'kind' => $h->kind,
                'origin' => $this->origin($h),
                'is_mandatory_rest' => $h->is_mandatory_rest, 'legal_basis' => $h->legal_basis,
                'notes' => $h->notes, 'change_reason' => $h->change_reason,
                'original' => $h->replaces ? ['date' => $h->replaces->date->toDateString(), 'name' => $h->replaces->name] : null,
                'work_center_id' => $h->work_center_id, 'work_center' => $h->workCenter?->name,
                'state' => MexicanStates::ALL[$h->workCenter->state_code ?? ''] ?? null,
                'editable' => $h->organization_id === null || $h->organization_id === $org,
                // Los precargados del art. 74 (y sus ajustes) nunca se desactivan.
                'can_deactivate' => $h->organization_id === $org && ! $h->isOfficialAdjustment(),
                'created_by' => $h->createdBy?->name,
                'updated_by' => $h->updatedBy?->name,
                'deleted' => $h->trashed(),
            ]);

        $years = Holiday::query()->effectiveFor($org)
            ->selectRaw('YEAR(date) as y')->distinct()->orderBy('y')->pluck('y')->map(fn ($y) => (int) $y)->all();

        $user = $request->user();

        return Inertia::render('configuracion/Holidays', [
            'year' => $year,
            'years' => array_values(array_unique([...$years, $year, (int) CarbonImmutable::today()->year])),
            'holidays' => $holidays->values(),
            'work_centers' => WorkCenter::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'state_code'])
                ->map(fn (WorkCenter $w) => ['value' => (string) $w->id, 'label' => $w->name, 'description' => MexicanStates::ALL[$w->state_code ?? ''] ?? null])->values(),
            'can' => [
                'manage' => $user?->can('settings.manage') ?? false,
                'manage_mandatory' => $user?->can('settings.legal_manage') ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $this->authorizeKind($request, $data['kind']);
        $code = $this->code($data['name']);
        if (Holiday::query()->withTrashed()->where('organization_id', $this->organizationId())->where('work_center_id', $data['work_center_id'])->whereDate('date', $data['date'])->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['date' => 'Ya existe ese festivo en la fecha indicada.']);
        }

        $holiday = Holiday::query()->create($data + [
            'organization_id' => $this->organizationId(),
            'code' => $code,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $this->audit->log('holiday.created', $holiday, "Festivo agregado: {$holiday->name} ({$holiday->date->format('d/m/Y')})", [
            'kind' => $holiday->kind, 'work_center_id' => $holiday->work_center_id, 'reason' => $holiday->change_reason,
        ], after: $this->snapshot($holiday));
        $this->toast('Festivo agregado.');

        return back();
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->ensureVisible($holiday);

        if ($holiday->isPreloadedOfficial() || $holiday->isOfficialAdjustment()) {
            return $this->adjustOfficial($request, $holiday);
        }

        $data = $this->validated($request, $holiday);
        $this->authorizeKind($request, $holiday->kind);
        $this->authorizeKind($request, $data['kind']);
        $before = $this->snapshot($holiday);
        $holiday->fill($data + ['code' => $this->code($data['name']), 'updated_by' => $request->user()?->id]);
        $changes = $holiday->getDirty();
        $holiday->save();

        $this->audit->log('holiday.updated', $holiday, "Festivo actualizado: {$holiday->name}", ['changed' => $changes, 'reason' => $holiday->change_reason], before: $before, after: $this->snapshot($holiday));
        $this->toast('Festivo actualizado.');

        return back();
    }

    public function deactivate(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->ensureOwn($holiday);
        if ($holiday->isOfficialAdjustment()) {
            throw ValidationException::withMessages(['holiday' => 'Los días de descanso obligatorio del art. 74 no se eliminan ni se desactivan. Edita el día si la ley lo movió.']);
        }
        $this->authorizeKind($request, $holiday->kind);
        // Dar de baja un día obligatorio capturado por RH exige motivo.
        $reason = $this->reason($request, $holiday->kind === Holiday::KIND_OFFICIAL);

        $holiday->forceFill(['change_reason' => $reason ?? $holiday->change_reason, 'updated_by' => $request->user()?->id])->save();
        $holiday->delete();
        $this->audit->log('holiday.deactivated', $holiday, "Festivo desactivado: {$holiday->name}", ['reason' => $reason]);
        $this->toast('Festivo desactivado.');

        return back();
    }

    public function restore(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->ensureOwn($holiday);
        $this->authorizeKind($request, $holiday->kind);
        $holiday->restore();
        $this->audit->log('holiday.restored', $holiday, "Festivo reactivado: {$holiday->name}");
        $this->toast('Festivo reactivado.');

        return back();
    }

    /**
     * Ajuste de la organización a un día oficial precargado: anotación, nombre o día
     * movido por la ley. Sigue siendo descanso obligatorio y del mismo año. Motivo obligatorio.
     */
    private function adjustOfficial(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorizeKind($request, Holiday::KIND_OFFICIAL);
        $org = $this->organizationId();
        $original = $holiday->isOfficialAdjustment() ? $holiday->replaces()->firstOrFail() : $holiday;

        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:255'],
            'change_reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['date' => 'fecha', 'name' => 'nombre', 'notes' => 'anotación', 'change_reason' => 'motivo del cambio']);
        $date = CarbonImmutable::parse($data['date']);
        if ($date->year !== $original->date->year) {
            throw ValidationException::withMessages(['date' => 'El día movido debe quedar en el mismo año ('.$original->date->year.').']);
        }

        $adjustment = DB::transaction(function () use ($original, $org, $data, $date, $request) {
            $adjustment = Holiday::query()->withTrashed()->where('organization_id', $org)->where('replaces_holiday_id', $original->id)->lockForUpdate()->first()
                ?? new Holiday([
                    'organization_id' => $org,
                    'replaces_holiday_id' => $original->id,
                    'work_center_id' => null,
                    'code' => $original->code,
                    'kind' => Holiday::KIND_OFFICIAL,
                    'legal_basis' => $original->legal_basis,
                    'created_by' => $request->user()?->id,
                ]);
            $before = $adjustment->exists ? $this->snapshot($adjustment) : $this->snapshot($original);
            $adjustment->fill([
                'date' => $date->toDateString(),
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
                'change_reason' => $data['change_reason'],
                // Un día del art. 74 nunca deja de ser descanso obligatorio.
                'is_mandatory_rest' => true,
                'updated_by' => $request->user()?->id,
            ]);
            $adjustment->save();
            if ($adjustment->trashed()) {
                $adjustment->restore();
            }

            $moved = $date->toDateString() !== $original->date->toDateString();
            $this->audit->log($moved ? 'holiday.official_moved' : 'holiday.official_adjusted', $adjustment,
                ($moved ? 'Día oficial movido: ' : 'Día oficial ajustado: ')."{$original->name} ({$original->date->format('d/m/Y')})",
                ['reason' => $data['change_reason'], 'original_holiday_id' => $original->id], before: $before, after: $this->snapshot($adjustment));

            return $adjustment;
        });

        $this->toast($adjustment->date->toDateString() !== $original->date->toDateString() ? 'Día movido.' : 'Día oficial actualizado.');

        return back();
    }

    /**
     * Visible para la organización: global o propio.
     */
    private function ensureVisible(Holiday $holiday): void
    {
        abort_unless($holiday->organization_id === null || $holiday->organization_id === $this->organizationId(), 404);
    }

    /**
     * Propio de la organización. Los oficiales precargados (globales) nunca se desactivan.
     */
    private function ensureOwn(Holiday $holiday): void
    {
        if ($holiday->isPreloadedOfficial()) {
            throw ValidationException::withMessages(['holiday' => 'Los días de descanso obligatorio del art. 74 no se eliminan ni se desactivan.']);
        }
        abort_unless($holiday->organization_id === $this->organizationId(), 404);
    }

    /**
     * Días obligatorios: sólo RH con normativa (`settings.legal_manage`); los demás,
     * `settings.manage` (ya exigido por la ruta).
     */
    private function authorizeKind(Request $request, string $kind): void
    {
        if ($kind === Holiday::KIND_OFFICIAL) {
            abort_unless($request->user()?->can('settings.legal_manage') ?? false, 403, 'Sólo RH administra los días de descanso obligatorio.');
        }
    }

    private function reason(Request $request, bool $required): ?string
    {
        $data = $request->validate([
            'reason' => [$required ? 'required' : 'nullable', 'string', 'min:5', 'max:255'],
        ], [], ['reason' => 'motivo']);

        return isset($data['reason']) ? (string) $data['reason'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Holiday $holiday): array
    {
        $official = $request->input('kind') === Holiday::KIND_OFFICIAL;
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:160'],
            // Un registro existente conserva su naturaleza: obligatorio ↔ empresa/local no se cruzan.
            'kind' => ['required', Rule::in($holiday === null
                ? [Holiday::KIND_OFFICIAL, Holiday::KIND_COMPANY, Holiday::KIND_LOCAL]
                : ($holiday->kind === Holiday::KIND_OFFICIAL ? [Holiday::KIND_OFFICIAL] : [Holiday::KIND_COMPANY, Holiday::KIND_LOCAL]))],
            'work_center_id' => [Rule::requiredIf($request->input('kind') === Holiday::KIND_LOCAL), 'nullable', 'integer', $this->existsInOrg('work_centers')],
            'is_mandatory_rest' => [$official ? 'nullable' : 'required', 'boolean'],
            'legal_basis' => [$official ? 'required' : 'nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:255'],
            // Los días obligatorios exigen motivo al crearse o cambiar (bitácora).
            'change_reason' => [$official ? 'required' : 'nullable', 'string', 'min:5', 'max:255'],
        ], [
            'work_center_id.required' => 'Un festivo local requiere el centro de trabajo al que aplica.',
            'legal_basis.required' => 'Indica el fundamento (p. ej. LFT art. 74 fr. IX, jornada electoral).',
        ], [
            'date' => 'fecha', 'name' => 'nombre', 'kind' => 'tipo', 'work_center_id' => 'centro de trabajo', 'legal_basis' => 'fundamento',
            'notes' => 'anotación', 'change_reason' => 'motivo',
        ]);

        if ($data['kind'] === Holiday::KIND_COMPANY) {
            $data['work_center_id'] = null;
        }
        if ($data['kind'] === Holiday::KIND_OFFICIAL) {
            // Descanso obligatorio por ley (art. 74): siempre obligatorio; local si sólo aplica a un centro.
            $data['is_mandatory_rest'] = true;
            $data['work_center_id'] ??= null;
        }
        $data['date'] = CarbonImmutable::parse($data['date'])->toDateString();
        $data['notes'] ??= null;
        $data['change_reason'] ??= null;

        return $data;
    }

    /**
     * Origen para la pantalla: precargado, ajustado, obligatorio capturado por RH, empresa o local.
     */
    private function origin(Holiday $holiday): string
    {
        return match (true) {
            $holiday->isPreloadedOfficial() => 'preloaded',
            $holiday->isOfficialAdjustment() => 'adjusted',
            $holiday->kind === Holiday::KIND_OFFICIAL => 'variable',
            default => $holiday->kind,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Holiday $holiday): array
    {
        return [
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'kind' => $holiday->kind,
            'is_mandatory_rest' => (bool) $holiday->is_mandatory_rest,
            'work_center_id' => $holiday->work_center_id,
            'legal_basis' => $holiday->legal_basis,
            'notes' => $holiday->notes,
        ];
    }

    private function code(string $name): string
    {
        return Str::upper(Str::limit(Str::slug($name, '_'), 40, ''));
    }
}
