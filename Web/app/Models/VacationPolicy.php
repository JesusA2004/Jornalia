<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VacationPolicy extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'vacation_policies';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_days' => 'array',
            'premium_percent' => 'decimal:2',
            'allow_half_days' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_default' => 'boolean',
        ];
    }
}
