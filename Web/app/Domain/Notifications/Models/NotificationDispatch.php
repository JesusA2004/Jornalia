<?php

namespace App\Domain\Notifications\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora de avisos automáticos. Su índice único (empleado, tipo, fecha,
 * referencia) garantiza que cada aviso se envíe una sola vez.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $type
 * @property string $local_date
 * @property string $reference
 */
class NotificationDispatch extends Model
{
    use BelongsToOrganization, NeverDeleted;

    public const UPDATED_AT = null;

    protected $table = 'notification_dispatches';

    protected $guarded = ['id'];
}
