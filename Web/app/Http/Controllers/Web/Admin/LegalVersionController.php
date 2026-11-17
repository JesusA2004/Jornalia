<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Legal\LegalFramework;
use App\Models\LegalVersion;
use App\Models\VacationEntitlementRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta de normativa vigente (sólo lectura): versiones, parámetros,
 * fuentes y estado de verificación.
 */
class LegalVersionController extends AdminController
{
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today()->toDateString();

        $versions = LegalVersion::query()->orderBy('valid_from')->get()->map(function (LegalVersion $v) use ($today) {
            $p = (array) $v->parameters;

            return [
                'id' => $v->id,
                'code' => $v->code,
                'name' => $v->name,
                'valid_from' => $v->valid_from->toDateString(),
                'valid_to' => $v->valid_to?->toDateString(),
                'verification_status' => $v->verification_status,
                'is_current' => $v->valid_from->toDateString() <= $today && ($v->valid_to === null || $v->valid_to->toDateString() >= $today),
                'checksum_ok' => hash_equals($v->checksum, LegalFramework::checksum($p)),
                'notes' => $v->notes,
                'sources' => array_values((array) $v->sources),
                'parameters' => [
                    'weekly_ordinary_max_minutes' => (int) ($p['weekly_ordinary_max_minutes'] ?? 0),
                    'daily_max_minutes' => (array) ($p['daily_max_minutes'] ?? []),
                    'night_window' => (array) ($p['night_window'] ?? []),
                    'overtime' => (array) ($p['overtime'] ?? []),
                    'min_break_minutes' => $p['min_break_minutes'] ?? null,
                    'rest_days_per_week' => $p['rest_days_per_week'] ?? null,
                    'sunday_premium_min_percent' => $p['sunday_premium_min_percent'] ?? null,
                    'vacation_premium_min_percent' => $p['vacation_premium_min_percent'] ?? null,
                ],
                'raw' => $p,
            ];
        });

        return Inertia::render('configuracion/Legal', [
            'versions' => $versions->values(),
            'vacation_table' => VacationEntitlementRule::query()->orderBy('valid_from')->orderBy('from_year')->get()
                ->map(fn ($r) => ['code' => $r->code, 'valid_from' => $r->valid_from, 'valid_to' => $r->valid_to, 'from_year' => (int) $r->from_year, 'to_year' => $r->to_year !== null ? (int) $r->to_year : null, 'days' => (int) $r->days, 'source' => $r->source])->values(),
            'today' => $today,
        ]);
    }
}
