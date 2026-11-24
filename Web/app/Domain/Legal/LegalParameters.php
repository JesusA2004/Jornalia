<?php

namespace App\Domain\Legal;

use App\Enums\ShiftKind;
use App\Models\LegalVersion;

/**
 * Parámetros legales inmutables de una versión normativa vigente en una fecha.
 * Los valores provienen de `legal_versions.parameters` (sembrados desde
 * `database/data/legal/parametros_legales.json`, con fuentes y estado de verificación).
 */
final readonly class LegalParameters
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $versionCode,
        public string $verificationStatus,
        public int $weeklyOrdinaryMaxMinutes,
        public int $dailyMaxDay,
        public int $dailyMaxNight,
        public int $dailyMaxMixed,
        public string $nightStart,
        public string $nightEnd,
        public int $mixedMaxNightMinutes,
        public int $minBreakMinutes,
        public int $overtimeDailyMax,
        public int $overtimeWeeklyMax,
        public int $overtimeMaxDaysPerWeek,
        public int $overtimeExcessWeeklyMax,
        public ?int $dailyTotalMax,
        public string $overtimeDoubleMultiplier,
        public string $overtimeExcessMultiplier,
        public int $restDaysPerWeek,
        public string $sundayPremiumMinPercent,
        public string $restDayWorkMultiplier,
        public string $holidayWorkMultiplier,
        public string $vacationPremiumMinPercent,
        public int $minorsMinWorkingAge,
        public int $minorsMaxDailyMinutesUnder16,
        public bool $minorsNightWorkAllowed,
        public bool $minorsOvertimeAllowed,
        public array $raw,
    ) {}

    /**
     * Construye los parámetros desde una versión normativa. «Verificada» sólo si
     * RH registró usuario y fecha reales de validación.
     */
    public static function fromVersion(LegalVersion $version): self
    {
        /** @var array<string, mixed> $p */
        $p = $version->parameters;
        $daily = (array) ($p['daily_max_minutes'] ?? []);
        $night = (array) ($p['night_window'] ?? []);
        $ot = (array) ($p['overtime'] ?? []);
        $minors = (array) ($p['minors'] ?? []);

        return new self(
            versionCode: $version->code,
            // «Verificada» exige usuario y fecha reales de validación por RH.
            verificationStatus: $version->isVerified() ? LegalVersion::STATUS_VERIFIED : LegalVersion::STATUS_UNVERIFIED,
            weeklyOrdinaryMaxMinutes: (int) $p['weekly_ordinary_max_minutes'],
            dailyMaxDay: (int) ($daily['day'] ?? 480),
            dailyMaxNight: (int) ($daily['night'] ?? 420),
            dailyMaxMixed: (int) ($daily['mixed'] ?? 450),
            nightStart: (string) ($night['start'] ?? '20:00'),
            nightEnd: (string) ($night['end'] ?? '06:00'),
            mixedMaxNightMinutes: (int) ($p['mixed_max_night_minutes'] ?? 210),
            minBreakMinutes: (int) ($p['min_break_minutes'] ?? 30),
            overtimeDailyMax: (int) ($ot['daily_max_minutes'] ?? 180),
            overtimeWeeklyMax: (int) ($ot['weekly_max_minutes'] ?? 540),
            overtimeMaxDaysPerWeek: (int) ($ot['max_days_per_week'] ?? 3),
            overtimeExcessWeeklyMax: (int) ($ot['excess_weekly_max_minutes'] ?? 0),
            dailyTotalMax: isset($p['daily_total_max_minutes']) ? (int) $p['daily_total_max_minutes'] : null,
            overtimeDoubleMultiplier: (string) ($ot['double_rate_multiplier'] ?? '2.00'),
            overtimeExcessMultiplier: (string) ($ot['excess_rate_multiplier'] ?? '3.00'),
            restDaysPerWeek: (int) ($p['rest_days_per_week'] ?? 1),
            sundayPremiumMinPercent: (string) ($p['sunday_premium_min_percent'] ?? '25.00'),
            restDayWorkMultiplier: (string) ($p['rest_day_work_multiplier'] ?? '2.00'),
            holidayWorkMultiplier: (string) ($p['holiday_work_multiplier'] ?? '2.00'),
            vacationPremiumMinPercent: (string) ($p['vacation_premium_min_percent'] ?? '25.00'),
            minorsMinWorkingAge: (int) ($minors['min_working_age'] ?? 15),
            minorsMaxDailyMinutesUnder16: (int) ($minors['max_daily_minutes_under_16'] ?? 360),
            minorsNightWorkAllowed: (bool) ($minors['night_work_allowed_under_18'] ?? false),
            minorsOvertimeAllowed: (bool) ($minors['overtime_allowed_under_18'] ?? false),
            raw: $p,
        );
    }

    /**
     * Jornada ordinaria máxima diaria en minutos según el tipo de jornada
     * (diurna, nocturna o mixta, LFT arts. 60–61).
     */
    public function dailyMaxFor(ShiftKind $kind): int
    {
        return match ($kind) {
            ShiftKind::Day => $this->dailyMaxDay,
            ShiftKind::Night => $this->dailyMaxNight,
            ShiftKind::Mixed => $this->dailyMaxMixed,
        };
    }

    /**
     * ¿RH validó esta versión normativa? Si no, la UI la muestra «Sin verificar».
     */
    public function isVerified(): bool
    {
        return $this->verificationStatus === LegalVersion::STATUS_VERIFIED;
    }
}
