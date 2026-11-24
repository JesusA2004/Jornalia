<?php

namespace App\Models;

use App\Support\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial append-only de la normativa: cada edición (antes/después) y cada
 * verificación por RH. Ver AGENTS.md regla 1 (trigger + trait Immutable).
 */
class LegalVersionRevision extends Model
{
    use Immutable;

    protected $table = 'legal_version_revisions';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LegalVersion, $this>
     */
    public function legalVersion(): BelongsTo
    {
        return $this->belongsTo(LegalVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
