<?php

namespace App\Domain\Signatures;

use App\Domain\Audit\AuditLogger;
use App\Models\Attachment;
use App\Models\Device;
use App\Models\DocumentAcknowledgement;
use App\Models\ElectronicSignature;
use App\Models\Employee;
use App\Models\PolicyDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Convenio de registro electrónico (`CONVENIO_REGISTRO`) y aviso de privacidad
 * (`AVISO_PRIVACIDAD`). La aceptación en la app es un acuse inmutable
 * (`DocumentAcknowledgement`) más una firma electrónica encadenada.
 *
 * Sólo cuenta la versión vigente: la publicada más reciente. Al publicar una
 * versión nueva, el colaborador debe volver a firmar.
 */
final class RegistrationDocuments
{
    public const AGREEMENT = 'CONVENIO_REGISTRO';

    public const PRIVACY = 'AVISO_PRIVACIDAD';

    /** Tipo de adjunto del expediente para el convenio firmado en papel. */
    public const PAPER_AGREEMENT_CATEGORY = 'registration_agreement';

    /** @var array<string, string> */
    public const PURPOSES = [
        self::AGREEMENT => ElectronicSignature::PURPOSE_REGISTRATION_AGREEMENT,
        self::PRIVACY => ElectronicSignature::PURPOSE_PRIVACY_NOTICE,
    ];

    public function __construct(
        private readonly ElectronicSignatureService $signatures,
        private readonly AuditLogger $audit,
        private readonly SignaturePinService $pins,
    ) {}

    /**
     * Versión vigente publicada del documento (o null si no se ha publicado).
     * Con `$organizationId` se consulta esa organización de forma explícita (no
     * depende de la organización activa; lo usa la validación del marcaje).
     */
    public function current(string $code, ?int $organizationId = null): ?PolicyDocument
    {
        return PolicyDocument::query()
            ->when($organizationId !== null, fn ($q) => $q->withoutGlobalScope('organization')->where('organization_id', $organizationId))
            ->where('code', $code)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')->orderByDesc('id')
            ->first();
    }

    /**
     * ¿El colaborador firmó la versión vigente del documento? Para el convenio de
     * registro electrónico también cuenta el convenio en papel adjunto al expediente.
     */
    public function hasSigned(Employee $employee, string $code): bool
    {
        $doc = $this->current($code);
        if ($doc !== null && $this->signatureFor($employee, $doc) !== null) {
            return true;
        }

        return $code === self::AGREEMENT && $this->hasPaperAgreement($employee);
    }

    /**
     * Firma electrónica del colaborador sobre una versión concreta del documento
     * (cada versión nueva exige firmar otra vez).
     */
    public function signatureFor(Employee $employee, PolicyDocument $doc): ?ElectronicSignature
    {
        return ElectronicSignature::query()
            ->where('employee_id', $employee->id)
            ->where('signable_type', $doc->getMorphClass())
            ->where('signable_id', $doc->id)
            ->where('purpose', self::PURPOSES[$doc->code] ?? '')
            ->first();
    }

    /**
     * Convenio firmado en papel adjunto al expediente (lo registra Colaboradores).
     */
    public function hasPaperAgreement(Employee $employee): bool
    {
        if (! self::attachmentsHaveCategory()) {
            return false;
        }

        return Attachment::query()
            ->where('attachable_type', $employee->getMorphClass())
            ->where('attachable_id', $employee->id)
            ->where('category', self::PAPER_AGREEMENT_CATEGORY)
            ->exists();
    }

    /**
     * Documentos vigentes pendientes de firma del colaborador.
     *
     * @return list<PolicyDocument>
     */
    public function pending(Employee $employee): array
    {
        $pending = [];
        foreach (array_keys(self::PURPOSES) as $code) {
            $doc = $this->current($code);
            if ($doc === null || $this->signatureFor($employee, $doc) !== null) {
                continue;
            }
            if ($code === self::AGREEMENT && $this->hasPaperAgreement($employee)) {
                continue;
            }
            $pending[] = $doc;
        }

        return $pending;
    }

    /**
     * Texto exacto que acepta el colaborador. Incluye la versión y el SHA-256 del
     * documento para que la firma pruebe qué contenido se aceptó.
     */
    public static function statement(PolicyDocument $doc): string
    {
        return "Acepto «{$doc->title}» versión {$doc->version} (SHA-256 {$doc->sha256}).";
    }

    /**
     * Acepta y firma la versión vigente. Idempotente: si ya está firmada, devuelve la firma.
     *
     * @throws ValidationException
     */
    public function sign(User $user, Employee $employee, PolicyDocument $doc, string $method, ?string $pin, ?Device $device, ?Request $request = null, ?string $deviceSignature = null): ElectronicSignature
    {
        if (! isset(self::PURPOSES[$doc->code])) {
            throw ValidationException::withMessages(['document' => 'Este documento no requiere firma electrónica.']);
        }
        $current = $this->current($doc->code);
        if ($current === null || $current->id !== $doc->id) {
            throw ValidationException::withMessages(['document' => 'Sólo puede firmarse la versión vigente del documento.']);
        }
        if ($existing = $this->signatureFor($employee, $doc)) {
            return $existing;
        }

        // El NIP se verifica FUERA de la transacción: un intento fallido debe persistir
        // (bloqueo tras 5 intentos) aunque la firma no se complete.
        if ($method === ElectronicSignature::METHOD_PIN && ($pin === null || $pin === '' || ! $this->pins->verify($user, $pin))) {
            throw ValidationException::withMessages(['pin' => 'NIP incorrecto.']);
        }

        // Doble toque o dos peticiones simultáneas: el bloqueo del expediente serializa la
        // firma y la segunda devuelve la existente (una sola firma por versión vigente).
        $signature = DB::transaction(function () use ($user, $employee, $doc, $method, $pin, $device, $request, $deviceSignature) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first(['id']);
            if ($existing = $this->signatureFor($employee, $doc)) {
                return $existing;
            }

            return $this->signatures->sign($user, $doc, self::PURPOSES[$doc->code], $method, $pin, $device, self::statement($doc), $request, $deviceSignature);
        });

        $exists = DocumentAcknowledgement::query()->where('policy_document_id', $doc->id)->where('employee_id', $employee->id)->exists();
        if (! $exists) {
            $at = CarbonImmutable::now('UTC');
            try {
                $ack = DocumentAcknowledgement::query()->create([
                    'organization_id' => $doc->organization_id,
                    'policy_document_id' => $doc->id,
                    'employee_id' => $employee->id,
                    'user_id' => $user->id,
                    'device_id' => $device?->id,
                    'document_sha256' => $doc->sha256,
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                    'hash' => hash('sha256', implode('|', [$doc->sha256, $employee->id, $user->id, $at->format('Y-m-d\TH:i:s.u\Z')])),
                    'acknowledged_at' => $at,
                ]);
                $this->audit->log('document.acknowledged', $ack, "Acuse firmado de «{$doc->title}» v{$doc->version}");
            } catch (UniqueConstraintViolationException) {
                // Acuse concurrente: ya existe.
            }
        }

        return $signature;
    }

    private static function attachmentsHaveCategory(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('attachments', 'category');
    }
}
