<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Kiosco de sucursal: tableta fija para marcar con número de empleado + NIP.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $branch_id
 * @property string $uuid
 * @property string $name
 * @property string $status pending | active | revoked
 * @property string|null $activation_code_hash
 * @property Carbon|null $activation_expires_at
 * @property string|null $token_hash
 * @property string|null $device_uuid
 * @property string|null $platform
 * @property string|null $os_version
 * @property string|null $app_version
 * @property string|null $model
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 */
class Kiosk extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'kiosks';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['activation_code_hash', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activation_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendiente de activar',
            self::STATUS_ACTIVE => 'Activo',
            default => 'Revocado',
        };
    }
}
