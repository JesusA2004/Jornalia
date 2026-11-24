<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Centro de trabajo (define zona horaria y fecha civil de los marcajes).
 *
 * @property string|null $external_source origen externo (p. ej. «people»)
 * @property string|null $external_id identificador en el sistema de origen
 */
class WorkCenter extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'work_centers';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LegalEntity, $this>
     */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
