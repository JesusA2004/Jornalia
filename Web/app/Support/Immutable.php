<?php

namespace App\Support;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;

/**
 * Hace un modelo append-only en la capa de dominio. La BD además tiene triggers
 * BEFORE UPDATE / BEFORE DELETE que rechazan cualquier mutación.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn (Model $model) => throw ImmutableRecordException::for(class_basename($model)));
        static::deleting(fn (Model $model) => throw ImmutableRecordException::for(class_basename($model)));
    }
}
