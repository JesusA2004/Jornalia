<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PolicyDocument extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'policy_documents';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_acknowledgement' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DocumentAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
