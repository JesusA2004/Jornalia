<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'devices';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'status' => DeviceStatus::class,
            'biometric_available' => 'boolean',
            'integrity_flags' => 'array',
            'integrity_reported_at' => 'datetime',
            'is_corporate' => 'boolean',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return HasMany<PunchChallenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(PunchChallenge::class);
    }
}
