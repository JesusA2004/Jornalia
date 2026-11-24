<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cambio de horario programado (antes «excepción»): jornada especial o descanso de un
 * colaborador en una fecha FUTURA. Sólo surte efecto cuando RH lo autoriza
 * (`status = approved`); nunca modifica marcajes ni proyecciones de días pasados.
 */
class ScheduleException extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Catálogo cerrado de motivos (PDF RH pantalla 10).
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'special_opening' => 'Guardia o apertura especial',
        'inventory' => 'Inventario o cierre',
        'training' => 'Capacitación',
        'lactation' => 'Horario de lactancia',
        'shift_swap' => 'Cambio de turno acordado',
        'holiday_worked' => 'Día festivo laborado',
        'other' => 'Otro',
    ];

    protected $table = 'schedule_exceptions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_rest_day' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'notified_at' => 'datetime',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_code ?? ''] ?? 'Sin catálogo';
    }
}
