<?php

namespace App\Domain\Attendance;

use App\Models\AttendanceAdjustment;
use App\Models\IntegrityAnchor;
use App\Models\Punch;
use Illuminate\Support\Facades\Storage;

/**
 * Anclaje externo de la cabeza de cada cadena.
 *
 * Cada ancla se firma con HMAC usando `INTEGRITY_ANCHOR_KEY` (distinta de
 * APP_KEY, idealmente custodiada fuera del servidor de BD) y se escribe:
 *   1. en la tabla `integrity_anchors` (append-only), y
 *   2. como línea JSON en el disco `anchors` (configurable a S3 con Object Lock,
 *      otro servidor o almacenamiento WORM).
 * Si alguien con privilegios reescribe la BD completa, las anclas externas ya
 * emitidas dejan de coincidir y la manipulación se vuelve detectable.
 */
final class IntegrityAnchorService
{
    public function anchor(int $organizationId): array
    {
        $created = [];
        foreach (['punches' => Punch::class, 'adjustments' => AttendanceAdjustment::class] as $chain => $model) {
            $head = $model::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->orderByDesc('sequence')
                ->first(['sequence', 'hash']);
            if ($head === null) {
                continue;
            }
            $last = IntegrityAnchor::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)->where('chain', $chain)
                ->orderByDesc('sequence')->value('sequence');
            if ($last !== null && (int) $last >= (int) $head->sequence) {
                continue;
            }

            $signature = $this->sign($organizationId, $chain, (int) $head->sequence, (string) $head->hash);
            $line = json_encode([
                'organization_id' => $organizationId,
                'chain' => $chain,
                'sequence' => (int) $head->sequence,
                'hash' => $head->hash,
                'signature' => $signature,
                'at' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            ], JSON_UNESCAPED_SLASHES);

            $disk = (string) config('integrity.anchor_disk', 'anchors');
            $file = "org-{$organizationId}/".now('UTC')->format('Y-m').'.jsonl';
            Storage::disk($disk)->append($file, (string) $line);

            $created[] = IntegrityAnchor::query()->create([
                'organization_id' => $organizationId,
                'chain' => $chain,
                'sequence' => (int) $head->sequence,
                'hash' => $head->hash,
                'signature' => $signature,
                'method' => 'disk:'.$disk,
                'external_reference' => $file,
                'created_at' => now('UTC'),
            ]);
        }

        return $created;
    }

    public function signatureValid(IntegrityAnchor $anchor): bool
    {
        return hash_equals($this->sign((int) $anchor->organization_id, $anchor->chain, (int) $anchor->sequence, $anchor->hash), $anchor->signature);
    }

    private function sign(int $organizationId, string $chain, int $sequence, string $hash): string
    {
        $key = (string) config('integrity.anchor_key');
        if ($key === '') {
            throw new \RuntimeException('Configure INTEGRITY_ANCHOR_KEY para anclar la cadena de integridad.');
        }

        return hash_hmac('sha256', "{$organizationId}|{$chain}|{$sequence}|{$hash}", $key);
    }
}
