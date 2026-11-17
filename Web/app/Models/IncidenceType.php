<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncidenceType extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'incidence_types';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_evidence' => 'boolean',
            'requires_approval' => 'boolean',
            'affects_payroll' => 'boolean',
            'employee_requestable' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return HasMany<Incidence, $this>
     */
    public function incidences(): HasMany
    {
        return $this->hasMany(Incidence::class);
    }
}
