<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\LegalVersionManager;
use App\Models\LegalVersion;
use App\Models\LegalVersionRevision;
use App\Models\VacationEntitlementRule;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Normativa (Configuración › Normativa): tabla por año, única fuente de los
 * parámetros legales. Consulta con `settings.legal`; edición y verificación
 * sólo con `settings.legal_manage` (RH), con historial y bitácora.
 */
class LegalVersionController extends AdminController
{
    public function __construct(private readonly LegalVersionManager $manager) {}

    public function index(Request $request, LegalFramework $law): Response
    {
        $tz = $this->currentTimezone();
        $today = CarbonImmutable::now($tz)->toDateString();

        $versions = LegalVersion::query()->with(['verifiedBy:id,name', 'updatedBy:id,name'])->orderBy('valid_from')->get()->map(function (LegalVersion $v) use ($today) {
            $p = (array) $v->parameters;
            $editable = [];
            foreach (LegalVersionManager::EDITABLE as $key => $path) {
                $editable[$key] = Arr::get($p, $path) !== null ? (int) Arr::get($p, $path) : null;
            }

            return [
                'id' => $v->id,
                'code' => $v->code,
                'name' => $v->name,
                'year' => (int) $v->valid_from->format('Y'),
                'valid_from' => $v->valid_from->toDateString(),
                'valid_to' => $v->valid_to?->toDateString(),
                'verification_status' => $v->isVerified() ? LegalVersion::STATUS_VERIFIED : LegalVersion::STATUS_UNVERIFIED,
                'verified' => $v->isVerified(),
                'verified_by' => $v->isVerified() ? $v->verifiedBy?->name : null,
                'verified_at' => $v->isVerified() ? $v->verified_at?->utc()->format('Y-m-d\TH:i:s\Z') : null,
                'updated_by' => $v->updatedBy?->name,
                'is_current' => $v->valid_from->toDateString() <= $today && ($v->valid_to === null || $v->valid_to->toDateString() >= $today),
                'is_editable' => $this->manager->isEditable($v),
                'checksum_ok' => hash_equals($v->checksum, LegalFramework::checksum($p)),
                'notes' => $v->notes,
                'sources' => array_values((array) $v->sources),
                'values' => $editable,
                'parameters' => [
                    'weekly_ordinary_max_minutes' => (int) ($p['weekly_ordinary_max_minutes'] ?? 0),
                    'daily_max_minutes' => (array) ($p['daily_max_minutes'] ?? []),
                    'night_window' => (array) ($p['night_window'] ?? []),
                    'overtime' => (array) ($p['overtime'] ?? []),
                    'daily_total_max_minutes' => $p['daily_total_max_minutes'] ?? null,
                    'min_break_minutes' => $p['min_break_minutes'] ?? null,
                    'rest_days_per_week' => $p['rest_days_per_week'] ?? null,
                    'sunday_premium_min_percent' => $p['sunday_premium_min_percent'] ?? null,
                    'vacation_premium_min_percent' => $p['vacation_premium_min_percent'] ?? null,
                ],
                'raw' => $p,
            ];
        });

        $history = LegalVersionRevision::query()->with(['user:id,name', 'legalVersion:id,code'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
            ->map(fn (LegalVersionRevision $r) => [
                'id' => $r->id,
                'version' => $r->legalVersion?->code,
                'action' => $r->action,
                'action_label' => match ($r->action) {
                    'verified' => 'Verificada por RH',
                    'updated' => 'Parámetros modificados',
                    default => $r->action,
                },
                'user' => $r->user?->name,
                'note' => $r->note,
                'changes' => $this->diff((array) $r->before, (array) $r->after, $r->action),
                'created_at' => $r->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->values();

        return Inertia::render('configuracion/Legal', [
            'versions' => $versions->values(),
            'current' => $law->verificationFor($today),
            'history' => $history,
            'vacation_table' => VacationEntitlementRule::query()->orderBy('valid_from')->orderBy('from_year')->get()
                ->map(fn ($r) => ['code' => $r->code, 'valid_from' => $r->valid_from, 'valid_to' => $r->valid_to, 'from_year' => (int) $r->from_year, 'to_year' => $r->to_year !== null ? (int) $r->to_year : null, 'days' => (int) $r->days, 'source' => $r->source])->values(),
            'today' => $today,
            'can' => ['manage' => $request->user()?->can('settings.legal_manage') ?? false],
        ]);
    }

    public function update(Request $request, LegalVersion $legalVersion): RedirectResponse
    {
        $rules = ['note' => ['required', 'string', 'min:5', 'max:500']];
        foreach (array_keys(LegalVersionManager::EDITABLE) as $key) {
            $rules[$key] = ['required', 'integer', 'min:0', 'max:5000'];
        }
        $rules['overtime_max_days_per_week'] = ['required', 'integer', 'min:0', 'max:7'];
        $data = $request->validate($rules, [], ['note' => 'motivo del cambio']);

        $this->manager->update($legalVersion, array_map('intval', Arr::except($data, ['note'])), $this->actor($request), (string) $data['note']);
        $this->toast("Normativa {$legalVersion->code} guardada. Queda sin verificar hasta que RH la valide.");

        return back();
    }

    public function verify(Request $request, LegalVersion $legalVersion): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']], [], ['note' => 'referencia de validación']);
        $this->manager->verify($legalVersion, $this->actor($request), (string) $data['note']);
        $this->toast("Normativa {$legalVersion->code} verificada por RH.");

        return back();
    }

    private function currentTimezone(): string
    {
        return (string) (app(CurrentOrganization::class)->get()?->timezone ?? 'America/Mexico_City');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field:string, before:mixed, after:mixed}>
     */
    private function diff(array $before, array $after, string $action): array
    {
        if ($action !== 'updated') {
            return [];
        }
        $out = [];
        foreach (LegalVersionManager::EDITABLE as $key => $path) {
            $b = Arr::get($before, $path);
            $a = Arr::get($after, $path);
            if ($b !== $a) {
                $out[] = ['field' => $key, 'before' => $b, 'after' => $a];
            }
        }

        return $out;
    }
}
