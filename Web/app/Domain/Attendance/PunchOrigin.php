<?php

namespace App\Domain\Attendance;

use App\Enums\PunchSource;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Illuminate\Support\Collection;

/**
 * «Origen del marcaje» (Revisión RH, pantalla 03): distingue siempre lo que el
 * colaborador marcó de lo que se ajustó con su firma.
 */
final class PunchOrigin
{
    public const APP_INSIDE = 'app_inside';

    public const APP_OUTSIDE = 'app_outside';

    public const APP = 'app';

    public const OFFLINE = 'offline';

    public const KIOSK = 'kiosk';

    public const WEB = 'web';

    public const IMPORT = 'import';

    public const RECTIFIED = 'rectified';

    public const OMISSION = 'omission';

    public const SYSTEM_HISTORIC = 'system_historic';

    /** @var array<string, string> */
    public const LABELS = [
        self::APP_INSIDE => 'App · dentro de geocerca',
        self::APP_OUTSIDE => 'App · fuera de geocerca',
        self::APP => 'App',
        self::OFFLINE => 'Sin conexión',
        self::KIOSK => 'Kiosco',
        self::WEB => 'Web',
        self::IMPORT => 'Importación',
        self::RECTIFIED => 'Rectificado (firmado por el colaborador)',
        self::OMISSION => 'Omisión · requiere rectificación',
        self::SYSTEM_HISTORIC => 'Generado por sistema (histórico)',
    ];

    /**
     * Origen de un marcaje original.
     */
    public static function forPunch(Punch $punch): string
    {
        $anomalies = (array) $punch->anomalies;

        return match (true) {
            $punch->source === PunchSource::System => self::SYSTEM_HISTORIC,
            $punch->captured_offline || $punch->source === PunchSource::MobileOffline => self::OFFLINE,
            $punch->source === PunchSource::Kiosk => self::KIOSK,
            $punch->source === PunchSource::Web => self::WEB,
            $punch->source === PunchSource::Import => self::IMPORT,
            in_array('outside_geofence', $anomalies, true) => self::APP_OUTSIDE,
            $punch->geofence_id !== null => self::APP_INSIDE,
            default => self::APP,
        };
    }

    /**
     * Origen resumido de una jornada a partir de sus marcajes originales.
     * Prioridad: rectificado > omisión > histórico de sistema > el menos confiable.
     *
     * @param  Collection<int, Punch>  $punches
     */
    public static function forWorkday(WorkdaySummary $summary, Collection $punches): ?string
    {
        if ($summary->has_rectifications) {
            return self::RECTIFIED;
        }
        if (array_intersect((array) $summary->anomalies, Omissions::ANOMALIES) !== []) {
            return self::OMISSION;
        }
        if ($punches->isEmpty()) {
            return null;
        }
        $origins = $punches->map(fn (Punch $p) => self::forPunch($p))->unique()->all();
        foreach ([self::SYSTEM_HISTORIC, self::APP_OUTSIDE, self::OFFLINE, self::IMPORT, self::WEB, self::KIOSK, self::APP, self::APP_INSIDE] as $code) {
            if (in_array($code, $origins, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return array{code:string, label:string}|null
     */
    public static function present(?string $code): ?array
    {
        return $code === null ? null : ['code' => $code, 'label' => self::LABELS[$code]];
    }
}
