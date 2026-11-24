<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sucursal. Los identificadores externos vinculan el registro con Mr. Lana People.
 *
 * @property string|null $external_source origen externo (p. ej. «people»)
 * @property string|null $external_id identificador en el sistema de origen
 */
class Branch extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'branches';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * @return HasMany<Geofence, $this>
     */
    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    /**
     * @return HasMany<EmploymentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmploymentAssignment::class);
    }
}
