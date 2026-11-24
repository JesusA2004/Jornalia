<?php

namespace App\Domain\Attendance;

use App\Domain\Signatures\PunchDocumentsGate;
use RuntimeException;

/**
 * Error de dominio de asistencia con código estable del contrato API
 * (`ApiErrorCode` en packages/contracts) y código HTTP sugerido.
 */
final class AttendanceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        /** Código equivalente para apps instaladas que no conocen `errorCode` (`legacy_code` del contrato). */
        public readonly ?string $legacyCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Convenio de registro o aviso de privacidad vigente sin firmar en un marcaje en
     * línea (PunchDocumentsGate). HTTP 409; `legacy_code = policy_violation` para apps
     * anteriores, que muestran el mensaje del servidor.
     *
     * @param  list<string>  $pending  códigos de documento pendientes
     */
    public static function documentsPending(array $pending): self
    {
        return new self('documents_pending', PunchDocumentsGate::message($pending), 409, 'policy_violation');
    }

    public static function noEmployment(): self
    {
        return new self('no_employment', 'No tienes una relación laboral vigente en esta organización.', 403);
    }

    public static function devicePending(): self
    {
        return new self('device_pending', 'Este dispositivo aún no ha sido autorizado por tu empresa.', 423);
    }

    public static function deviceRevoked(): self
    {
        return new self('device_revoked', 'Este dispositivo fue revocado. Inicia sesión nuevamente para vincularlo.', 403);
    }

    public static function deviceUnknown(): self
    {
        return new self('device_unknown', 'Dispositivo no reconocido para este usuario.', 403);
    }

    public static function invalidSignature(): self
    {
        return new self('invalid_signature', 'La firma del dispositivo no es válida.', 401);
    }

    public static function challengeExpired(): self
    {
        return new self('challenge_expired', 'El desafío de seguridad expiró. Intenta de nuevo.', 409);
    }

    public static function challengeUsed(): self
    {
        return new self('challenge_used', 'El desafío de seguridad ya fue utilizado.', 409);
    }

    public static function invalidSequence(string $expected): self
    {
        return new self('invalid_sequence', "Secuencia de marcaje inválida. Se esperaba: {$expected}.", 409);
    }

    public static function locationRequired(): self
    {
        return new self('location_required', 'Tu política de asistencia exige compartir la ubicación al marcar.', 422);
    }

    public static function outsideGeofence(): self
    {
        return new self('outside_geofence', 'Te encuentras fuera de las ubicaciones autorizadas para marcar.', 422);
    }

    public static function policyViolation(string $message): self
    {
        return new self('policy_violation', $message, 422);
    }
}
