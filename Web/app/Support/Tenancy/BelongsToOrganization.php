<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aísla el modelo por organización: scope global + asignación automática.
 *
 * Fuera de una petición (consola/jobs) sin organización activa, el scope no
 * filtra; los jobs deben usar `CurrentOrganization::runAs()`.
 *
 * @property int $organization_id
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $organizationId = app(CurrentOrganization::class)->id();
            if ($organizationId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $organizationId);
            }
        });

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('organization_id'))) {
                $model->setAttribute('organization_id', app(CurrentOrganization::class)->require()->id);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
