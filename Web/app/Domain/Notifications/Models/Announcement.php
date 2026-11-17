<?php

namespace App\Domain\Notifications\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comunicado enviado desde la web a colaboradores. Nunca se borra: se retira
 * (`withdrawn_at`) y deja de mostrarse en la app; puede reactivarse.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string $body
 * @property string $priority
 * @property string $audience_type
 * @property list<int>|null $audience_ids
 * @property string $status
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $withdrawn_at
 * @property int $recipients_count
 * @property int|null $created_by
 */
class Announcement extends Model
{
    use BelongsToOrganization, NeverDeleted;

    public const AUDIENCES = ['all', 'branches', 'departments', 'positions', 'employees'];

    protected $table = 'announcements';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience_ids' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /**
     * @return HasMany<AnnouncementRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }
}
