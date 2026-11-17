<?php

namespace App\Models;

use App\Enums\ShiftKind;
use App\Enums\WorkdayStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkdaySummary extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'workday_summaries';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'shift_kind' => ShiftKind::class,
            'status' => WorkdayStatus::class,
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'auto_clock_out_at' => 'datetime',
            'anomalies' => 'array',
            'has_rectifications' => 'boolean',
            'computed_at' => 'datetime',
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
     * @return BelongsTo<Employment, $this>
     */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(Employment::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
