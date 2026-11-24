<?php

namespace App\Http\Controllers\Web\Operacion;

use App\Domain\Attendance\IntegrityAnchorService;
use App\Domain\Attendance\IntegrityChains;
use App\Domain\Compliance\IntegrityMonitor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Operacion\Support\OperacionScope;
use App\Http\Requests\Web\Operacion\ListFiltersRequest;
use App\Models\AuditLog;
use App\Models\IntegrityAnchor;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cumplimiento › Integridad del registro: verificación de cadenas de hash
 * (marcajes, ajustes y firmas electrónicas) contra anclas externas firmadas. Corre sola cada día
 * (scheduler); el botón manual es opcional. Cada ejecución queda en la
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
            'chain_label' => IntegrityChains::shortLabel($a->chain),
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
                'automatic' => (bool) ($l->context['automatic'] ?? $l->user_id === null),
                'valid' => (bool) ($l->context['valid'] ?? false),
                'chains' => array_values((array) ($l->context['chains'] ?? [])),
            ])->values();

        $lastAnchorLog = AuditLog::query()->where('organization_id', $orgId)
            ->whereIn('action', ['integrity.anchored', 'integrity.anchor_failed'])
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $disk = (string) config('integrity.anchor_disk', 'anchors');
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        return Inertia::render('cumplimiento/Integridad', [
            'chains' => array_map(fn (string $chain) => $this->head($chain), array_keys(IntegrityChains::MODELS)),
            'anchors' => $page,
            'latest' => $verifications->first(),
            'history' => $verifications,
            'automation' => [
                'schedule' => 'Diaria 03:30 (verificación y anclaje) · anclaje cada hora',
                'last_anchor' => $lastAnchorLog ? [
                    'at' => OperacionScope::iso($lastAnchorLog->created_at),
                    'ok' => $lastAnchorLog->action === 'integrity.anchored',
                    'automatic' => (bool) ($lastAnchorLog->context['automatic'] ?? false),
                    'error' => $lastAnchorLog->context['error'] ?? null,
                ] : null,
                'anchor_key_configured' => (string) config('integrity.anchor_key') !== '',
                'anchor_storage' => [
                    'disk' => $disk,
                    'driver' => $driver,
                    // Sólo un almacenamiento fuera del servidor (S3 con Object Lock u otro WORM) es externo real.
                    'external' => $driver !== 'local',
                ],
            ],
            'can' => ['verify' => $request->user()->can('integrity.verify')],
            'timezone' => (string) ($this->current->get()->timezone ?? 'UTC'),
        ]);
    }

    public function verify(ListFiltersRequest $request, IntegrityMonitor $monitor): RedirectResponse
    {
        $result = $monitor->verify($this->current->require(), automatic: false);

        Inertia::flash('toast', $result['valid']
            ? ['type' => 'success', 'message' => 'Cadenas íntegras: no se detectaron alteraciones.']
            : ['type' => 'error', 'message' => 'Se detectaron inconsistencias. Se avisó a RH y Sistemas.']);

        return back();
    }

    public function anchor(ListFiltersRequest $request, IntegrityMonitor $monitor): RedirectResponse
    {
        $result = $monitor->anchor($this->current->require(), automatic: false);
        if (! $result['ok']) {
            return back()->withErrors(['anchor' => (string) $result['error']]);
        }

        Inertia::flash('toast', ['type' => $result['created'] > 0 ? 'success' : 'info', 'message' => $result['created'] > 0
            ? "Se registraron {$result['created']} anclas externas."
            : 'Las cadenas ya estaban ancladas en su último registro.']);

        return back();
    }

    /**
     * Cabeza de la cadena y su última ancla externa.
     *
     * @return array<string, mixed>
     */
    private function head(string $chain): array
    {
        $model = IntegrityChains::model($chain);
        $recordedAt = IntegrityChains::RECORDED_AT[$chain];
        $head = $model::query()->orderByDesc('sequence')->first(['sequence', 'hash', $recordedAt]);
        $anchor = IntegrityAnchor::query()->where('chain', $chain)->orderByDesc('sequence')->first();
        $lastAt = $head?->getAttribute($recordedAt);

        return [
            'chain' => $chain,
            'label' => IntegrityChains::label($chain),
            'records' => $model::query()->count(),
            'head_sequence' => (int) ($head->sequence ?? 0),
            'head_hash' => $head->hash ?? null,
            'last_record_at' => OperacionScope::iso($lastAt instanceof CarbonInterface ? $lastAt : null),
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
