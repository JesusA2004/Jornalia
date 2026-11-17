<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegalEntity extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'legal_entities';

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
     * @return HasMany<WorkCenter, $this>
     */
    public function workCenters(): HasMany
    {
        return $this->hasMany(WorkCenter::class);
    }
}
