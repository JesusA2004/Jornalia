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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Días festivos. Los oficiales (LFT art. 74) son de sólo lectura; la empresa
 * puede agregar festivos propios o locales por centro de trabajo.
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
            ->with('workCenter:id,name,state_code', 'createdBy:id,name')
            ->whereYear('date', $year)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $org))
            ->orderBy('date')->get()
            ->map(fn (Holiday $h) => [
                'id' => $h->id, 'date' => $h->date->toDateString(), 'code' => $h->code, 'name' => $h->name, 'kind' => $h->kind,
                'is_mandatory_rest' => $h->is_mandatory_rest, 'legal_basis' => $h->legal_basis,
                'work_center_id' => $h->work_center_id, 'work_center' => $h->workCenter?->name,
                'state' => MexicanStates::ALL[$h->workCenter?->state_code ?? ''] ?? null,
                'editable' => $h->organization_id === $org && $h->kind !== 'official',
                'created_by' => $h->createdBy?->name,
                'deleted' => $h->trashed(),
            ]);

        $years = Holiday::query()->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $org))
            ->selectRaw('YEAR(date) as y')->distinct()->orderBy('y')->pluck('y')->map(fn ($y) => (int) $y)->all();

        return Inertia::render('configuracion/Holidays', [
            'year' => $year,
            'years' => array_values(array_unique([...$years, $year, (int) CarbonImmutable::today()->year])),
            'holidays' => $holidays->values(),
            'work_centers' => WorkCenter::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'state_code'])
                ->map(fn (WorkCenter $w) => ['value' => (string) $w->id, 'label' => $w->name, 'description' => MexicanStates::ALL[$w->state_code ?? ''] ?? null])->values(),
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $code = $this->code($data['name']);
        if (Holiday::query()->withTrashed()->where('organization_id', $this->organizationId())->where('work_center_id', $data['work_center_id'])->whereDate('date', $data['date'])->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['date' => 'Ya existe ese festivo en la fecha indicada.']);
        }

        $holiday = Holiday::query()->create($data + [
            'organization_id' => $this->organizationId(),
            'code' => $code,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->log('holiday.created', $holiday, "Festivo agregado: {$holiday->name} ({$holiday->date->format('d/m/Y')})", ['kind' => $holiday->kind, 'work_center_id' => $holiday->work_center_id]);
        $this->toast('Festivo agregado.');

        return back();
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->ensureEditable($holiday);
        $data = $this->validated($request);
        $holiday->fill($data + ['code' => $this->code($data['name'])]);
        $changes = $holiday->getDirty();
        $holiday->save();

        $this->audit->log('holiday.updated', $holiday, "Festivo actualizado: {$holiday->name}", ['changed' => $changes]);
        $this->toast('Festivo actualizado.');

        return back();
    }

    public function deactivate(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->ensureEditable($holiday);
        $holiday->delete();
        $this->audit->log('holiday.deactivated', $holiday, "Festivo desactivado: {$holiday->name}");
        $this->toast('Festivo desactivado.');

        return back();
    }

    public function restore(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->ensureEditable($holiday);
        $holiday->restore();
        $this->audit->log('holiday.restored', $holiday, "Festivo reactivado: {$holiday->name}");
        $this->toast('Festivo reactivado.');

        return back();
    }

    private function ensureEditable(Holiday $holiday): void
    {
        abort_unless($holiday->organization_id === $this->organizationId(), 404);
        if ($holiday->kind === 'official') {
            throw ValidationException::withMessages(['holiday' => 'Los festivos oficiales son de sólo lectura (LFT art. 74).']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(['company', 'local'])],
            'work_center_id' => [Rule::requiredIf($request->input('kind') === 'local'), 'nullable', 'integer', $this->existsInOrg('work_centers')],
            'is_mandatory_rest' => ['required', 'boolean'],
            'legal_basis' => ['nullable', 'string', 'max:160'],
        ], ['work_center_id.required' => 'Un festivo local requiere el centro de trabajo al que aplica.'], [
            'date' => 'fecha', 'name' => 'nombre', 'kind' => 'tipo', 'work_center_id' => 'centro de trabajo', 'legal_basis' => 'fundamento',
        ]);

        if ($data['kind'] === 'company') {
            $data['work_center_id'] = $data['work_center_id'] ?? null;
        }
        $data['date'] = CarbonImmutable::parse($data['date'])->toDateString();

        return $data;
    }

    private function code(string $name): string
    {
        return Str::upper(Str::limit(Str::slug($name, '_'), 40, ''));
    }
}
