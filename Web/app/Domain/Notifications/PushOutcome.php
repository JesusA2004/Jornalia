<?php

namespace App\Domain\Notifications;

/**
 * Resultado agregado del push a todos los dispositivos activos de un usuario.
 */
final readonly class PushOutcome
{
    public const SENT = 'sent';

    public const NO_DEVICE = 'no_device';

    public const DISABLED = 'disabled';

    public const FAILED = 'failed';

    public const TRANSIENT = 'transient';

    public function __construct(
        public string $status,
        public int $sent = 0,
        public ?string $code = null,
    ) {}
}
