<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versión de la normativa laboral (tabla por año). Sólo RH la edita y la marca
 * «Verificada por RH» (`App\Domain\Legal\LegalVersionManager`); nunca se precarga verificada.
 */
class LegalVersion extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_UNVERIFIED = 'unverified';

    protected $table = 'legal_versions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'parameters' => 'array',
            'sources' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    /**
     * Verificada sólo si existe un usuario y una fecha real de validación.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED && $this->verified_by !== null && $this->verified_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<LegalVersionRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(LegalVersionRevision::class);
    }
}
