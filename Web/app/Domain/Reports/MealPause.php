<?php

namespace App\Domain\Reports;

use App\Models\OrganizationSetting;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Pausa del día que corresponde a la comida (fuente única para reportes y cálculo).
 *
 * El registro distingue pausas (inicio/fin de descanso); cuál de ellas es la comida
 * lo define RH por organización en Configuración › Nómina (ajuste `reports.meal_pause`:
 * {pause, confirmed_by_hr}). 0 = la organización no distingue comida: todas las pausas
 * se reportan como descanso. Sin ajuste guardado se usa el valor inicial de
 * `config('reports.meal_pause')` (`REPORTS_MEAL_PAUSE`), marcado como pendiente de RH.
 */
final class MealPause
{
    public const SETTING_KEY = 'reports.meal_pause';

    /** Número máximo de pausa seleccionable (1 = primera pausa del día). */
    public const MAX = 4;

    /**
     * @return array{pause:int, confirmed_by_hr:bool, configured:bool}
     */
    public static function settings(?int $organizationId = null): array
    {
        $value = self::stored($organizationId);
        if (! is_array($value)) {
            return ['pause' => self::clamp((int) config('reports.meal_pause', 0)), 'confirmed_by_hr' => false, 'configured' => false];
        }

        return [
            'pause' => self::clamp((int) ($value['pause'] ?? 0)),
            'confirmed_by_hr' => (bool) ($value['confirmed_by_hr'] ?? false),
            'configured' => true,
        ];
    }

    /**
     * Número de pausa de comida vigente para la organización (0 = no se distingue).
     */
    public static function current(?int $organizationId = null): int
    {
        return self::settings($organizationId)['pause'];
    }

    /**
     * Guarda el ajuste de la organización activa.
     */
    public static function save(int $pause, bool $confirmedByHr, ?int $userId): void
    {
        $setting = OrganizationSetting::query()->firstOrNew(['key' => self::SETTING_KEY]);
        $setting->value = ['pause' => self::clamp($pause), 'confirmed_by_hr' => $confirmedByHr];
        $setting->updated_by = $userId;
        $setting->save();
    }

    private static function clamp(int $pause): int
    {
        return max(0, min(self::MAX, $pause));
    }

    /**
     * Valor guardado. Con organización explícita no depende de la organización activa
     * (reportes en cola); sin ella y sin organización activa no se lee ningún ajuste.
     */
    private static function stored(?int $organizationId): mixed
    {
        $organizationId ??= app(CurrentOrganization::class)->id();
        if ($organizationId === null) {
            return null;
        }

        return OrganizationSetting::query()->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('key', self::SETTING_KEY)
            ->value('value');
    }
}
