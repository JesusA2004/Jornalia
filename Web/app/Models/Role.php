<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol con baja lógica: los roles nunca se eliminan físicamente.
 */
class Role extends SpatieRole
{
    use SoftDeletes;
}
