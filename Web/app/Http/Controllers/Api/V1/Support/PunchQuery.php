<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Models\AttendanceAdjustment;
use App\Models\Punch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas de marcajes con la bandera `rectified` (existe un ajuste aprobado
 * que afecta al evento) resuelta en la misma consulta, sin N+1.
 */
final class PunchQuery
{
    /**
     * @return Builder<Punch>
     */
    public static function forEmployee(int $employeeId): Builder
    {
        return Punch::query()
            ->select('attendance_punches.*')
            ->selectSub(
                AttendanceAdjustment::query()->withoutGlobalScopes()
                    ->selectRaw('count(*)')
                    ->whereColumn('attendance_adjustments.target_punch_uuid', 'attendance_punches.uuid'),
                'adjustments_count',
            )
            ->where('attendance_punches.employee_id', $employeeId);
    }

    /**
     * Recarga un marcaje con la bandera calculada.
     */
    public static function fresh(Punch $punch): Punch
    {
        return self::forEmployee($punch->employee_id)->whereKey($punch->id)->firstOrFail();
    }
}
