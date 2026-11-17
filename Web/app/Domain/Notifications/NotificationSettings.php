<?php

namespace App\Domain\Notifications;

use App\Models\OrganizationSetting;

/**
 * Configuración de avisos automáticos por organización (organization_settings).
 *
 *  - `notifications.late_reminder`:      {enabled, grace_minutes}
 *  - `notifications.clock_out_reminder`: {enabled, minutes_after}
 *
 * Se lee dentro del contexto de la organización activa.
 */
final class NotificationSettings
{
    public const LATE_KEY = 'notifications.late_reminder';

    public const CLOCK_OUT_KEY = 'notifications.clock_out_reminder';

    /** @var array<string, array<string, bool|int>> */
    public const DEFAULTS = [
        self::LATE_KEY => ['enabled' => true, 'grace_minutes' => 10],
        self::CLOCK_OUT_KEY => ['enabled' => true, 'minutes_after' => 15],
    ];

    /**
     * @return array{late: array{enabled: bool, grace_minutes: int}, clock_out: array{enabled: bool, minutes_after: int}}
     */
    public function all(): array
    {
        $rows = OrganizationSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');
        $late = (array) ($rows[self::LATE_KEY] ?? []) + self::DEFAULTS[self::LATE_KEY];
        $out = (array) ($rows[self::CLOCK_OUT_KEY] ?? []) + self::DEFAULTS[self::CLOCK_OUT_KEY];

        return [
            'late' => ['enabled' => (bool) $late['enabled'], 'grace_minutes' => max(0, min(240, (int) $late['grace_minutes']))],
            'clock_out' => ['enabled' => (bool) $out['enabled'], 'minutes_after' => max(0, min(240, (int) $out['minutes_after']))],
        ];
    }

    /**
     * @param  array{late: array{enabled: bool, grace_minutes: int}, clock_out: array{enabled: bool, minutes_after: int}}  $values
     */
    public function save(array $values, ?int $userId): void
    {
        foreach ([self::LATE_KEY => $values['late'], self::CLOCK_OUT_KEY => $values['clock_out']] as $key => $value) {
            $setting = OrganizationSetting::query()->firstOrNew(['key' => $key]);
            $setting->value = $value;
            $setting->updated_by = $userId;
            $setting->save();
        }
    }
}
