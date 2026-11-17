<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Attendance\IntegrityAnchorService;
use App\Domain\Attendance\IntegrityVerifier;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\AttendanceAdjustment;
use App\Models\AuditLog;
use App\Models\IntegrityAnchor;
use App\Models\Punch;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integridad del registro: verificación de cadenas de hash (marcajes y
 * ajustes) contra anclas externas firmadas. Cada verificación queda en la
 * bitácora inmutable con su resultado.
 */
final class IntegrityController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(ListFiltersRequest $request, IntegrityAnchorService $anchors): Response
    {
        $orgId = (int) $this->current->id();

        $page = IntegrityAnchor::query()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(10))->withQueryString();
        $page->through(fn (IntegrityAnchor $a) => [
            'id' => $a->id,
            'chain' => $a->chain,
            'chain_label' => $a->chain === 'punches' ? 'Marcajes' : 'Ajustes',
            'sequence' => (int) $a->sequence,
            'hash' => $a->hash,
            'method' => $a->method,
            'external_reference' => $a->external_reference,
            'signature_valid' => $this->safeSignature($anchors, $a),
            'created_at' => OperacionScope::iso($a->created_at),
        ]);

        $verifications = AuditLog::query()->with('user:id,name')
            ->where('organization_id', $orgId)->where('action', 'integrity.verified')
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'at' => OperacionScope::iso($l->created_at),
                'by' => $l->user?->name,
                'valid' => (bool) ($l->context['valid'] ?? false),
                'chains' => array_values((array) ($l->context['chains'] ?? [])),
            ])->values();

        return Inertia::render('integridad/Index', [
            'chains' => [
                $this->head('punches', 'Marcajes originales', Punch::class),
                $this->head('adjustments', 'Ajustes por rectificación', AttendanceAdjustment::class),
            ],
            'anchors' => $page,
            'latest' => $verifications->first(),
            'history' => $verifications,
            'can' => ['verify' => $request->user()->can('integrity.verify')],
            'timezone' => (string) ($this->current->get()?->timezone ?? 'UTC'),
        ]);
    }

    public function verify(ListFiltersRequest $request, IntegrityVerifier $verifier, AuditLogger $audit): RedirectResponse
    {
        $orgId = (int) $this->current->id();
        $chains = [];
        foreach (['punches', 'adjustments'] as $chain) {
            $result = $verifier->verify($orgId, $chain);
            $result['errors_total'] = count($result['errors']);
            $result['errors'] = array_slice($result['errors'], 0, 200);
            $result['label'] = $chain === 'punches' ? 'Marcajes originales' : 'Ajustes por rectificación';
            $chains[] = $result;
        }
        $valid = collect($chains)->every(fn ($c) => $c['valid']);

        $audit->log('integrity.verified', null, $valid
            ? 'Verificación de integridad sin hallazgos'
            : 'Verificación de integridad con hallazgos', ['valid' => $valid, 'chains' => $chains], $orgId);

        Inertia::flash('toast', $valid
            ? ['type' => 'success', 'message' => 'Cadenas íntegras: no se detectaron alteraciones.']
            : ['type' => 'error', 'message' => 'Se detectaron inconsistencias en la cadena de integridad. Revisa el detalle.']);

        return back();
    }

    public function anchor(ListFiltersRequest $request, IntegrityAnchorService $anchors, AuditLogger $audit): RedirectResponse
    {
        $orgId = (int) $this->current->id();
        try {
            $created = $anchors->anchor($orgId);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['anchor' => $e->getMessage()]);
        }
        $audit->log('integrity.anchored', null, 'Anclaje manual de cadenas ('.count($created).')', ['anchors' => count($created)], $orgId);

        Inertia::flash('toast', ['type' => count($created) > 0 ? 'success' : 'info', 'message' => count($created) > 0
            ? 'Se registraron '.count($created).' anclas externas.'
            : 'Las cadenas ya estaban ancladas en su último registro.']);

        return back();
    }

    /**
     * @param  class-string<Punch|AttendanceAdjustment>  $model
     * @return array<string, mixed>
     */
    private function head(string $chain, string $label, string $model): array
    {
        $head = $model::query()->orderByDesc('sequence')->first(['sequence', 'hash', 'created_at']);
        $anchor = IntegrityAnchor::query()->where('chain', $chain)->orderByDesc('sequence')->first();

        return [
            'chain' => $chain,
            'label' => $label,
            'records' => $model::query()->count(),
            'head_sequence' => (int) ($head->sequence ?? 0),
            'head_hash' => $head->hash ?? null,
            'last_record_at' => OperacionScope::iso($head?->created_at),
            'anchored_sequence' => (int) ($anchor->sequence ?? 0),
            'last_anchor_at' => OperacionScope::iso($anchor?->created_at),
            'unanchored' => max(0, (int) ($head->sequence ?? 0) - (int) ($anchor->sequence ?? 0)),
        ];
    }

    private function safeSignature(IntegrityAnchorService $anchors, IntegrityAnchor $anchor): ?bool
    {
        try {
            return $anchors->signatureValid($anchor);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
