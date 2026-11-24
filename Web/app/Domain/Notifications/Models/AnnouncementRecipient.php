<?php

namespace App\Domain\Notifications\Models;

use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destinatario de un comunicado (uno por colaborador). `delivered_at` = quedó
 * en la bandeja de la app; `push_status` = resultado del aviso push.
 *
 * @property int $id
 * @property int $announcement_id
 * @property int $employee_id
 * @property int|null $user_id
 * @property string|null $notification_id
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $read_at
 * @property string $push_status
 * @property CarbonImmutable|null $push_sent_at
 * @property int $attempts
 * @property CarbonImmutable|null $failed_at
 * @property string|null $error
 */
class AnnouncementRecipient extends Model
{
    use BelongsToOrganization, NeverDeleted;

    public const PUSH_PENDING = 'pending';

    public const PUSH_SENT = 'sent';

    public const PUSH_NO_DEVICE = 'no_device';

    public const PUSH_DISABLED = 'disabled';

    public const PUSH_FAILED = 'failed';

    protected $table = 'announcement_recipients';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'push_sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
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
}
