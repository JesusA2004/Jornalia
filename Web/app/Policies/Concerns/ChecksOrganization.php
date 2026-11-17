<?php

namespace App\Policies\Concerns;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Defensa en profundidad multiempresa: además del scope global, cada policy
 * comprueba que el registro pertenezca a la organización activa (la
 * resolución de modelos por ruta puede ocurrir antes de fijar la organización).
 */
trait ChecksOrganization
{
    protected function sameOrganization(Model $model): bool
    {
        $current = app(CurrentOrganization::class)->id();

        return $current !== null && (int) $model->getAttribute('organization_id') === $current;
    }
}
