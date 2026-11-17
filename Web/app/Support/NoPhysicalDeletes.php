<?php

namespace App\Support;

use App\Exceptions\ImmutableRecordException;
use App\Models\PunchChallenge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

/**
 * Política del sistema: NADA se elimina físicamente. Cualquier `delete()` sobre
 * un modelo de dominio sin baja lógica, o un `forceDelete()`, se rechaza.
 *
 * Excepciones técnicas (no son datos de negocio): desafíos de marcaje de un
 * solo uso (`PunchChallenge`), que caducan en minutos.
 */
final class NoPhysicalDeletes
{
    /** @var list<class-string<Model>> */
    private const TECHNICAL_ALLOWLIST = [PunchChallenge::class];

    public static function register(): void
    {
        Event::listen('eloquent.deleting: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }
            $isDomain = str_starts_with($model::class, 'App\\Models\\')
                || (str_starts_with($model::class, 'App\\Domain\\') && str_contains($model::class, '\\Models\\'))
                || $model instanceof Role;
            if (! $isDomain || in_array($model::class, self::TECHNICAL_ALLOWLIST, true)) {
                return;
            }

            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
            if (! $softDeletes || (method_exists($model, 'isForceDeleting') && $model->isForceDeleting())) {
                throw new ImmutableRecordException(
                    'El sistema no permite eliminar información: '.class_basename($model).' sólo admite baja lógica.'
                );
            }
        });
    }
}
