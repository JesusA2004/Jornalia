<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\RectificationKind;
use App\Enums\RectificationStage;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Solicitud de rectificación. El marcaje original nunca se modifica: al
 * aplicarse genera `attendance_adjustments` encadenados.
 *
 * @property RectificationStage $stage
 */
class RectificationRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'rectification_requests';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => RectificationKind::class,
            'proposed_type' => PunchType::class,
            'proposed_time' => 'datetime',
            'status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
            'stage' => RectificationStage::class,
            'authorized_at' => 'datetime',
            'signature_due_at' => 'datetime',
            'signed_at' => 'datetime',
            'validated_at' => 'datetime',
            'applied_at' => 'datetime',
            'rejected_at' => 'datetime',
            'is_late' => 'boolean',
            'legacy_without_signature' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<AttendanceAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return MorphMany<ApprovalDecision, $this>
     */
    public function decisions(): MorphMany
    {
        return $this->morphMany(ApprovalDecision::class, 'approvable');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Firma electrónica del colaborador (paso 4).
     *
     * @return BelongsTo<ElectronicSignature, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(ElectronicSignature::class, 'electronic_signature_id');
    }

    /**
     * Incidencia de omisión de checada que originó la solicitud.
     *
     * @return BelongsTo<Incidence, $this>
     */
    public function incidence(): BelongsTo
    {
        return $this->belongsTo(Incidence::class);
    }
}
