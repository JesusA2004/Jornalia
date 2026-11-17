<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\PunchSource;
use App\Enums\PunchStatus;
use App\Enums\PunchType;
use App\Support\Immutable;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable (append-only). Ver AGENTS.md regla 1.
 */
class Punch extends Model
{
    use BelongsToOrganization, HasFactory, Immutable;

    protected $table = 'attendance_punches';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PunchType::class,
            'source' => PunchSource::class,
            'status' => PunchStatus::class,
            'auth_method' => AuthMethod::class,
            'server_time' => 'immutable_datetime',
            'device_time' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'local_date' => 'date',
            'anomalies' => 'array',
            'signature_valid' => 'boolean',
            'captured_offline' => 'boolean',
            'location_mocked' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'location_captured_at' => 'datetime',
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
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<Geofence, $this>
     */
    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }
}
