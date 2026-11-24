<?php

namespace App\Domain\Signatures;

use App\Domain\Attendance\DeviceSignature;
use App\Domain\Attendance\HashChain;
use App\Domain\Audit\AuditLogger;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\ElectronicSignature;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Firma electrónica del colaborador (NIP o biometría del teléfono).
 *
 * Cada firma es un eslabón inmutable de la cadena `HashChain` de la
 * organización y guarda: texto exacto firmado, su SHA-256, fecha y hora UTC,
 * usuario, dispositivo, IP y método. Con biometría no se confía en un booleano
 * de la app: se exige la firma HMAC del dispositivo vinculado (el secreto sólo
 * se libera tras la autenticación biométrica del sistema operativo).
 *
 * Firma HMAC esperada (biometría):
 *   canonical = "sign|{purpose}|{signable_id}|{payload_sha256}|{device_uuid}"
 *   device_signature = HMAC_SHA256(secreto_del_dispositivo, canonical) en hex.
 */
final class ElectronicSignatureService
{
    public function __construct(
        private readonly HashChain $chain,
        private readonly SignaturePinService $pins,
        private readonly DeviceSignature $deviceSignature,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Firma `$signable` a nombre del colaborador ligado a `$user`.
     *
     * @throws ValidationException
     */
    public function sign(
        User $user,
        Model $signable,
        string $purpose,
        string $method,
        ?string $pin,
        ?Device $device,
        string $statement,
        ?Request $request = null,
        ?string $deviceSignature = null,
    ): ElectronicSignature {
        if (! in_array($purpose, ElectronicSignature::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Propósito de firma no válido.']);
        }
        if (! in_array($method, ElectronicSignature::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Método de firma no válido.']);
        }

        $employee = Employee::query()->where('user_id', $user->id)->first()
            ?? throw ValidationException::withMessages(['employee' => 'Tu usuario no tiene expediente de colaborador.']);

        $payloadSha = self::payloadHashFor($purpose, $signable, (int) $employee->id, $statement);

        if ($method === ElectronicSignature::METHOD_PIN) {
            // Fuera de la transacción de la firma: el intento fallido debe persistir.
            if ($pin === null || $pin === '' || ! $this->pins->verify($user, $pin)) {
                throw ValidationException::withMessages(['pin' => 'NIP incorrecto.']);
            }
        } else {
            $deviceSignature ??= $request?->input('device_signature');
            if ($device === null || $device->user_id !== $user->id || $device->status !== DeviceStatus::Approved) {
                throw ValidationException::withMessages(['method' => 'La firma biométrica requiere un dispositivo vinculado y autorizado.']);
            }
            $canonical = self::biometricCanonical($purpose, (int) $signable->getKey(), $payloadSha, (string) $device->device_uuid);
            if (! is_string($deviceSignature) || ! $this->deviceSignature->verify($device, $canonical, $deviceSignature)) {
                throw ValidationException::withMessages(['device_signature' => 'La firma del dispositivo no es válida.']);
            }
        }

        $signature = DB::transaction(function () use ($user, $employee, $signable, $purpose, $method, $device, $statement, $payloadSha, $request) {
            $link = $this->chain->next((int) $employee->organization_id, ElectronicSignature::class);

            $sig = new ElectronicSignature([
                'uuid' => (string) Str::uuid7(),
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'signable_type' => $signable->getMorphClass(),
                'signable_id' => $signable->getKey(),
                'purpose' => $purpose,
                'method' => $method,
                'statement' => $statement,
                'payload_sha256' => $payloadSha,
                'device_id' => $device?->id,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'signed_at' => CarbonImmutable::now('UTC'),
                'sequence' => $link['sequence'],
                'previous_hash' => $link['previous_hash'],
            ]);
            $sig->hash = $this->chain->hash($link['previous_hash'], HashChain::signaturePayload($sig));
            $sig->save();

            return $sig;
        });

        $this->audit->log('signature.signed', $signature, 'Firma electrónica ('.$signature->methodLabel().') — '.$purpose, [
            'signable_type' => $signature->signable_type, 'signable_id' => $signature->signable_id,
        ]);

        return $signature;
    }

    /**
     * Verifica el sello de una firma contra la clave de integridad.
     */
    public function verifySeal(ElectronicSignature $signature): bool
    {
        return hash_equals($this->chain->hash($signature->previous_hash, HashChain::signaturePayload($signature)), $signature->hash)
            && hash_equals(self::payloadHash($signature->purpose, $signature->signable_type, (int) $signature->signable_id, (int) $signature->employee_id, $signature->statement), $signature->payload_sha256);
    }

    /**
     * SHA-256 del contenido firmado. La app lo recibe (`signature_payload_sha256`)
     * para construir la firma HMAC biométrica.
     */
    public static function payloadHash(string $purpose, string $signableType, int $signableId, int $employeeId, string $statement): string
    {
        return hash('sha256', HashChain::canonical([
            'purpose' => $purpose,
            'signable_type' => $signableType,
            'signable_id' => $signableId,
            'employee_id' => $employeeId,
            'statement' => $statement,
        ]));
    }

    /**
     * SHA-256 del contenido que firma el colaborador para un modelo firmable.
     * El mismo valor se encadena en la firma, así que cualquier cambio posterior
     * al texto o al objeto firmado se detecta en la verificación de integridad.
     */
    public static function payloadHashFor(string $purpose, Model $signable, int $employeeId, string $statement): string
    {
        return self::payloadHash($purpose, $signable->getMorphClass(), (int) $signable->getKey(), $employeeId, $statement);
    }

    /**
     * Cadena que la app firma con el secreto del dispositivo tras la biometría
     * del sistema operativo. El servidor la reconstruye para validar la HMAC:
     * liga propósito, objeto, contenido y teléfono en una sola firma.
     */
    public static function biometricCanonical(string $purpose, int $signableId, string $payloadSha256, string $deviceUuid): string
    {
        return implode('|', ['sign', $purpose, (string) $signableId, $payloadSha256, $deviceUuid]);
    }
}
