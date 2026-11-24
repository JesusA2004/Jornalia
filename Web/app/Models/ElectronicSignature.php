<?php

namespace App\Models;

use App\Support\Immutable;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Firma electrónica del colaborador. Registro inmutable (append-only) y
 * encadenado con `HashChain`: cualquier alteración rompe el sello.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $employee_id
 * @property int $user_id
 * @property string $signable_type
 * @property int $signable_id
 * @property string $purpose
 * @property string $method
 * @property string $statement
 * @property string $payload_sha256
 * @property int|null $device_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $signed_at
 * @property int $sequence
 * @property string $previous_hash
 * @property string $hash
 */
class ElectronicSignature extends Model
{
    use BelongsToOrganization, Immutable;

    public const PURPOSE_RECTIFICATION = 'rectification';

    public const PURPOSE_REGISTRATION_AGREEMENT = 'registration_agreement';

    public const PURPOSE_PRIVACY_NOTICE = 'privacy_notice';

    public const PURPOSES = [self::PURPOSE_RECTIFICATION, self::PURPOSE_REGISTRATION_AGREEMENT, self::PURPOSE_PRIVACY_NOTICE];

    public const METHOD_PIN = 'pin';

    public const METHOD_BIOMETRIC = 'biometric';

    public const METHODS = [self::METHOD_PIN, self::METHOD_BIOMETRIC];

    protected $table = 'electronic_signatures';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function signable(): MorphTo
    {
        return $this->morphTo();
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function methodLabel(): string
    {
        return $this->method === self::METHOD_BIOMETRIC ? 'Biometría del teléfono' : 'NIP';
    }
}
