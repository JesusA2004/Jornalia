<?php

namespace App\Domain\Attendance;

use App\Models\AttendanceAdjustment;
use App\Models\IntegrityAnchor;
use App\Models\Punch;

/**
 * Verifica las cadenas de hash de marcajes y ajustes de una organización y
 * contrasta con las anclas externas. Detecta: campos alterados, filas
 * eliminadas o insertadas (huecos de secuencia / enlace roto) y reescritura
 * total de la cadena (discrepancia con anclas firmadas fuera de la BD).
 */
final class IntegrityVerifier
{
    public function __construct(
        private readonly HashChain $chain,
        private readonly IntegrityAnchorService $anchors,
    ) {}

    /**
     * @return array{chain:string, checked:int, valid:bool, head_sequence:int, head_hash:string, errors:list<array{sequence:int, error:string}>, anchors_checked:int}
     */
    public function verify(int $organizationId, string $chain = 'punches'): array
    {
        $model = $chain === 'punches' ? Punch::class : AttendanceAdjustment::class;
        $errors = [];
        $expectedSeq = 1;
        $prevHash = HashChain::GENESIS;
        $checked = 0;
        $hashes = [];

        $model::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderBy('sequence')
            ->chunk(1000, function ($rows) use (&$errors, &$expectedSeq, &$prevHash, &$checked, &$hashes, $chain) {
                foreach ($rows as $row) {
                    $seq = (int) $row->sequence;
                    if ($seq !== $expectedSeq) {
                        $errors[] = ['sequence' => $seq, 'error' => "Hueco en la secuencia: se esperaba {$expectedSeq}"];
                    }
                    if ($row->previous_hash !== $prevHash) {
                        $errors[] = ['sequence' => $seq, 'error' => 'Enlace roto con el registro anterior'];
                    }
                    $payload = $chain === 'punches' ? HashChain::punchPayload($row) : HashChain::adjustmentPayload($row);
                    $recomputed = $this->chain->hash($row->previous_hash, $payload);
                    if (! hash_equals($recomputed, $row->hash)) {
                        $errors[] = ['sequence' => $seq, 'error' => 'Contenido alterado: el hash no coincide'];
                    }
                    $hashes[$seq] = $row->hash;
                    $prevHash = $row->hash;
                    $expectedSeq = $seq + 1;
                    $checked++;
                }
            });

        $anchorsChecked = 0;
        foreach (IntegrityAnchor::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->where('chain', $chain)->orderBy('sequence')->cursor() as $anchor) {
            $anchorsChecked++;
            if (! $this->anchors->signatureValid($anchor)) {
                $errors[] = ['sequence' => (int) $anchor->sequence, 'error' => 'Firma de ancla inválida'];
            }
            if (($hashes[(int) $anchor->sequence] ?? null) !== $anchor->hash) {
                $errors[] = ['sequence' => (int) $anchor->sequence, 'error' => 'La cadena no coincide con el ancla externa registrada'];
            }
        }

        return [
            'chain' => $chain,
            'checked' => $checked,
            'valid' => $errors === [],
            'head_sequence' => $expectedSeq - 1,
            'head_hash' => $prevHash,
            'errors' => $errors,
            'anchors_checked' => $anchorsChecked,
        ];
    }
}
