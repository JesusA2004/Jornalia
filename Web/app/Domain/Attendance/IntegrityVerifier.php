<?php

namespace App\Domain\Attendance;

use App\Domain\Signatures\ElectronicSignatureService;
use App\Models\ElectronicSignature;
use App\Models\IntegrityAnchor;
use Illuminate\Support\Collection;

/**
 * Verifica las cadenas de hash de una organización (marcajes, ajustes y
 * firmas electrónicas, ver `IntegrityChains`) y las contrasta con las anclas
 * externas. Detecta: campos alterados, filas eliminadas o insertadas (huecos
 * de secuencia / enlace roto), reescritura total de la cadena (discrepancia
 * con anclas firmadas fuera de la BD) y, en firmas, cambios al texto firmado.
 */
final class IntegrityVerifier
{
    public function __construct(
        private readonly HashChain $chain,
        private readonly IntegrityAnchorService $anchors,
    ) {}

    /**
     * Recorre la cadena completa en orden de secuencia.
     *
     * @return array{chain:string, checked:int, valid:bool, head_sequence:int, head_hash:string, errors:list<array{sequence:int, error:string}>, anchors_checked:int}
     *
     * @throws \InvalidArgumentException si la cadena no existe
     */
    public function verify(int $organizationId, string $chain = IntegrityChains::PUNCHES): array
    {
        $model = IntegrityChains::model($chain);
        $errors = [];
        $expectedSeq = 1;
        $prevHash = HashChain::GENESIS;
        $checked = 0;
        $hashes = [];

        $model::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderBy('sequence')
            ->chunk(1000, function (Collection $rows) use (&$errors, &$expectedSeq, &$prevHash, &$checked, &$hashes) {
                foreach ($rows as $row) {
                    $seq = (int) $row->sequence;
                    if ($seq !== $expectedSeq) {
                        $errors[] = ['sequence' => $seq, 'error' => "Hueco en la secuencia: se esperaba {$expectedSeq}"];
                    }
                    if ($row->previous_hash !== $prevHash) {
                        $errors[] = ['sequence' => $seq, 'error' => 'Enlace roto con el registro anterior'];
                    }
                    $recomputed = $this->chain->hash($row->previous_hash, HashChain::payloadFor($row));
                    if (! hash_equals($recomputed, $row->hash)) {
                        $errors[] = ['sequence' => $seq, 'error' => 'Contenido alterado: el hash no coincide'];
                    }
                    if ($row instanceof ElectronicSignature && ! $this->statementIntact($row)) {
                        $errors[] = ['sequence' => $seq, 'error' => 'Texto firmado alterado: el SHA-256 no coincide'];
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

    /**
     * El texto exacto que vio el colaborador no se encadena directo sino por su
     * SHA-256; se recalcula para detectar que alguien lo reescribió en la BD.
     */
    private function statementIntact(ElectronicSignature $signature): bool
    {
        $expected = ElectronicSignatureService::payloadHash(
            $signature->purpose,
            $signature->signable_type,
            (int) $signature->signable_id,
            (int) $signature->employee_id,
            $signature->statement,
        );

        return hash_equals($expected, $signature->payload_sha256);
    }
}
