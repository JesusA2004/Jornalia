<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RectificationRequest extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'rectification_requests';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => RectificationKind::class,
            'proposed_type' => PunchType::class,
            'proposed_time' => 'datetime',
            'status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
        ];
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<AttendanceAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return MorphMany<ApprovalDecision, $this>
     */
    public function decisions(): MorphMany
    {
        return $this->morphMany(ApprovalDecision::class, 'approvable');
    }
}
