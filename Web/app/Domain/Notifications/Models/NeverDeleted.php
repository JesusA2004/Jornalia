<?php

namespace App\Domain\Notifications\Models;

use App\Exceptions\ImmutableRecordException;

/**
 * Regla 11: los registros de notificaciones nunca se eliminan físicamente.
 */
trait NeverDeleted
{
    public static function bootNeverDeleted(): void
    {
        static::deleting(function (): never {
            throw new ImmutableRecordException('El sistema no permite eliminar información: '.class_basename(static::class).' sólo admite baja lógica.');
        });
    }
}
