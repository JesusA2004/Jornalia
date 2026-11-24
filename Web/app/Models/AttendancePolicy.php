<?php

namespace App\Models;

use App\Enums\LocationMode;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendancePolicy extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'attendance_policies';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location_mode' => LocationMode::class,
            'allowed_auth_methods' => 'array',
            'require_strong_biometric' => 'boolean',
            'allow_offline' => 'boolean',
            'face_verification_required' => 'boolean',
            'web_punch_allowed' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Geofence, $this>
     */
    public function geofences(): BelongsToMany
    {
        return $this->belongsToMany(Geofence::class, 'attendance_policy_geofence');
    }
}
