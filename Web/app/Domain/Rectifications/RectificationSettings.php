<?php

namespace App\Domain\Rectifications;

use App\Models\Holiday;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Parámetros de rectificaciones por organización (`organization_settings`):
 *
 *  - `rectifications.signature`: {business_days, confirmed_by_hr}. Plazo para que el
 *    colaborador firme. Valor inicial 3 días hábiles, PENDIENTE de confirmar por RH.
 *  - `payroll.close`: {closed_through}. Fecha (inclusive) hasta la que la nómina ya
 *    se cerró. Las rectificaciones de fechas cerradas son «extemporáneas» y sólo
 *    RH las aplica con `rectifications.apply_late`.
 *
 * Se lee dentro del contexto de la organización activa.
 */
final class RectificationSettings
{
    public const SIGNATURE_KEY = 'rectifications.signature';

    public const PAYROLL_KEY = 'payroll.close';

    public const DEFAULT_BUSINESS_DAYS = 3;

    /**
     * @return array{business_days:int, confirmed_by_hr:bool, closed_through:?string}
     */
    public function all(): array
    {
        $rows = OrganizationSetting::query()->whereIn('key', [self::SIGNATURE_KEY, self::PAYROLL_KEY])->pluck('value', 'key');
        $sig = (array) ($rows[self::SIGNATURE_KEY] ?? []);
        $pay = (array) ($rows[self::PAYROLL_KEY] ?? []);

        return [
            'business_days' => max(1, min(15, (int) ($sig['business_days'] ?? self::DEFAULT_BUSINESS_DAYS))),
            'confirmed_by_hr' => (bool) ($sig['confirmed_by_hr'] ?? false),
            'closed_through' => isset($pay['closed_through']) && is_string($pay['closed_through']) ? $pay['closed_through'] : null,
        ];
    }

    /**
     * @param  array{business_days:int, confirmed_by_hr:bool, closed_through:?string}  $values
     */
    public function save(array $values, ?int $userId): void
    {
        foreach ([
            self::SIGNATURE_KEY => ['business_days' => $values['business_days'], 'confirmed_by_hr' => $values['confirmed_by_hr']],
            self::PAYROLL_KEY => ['closed_through' => $values['closed_through']],
        ] as $key => $value) {
            $setting = OrganizationSetting::query()->firstOrNew(['key' => $key]);
            $setting->value = $value;
            $setting->updated_by = $userId;
            $setting->save();
        }
    }

    /**
     * Fin del plazo de firma: último instante del N-ésimo día hábil siguiente
     * (sin sábados, domingos ni días de descanso obligatorio), en la zona de la organización.
     */
    public function signatureDueAt(Organization $organization, CarbonInterface $from): CarbonImmutable
    {
        $tz = $organization->timezone ?: 'America/Mexico_City';
        $day = CarbonImmutable::instance($from)->setTimezone($tz)->startOfDay();
        $remaining = $this->all()['business_days'];

        $holidays = Holiday::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organization->id))
            ->whereNull('work_center_id')
            ->where('is_mandatory_rest', true)
            ->whereBetween('date', [$day->toDateString(), $day->addDays($remaining * 3 + 14)->toDateString()])
            ->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();

        while ($remaining > 0) {
            $day = $day->addDay();
            if ($day->isWeekend() || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }
            $remaining--;
        }

        return $day->endOfDay()->utc();
    }

    /**
     * La fecha ya pertenece a una nómina cerrada (rectificación extemporánea).
     */
    public function isAfterPayrollClose(CarbonInterface|string $date): bool
    {
        $closed = $this->all()['closed_through'];

        return $closed !== null && CarbonImmutable::parse($date)->toDateString() <= $closed;
    }
}
