<?php

namespace App\Models;

use App\Enums\AdjustmentAction;
use App\Enums\PunchType;
use App\Support\Immutable;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable (append-only). Ver AGENTS.md regla 1.
 */
class AttendanceAdjustment extends Model
{
    use BelongsToOrganization, Immutable;

    protected $table = 'attendance_adjustments';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AdjustmentAction::class,
            'type' => PunchType::class,
            'recognized_time' => 'immutable_datetime',
            'local_date' => 'date',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<RectificationRequest, $this>
     */
    public function rectificationRequest(): BelongsTo
    {
        return $this->belongsTo(RectificationRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
