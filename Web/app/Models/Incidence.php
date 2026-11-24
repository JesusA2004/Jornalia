<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Incidence extends Model
{
    use BelongsToOrganization;

    protected $table = 'incidences';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'external_synced_at' => 'datetime',
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
     * @return BelongsTo<IncidenceType, $this>
     */
    public function type(): BelongsTo
    {
        // Incluye tipos dados de baja: el historial conserva su nombre.
        return $this->belongsTo(IncidenceType::class, 'incidence_type_id')->withTrashed();
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
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Rectificación generada por una omisión de checada.
     *
     * @return BelongsTo<RectificationRequest, $this>
     */
    public function rectificationRequest(): BelongsTo
    {
        return $this->belongsTo(RectificationRequest::class);
    }

    /**
     * @return MorphMany<ApprovalDecision, $this>
     */
    public function decisions(): MorphMany
    {
        return $this->morphMany(ApprovalDecision::class, 'approvable');
    }
}
