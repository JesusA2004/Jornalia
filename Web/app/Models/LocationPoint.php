<?php

namespace App\Models;

use App\Support\Immutable;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de ubicación de trabajo (append-only).
 */
class LocationPoint extends Model
{
    use BelongsToOrganization, Immutable;

    protected $table = 'location_points';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'heading' => 'decimal:2',
            'speed_mps' => 'decimal:2',
            'altitude_m' => 'decimal:2',
            'is_mocked' => 'boolean',
            'captured_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
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
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
