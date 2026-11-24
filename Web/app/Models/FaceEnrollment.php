<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enrolamiento facial. `template` es la plantilla biométrica (dato sensible):
 * cifrada en reposo con APP_KEY y oculta en serialización. Nunca hay imágenes.
 */
class FaceEnrollment extends Model
{
    use BelongsToOrganization;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'face_enrollments';

    protected $guarded = ['id'];

    protected $hidden = ['template'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => 'encrypted',
            'quality' => 'array',
            'consent_at' => 'datetime',
            'revoked_at' => 'datetime',
            'template_erased_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)->whereNotNull('template');
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
    public function identityVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'identity_verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return BelongsTo<PolicyDocument, $this>
     */
    public function consentDocument(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class, 'consent_document_id')->withTrashed();
    }
}
