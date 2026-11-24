<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Revisión trimestral de usuarios activos de una organización.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $period
 * @property Carbon $due_on
 * @property string $status
 * @property array<string, array{decision:string, by:int, at:string, note:?string}>|null $decisions
 * @property array<string, mixed>|null $summary
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 */
class UserAccessReview extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'user_access_reviews';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'decisions' => 'array',
            'summary' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
