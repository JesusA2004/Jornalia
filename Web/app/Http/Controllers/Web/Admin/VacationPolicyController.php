<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Legal\LegalFramework;
use App\Domain\Vacations\VacationService;
use App\Models\VacationEntitlementRule;
use App\Models\VacationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Políticas de vacaciones y prestaciones superiores. Nunca inferiores al
 * mínimo legal (LFT arts. 76 y 80): los días se suman al mínimo y la prima
 * vacacional no puede ser menor al porcentaje legal vigente.
 */
class VacationPolicyController extends AdminController
{
    public function __construct(
        private readonly VacationService $vacations,
        private readonly LegalFramework $law,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today()->toDateString();
        $legal = VacationEntitlementRule::query()
            ->whereDate('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
            ->orderBy('from_year')->get(['from_year', 'to_year', 'days', 'source', 'code']);

        return Inertia::render('configuracion/Vacations', [
            'policies' => VacationPolicy::query()->withTrashed()->orderByDesc('is_default')->orderByDesc('valid_from')->get()
                ->map(fn (VacationPolicy $p) => [
                    'id' => $p->id, 'name' => $p->name, 'extra_days' => array_values((array) $p->extra_days),
                    'premium_percent' => (string) $p->premium_percent, 'min_notice_days' => (int) $p->min_notice_days,
                    'allow_half_days' => $p->allow_half_days, 'valid_from' => $p->valid_from->toDateString(),
                    'valid_to' => $p->valid_to?->toDateString(), 'is_default' => $p->is_default, 'deleted' => $p->trashed(),
                ]),
            'legal_table' => $legal->map(fn ($r) => ['from_year' => (int) $r->from_year, 'to_year' => $r->to_year !== null ? (int) $r->to_year : null, 'days' => (int) $r->days, 'source' => $r->source])->values(),
            'legal_premium_percent' => $this->law->for($today)->vacationPremiumMinPercent,
            'can' => ['manage' => $request->user()?->can('settings.manage') ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $policy = DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                VacationPolicy::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return VacationPolicy::query()->create($data);
        });

        $this->audit->log('vacation_policy.created', $policy, "Política de vacaciones creada: {$policy->name}", Arr::except($data, ['name']));
        $this->toast("Política «{$policy->name}» creada.");

        return back();
    }

    public function update(Request $request, VacationPolicy $vacationPolicy): RedirectResponse
    {
        $this->ensureOwned($vacationPolicy);
        $data = $this->validated($request);
        if ($vacationPolicy->is_default && ! $data['is_default']) {
            throw ValidationException::withMessages(['is_default' => 'Debe existir una política por defecto. Marca otra como predeterminada.']);
        }

        $before = Arr::except($vacationPolicy->toArray(), ['created_at', 'updated_at']);
        DB::transaction(function () use ($vacationPolicy, $data) {
            if ($data['is_default']) {
                VacationPolicy::query()->whereKeyNot($vacationPolicy->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $vacationPolicy->update($data);
        });

        $this->audit->log('vacation_policy.updated', $vacationPolicy, "Política de vacaciones actualizada: {$vacationPolicy->name}", ['before' => $before, 'after' => $data]);
        $this->toast('Política de vacaciones actualizada.');

        return back();
    }

    public function deactivate(Request $request, VacationPolicy $vacationPolicy): RedirectResponse
    {
        $this->ensureOwned($vacationPolicy);
        if ($vacationPolicy->is_default) {
            throw ValidationException::withMessages(['policy' => 'No puedes desactivar la política por defecto.']);
        }
        $vacationPolicy->delete();
        $this->audit->log('vacation_policy.deactivated', $vacationPolicy, "Política de vacaciones desactivada: {$vacationPolicy->name}");
        $this->toast('Política desactivada.');

        return back();
    }

    public function restore(Request $request, VacationPolicy $vacationPolicy): RedirectResponse
    {
        $this->ensureOwned($vacationPolicy);
        $vacationPolicy->restore();
        $this->audit->log('vacation_policy.restored', $vacationPolicy, "Política de vacaciones reactivada: {$vacationPolicy->name}");
        $this->toast('Política reactivada.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'extra_days' => ['present', 'array', 'max:40'],
            'extra_days.*.from_year' => ['required', 'integer', 'min:1', 'max:60'],
            'extra_days.*.to_year' => ['nullable', 'integer', 'min:1', 'max:60', 'gte:extra_days.*.from_year'],
            'extra_days.*.days' => ['required', 'integer', 'max:60'],
            'premium_percent' => ['required', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
            'min_notice_days' => ['required', 'integer', 'min:0', 'max:90'],
            'allow_half_days' => ['required', 'boolean'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_default' => ['required', 'boolean'],
        ], [
            'extra_days.*.to_year.gte' => 'El año final debe ser mayor o igual al inicial.',
        ], [
            'name' => 'nombre', 'premium_percent' => 'prima vacacional', 'min_notice_days' => 'anticipación mínima',
            'valid_from' => 'inicio de vigencia', 'valid_to' => 'fin de vigencia',
            'extra_days.*.from_year' => 'año inicial', 'extra_days.*.to_year' => 'año final', 'extra_days.*.days' => 'días adicionales',
        ]);

        $rows = array_values(array_map(fn ($r) => [
            'from_year' => (int) $r['from_year'],
            'to_year' => isset($r['to_year']) && $r['to_year'] !== '' ? (int) $r['to_year'] : null,
            'days' => (int) $r['days'],
        ], $data['extra_days']));
        usort($rows, fn ($a, $b) => $a['from_year'] <=> $b['from_year']);

        $premium = number_format((float) $data['premium_percent'], 2, '.', '');
        // Regla de dominio: nunca inferior al mínimo legal.
        $this->vacations->assertPolicyValid($rows, $premium);

        for ($i = 1; $i < count($rows); $i++) {
            $prevEnd = $rows[$i - 1]['to_year'];
            if ($prevEnd === null || $rows[$i]['from_year'] <= $prevEnd) {
                throw ValidationException::withMessages(['extra_days' => 'Los rangos de años de servicio se traslapan.']);
            }
        }

        return [
            'name' => $data['name'],
            'extra_days' => $rows,
            'premium_percent' => $premium,
            'min_notice_days' => (int) $data['min_notice_days'],
            'allow_half_days' => (bool) $data['allow_half_days'],
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'is_default' => (bool) $data['is_default'],
        ];
    }
}
