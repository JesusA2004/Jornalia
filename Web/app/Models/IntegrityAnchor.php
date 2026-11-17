<?php

namespace App\Models;

use App\Support\Immutable;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro inmutable (append-only). Ver AGENTS.md regla 1.
 */
class IntegrityAnchor extends Model
{
    use BelongsToOrganization, HasFactory, Immutable;

    protected $table = 'integrity_anchors';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
