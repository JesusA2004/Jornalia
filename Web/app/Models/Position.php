<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Puesto. Los identificadores externos vinculan el registro con Mr. Lana People.
 *
 * @property string|null $external_source origen externo (p. ej. «people»)
 * @property string|null $external_id identificador en el sistema de origen
 */
class Position extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'positions';

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
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
