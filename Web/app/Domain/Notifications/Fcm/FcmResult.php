<?php

namespace App\Domain\Notifications\Fcm;

/**
 * Resultado honesto de un envío FCM HTTP v1. Nunca lleva el token, la
 * credencial ni datos personales: sólo el tipo de resultado y el código FCM.
 */
final readonly class FcmResult
{
    public const SENT = 'sent';

    public const DEAD_TOKEN = 'dead_token';

    public const PERMANENT = 'permanent';

    public const TRANSIENT = 'transient';

    public const DISABLED = 'disabled';

    private function __construct(
        public string $status,
        public ?string $messageId,
        public ?string $code,
    ) {}

    public static function sent(string $messageId): self
    {
        return new self(self::SENT, $messageId, null);
    }

    /** Token muerto (UNREGISTERED / 404): se desactiva el token, no se reintenta. */
    public static function deadToken(string $code): self
    {
        return new self(self::DEAD_TOKEN, null, $code);
    }

    /** Rechazo permanente (400 carga inválida, 401/403 credencial): no se reintenta. */
    public static function permanent(string $code): self
    {
        return new self(self::PERMANENT, null, $code);
    }

    /** Falla transitoria (conexión, 429, 5xx): la cola puede reintentar. */
    public static function transient(string $code): self
    {
        return new self(self::TRANSIENT, null, $code);
    }

    /** Sin credencial válida: push apagado; ningún canal se simula. */
    public static function disabled(): self
    {
        return new self(self::DISABLED, null, 'sin_configuracion');
    }

    public function wasSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isRetryable(): bool
    {
        return $this->status === self::TRANSIENT;
    }
}
