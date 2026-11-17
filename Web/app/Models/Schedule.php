<?php

namespace App\Models;

use App\Enums\ScheduleType;
use App\Enums\ShiftKind;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'schedules';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ScheduleType::class,
            'shift_kind' => ShiftKind::class,
            'cycle_anchor_date' => 'date',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ScheduleSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(ScheduleSegment::class);
    }
}
