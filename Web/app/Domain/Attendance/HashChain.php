<?php

namespace App\Domain\Attendance;

use App\Models\AttendanceAdjustment;
use App\Models\ElectronicSignature;
use App\Models\Organization;
use App\Models\Punch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cadena de hash por organización para tablas append-only.
 *
 *   hash_n = HMAC_SHA256(INTEGRITY_CHAIN_KEY, previous_hash || "\n" || canonical_json(payload_n))
 *
 * El sello HMAC con una clave que vive fuera de la base de datos impide que
 * alguien con acceso sólo a la BD recalcule o falsifique eslabones.
 *
 * La secuencia se asigna bajo bloqueo de fila de la organización (SELECT … FOR
 * UPDATE), por lo que dos marcajes concurrentes nunca comparten secuencia ni
 * rompen la cadena. Cualquier alteración posterior de un campo encadenado,
 * eliminación o inserción intermedia hace fallar `IntegrityVerifier`.
 */
final class HashChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Debe llamarse dentro de una transacción.
     *
     * @param  class-string<Punch|AttendanceAdjustment|ElectronicSignature>  $modelClass
     * @return array{sequence:int, previous_hash:string}
     */
    public function next(int $organizationId, string $modelClass): array
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->first(['id']);

        /** @var Model|null $last */
        $last = $modelClass::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('sequence')
            ->first(['sequence', 'hash']);

        return [
            'sequence' => $last ? ((int) $last->getAttribute('sequence')) + 1 : 1,
            'previous_hash' => $last ? (string) $last->getAttribute('hash') : self::GENESIS,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(string $previousHash, array $payload): string
    {
        $key = (string) config('integrity.chain_key');
        if ($key === '') {
            throw new \RuntimeException('Configure INTEGRITY_CHAIN_KEY (o APP_KEY) para sellar la cadena de integridad.');
        }

        return hash_hmac('sha256', $previousHash."\n".self::canonical($payload), $key);
    }

    /**
     * Campos encadenados de un marcaje (orden fijo y formato estable).
     *
     * @return array<string, mixed>
     */
    public static function punchPayload(Punch $p): array
    {
        return [
            'uuid' => $p->uuid,
            'organization_id' => (int) $p->organization_id,
            'employee_id' => (int) $p->employee_id,
            'employment_id' => $p->employment_id !== null ? (int) $p->employment_id : null,
            'device_id' => $p->device_id !== null ? (int) $p->device_id : null,
            'recorded_by' => $p->recorded_by !== null ? (int) $p->recorded_by : null,
            'client_uuid' => $p->client_uuid,
            'type' => $p->type->value,
            'source' => $p->source->value,
            'server_time' => self::time($p->server_time),
            'device_time' => self::time($p->device_time),
            'timezone' => $p->timezone,
            'local_date' => $p->local_date->toDateString(),
            'auth_method' => $p->auth_method->value,
            'signature_valid' => (bool) $p->signature_valid,
            'captured_offline' => (bool) $p->captured_offline,
            'status' => $p->status->value,
            'anomalies' => array_values((array) $p->anomalies),
            'latitude' => $p->latitude !== null ? (string) $p->latitude : null,
            'longitude' => $p->longitude !== null ? (string) $p->longitude : null,
            'accuracy_meters' => $p->accuracy_meters !== null ? (int) $p->accuracy_meters : null,
            'location_mocked' => $p->location_mocked,
            'sequence' => (int) $p->sequence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adjustmentPayload(AttendanceAdjustment $a): array
    {
        return [
            'uuid' => $a->uuid,
            'organization_id' => (int) $a->organization_id,
            'employee_id' => (int) $a->employee_id,
            'rectification_request_id' => (int) $a->rectification_request_id,
            'action' => $a->action->value,
            'target_punch_uuid' => $a->target_punch_uuid,
            'type' => $a->type?->value,
            'recognized_time' => self::time($a->recognized_time),
            'local_date' => $a->local_date->toDateString(),
            'approved_by' => (int) $a->approved_by,
            'reason' => $a->reason,
            'sequence' => (int) $a->sequence,
        ];
    }

    /**
     * Campos encadenados de una firma electrónica (orden fijo y formato estable).
     * El texto firmado no se encadena directo: se encadena su SHA-256
     * (`payload_sha256`) y `IntegrityVerifier` lo recalcula para detectar
     * cualquier cambio al texto.
     *
     * @return array<string, mixed>
     */
    public static function signaturePayload(ElectronicSignature $s): array
    {
        return [
            'uuid' => $s->uuid,
            'organization_id' => (int) $s->organization_id,
            'employee_id' => (int) $s->employee_id,
            'user_id' => (int) $s->user_id,
            'signable_type' => $s->signable_type,
            'signable_id' => (int) $s->signable_id,
            'purpose' => $s->purpose,
            'method' => $s->method,
            'payload_sha256' => $s->payload_sha256,
            'device_id' => $s->device_id !== null ? (int) $s->device_id : null,
            'ip' => $s->ip,
            'signed_at' => self::time($s->signed_at),
            'sequence' => (int) $s->sequence,
        ];
    }

    /**
     * Campos encadenados de cualquier eslabón según su tabla.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(Punch|AttendanceAdjustment|ElectronicSignature $row): array
    {
        return match (true) {
            $row instanceof Punch => self::punchPayload($row),
            $row instanceof AttendanceAdjustment => self::adjustmentPayload($row),
            $row instanceof ElectronicSignature => self::signaturePayload($row),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function canonical(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function time(?CarbonInterface $t): ?string
    {
        return $t?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Transacción con reintentos ante bloqueos al asignar la secuencia.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(\Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }
}
