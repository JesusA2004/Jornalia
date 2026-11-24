<?php

namespace App\Models;

use App\Support\Immutable;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable (append-only). Ver AGENTS.md regla 1.
 */
class DocumentAcknowledgement extends Model
{
    use BelongsToOrganization, Immutable;

    protected $table = 'document_acknowledgements';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PolicyDocument, $this>
     */
    public function policyDocument(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
