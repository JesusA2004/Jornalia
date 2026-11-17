<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza ante cualquier intento de modificar o eliminar un registro inmutable
 * (marcajes, ajustes, acuses, bitácora de auditoría, anclas de integridad).
 */
final class ImmutableRecordException extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self("El registro {$model} es inmutable: no admite modificaciones ni eliminación.");
    }
}
