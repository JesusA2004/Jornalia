<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalFlow extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'approval_flows';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'levels' => 'array',
        ];
    }
}
