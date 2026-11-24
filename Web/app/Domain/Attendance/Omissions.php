<?php

namespace App\Domain\Attendance;

use App\Enums\RectificationStage;
use App\Models\WorkdaySummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Omisiones de checada (entrada sin salida o salida sin entrada).
 *
 * El sistema nunca completa un marcaje faltante: el día queda como omisión
 * hasta que una rectificación firmada por el colaborador lo corrige. Criterio
 * único para Panel, Asistencias y Calendario.
 */
final class Omissions
{
    /** Anomalías de jornada que constituyen omisión de checada. */
    public const ANOMALIES = ['missing_clock_out', 'missing_clock_in'];

    /**
     * Restringe a jornadas con omisión de checada.
     *
     * @param  Builder<WorkdaySummary>  $query
     * @return Builder<WorkdaySummary>
     */
    public static function only(Builder $query): Builder
    {
        $column = $query->getModel()->qualifyColumn('anomalies');

        return $query->where(function (Builder $q) use ($column) {
            foreach (self::ANOMALIES as $code) {
                $q->orWhereJsonContains($column, $code);
            }
        });
    }

    /**
     * Omisiones sin rectificación en curso ni aplicada (las rechazadas o vencidas
     * vuelven a contar: el día sigue abierto).
     *
     * @param  Builder<WorkdaySummary>  $query
     * @return Builder<WorkdaySummary>
     */
    public static function unrectified(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $stages = [RectificationStage::ToAuthorize->value, RectificationStage::ToSign->value, RectificationStage::ToValidate->value, RectificationStage::Applied->value];

        return self::only($query)->whereNotExists(function (QueryBuilder $sub) use ($table, $stages) {
            $sub->selectRaw('1')->from('rectification_requests as rr')
                ->whereColumn('rr.employee_id', $table.'.employee_id')
                ->whereColumn('rr.date', $table.'.date')
                ->whereIn('rr.stage', $stages);
        });
    }
}
