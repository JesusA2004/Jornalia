<?php

namespace App\Domain\Legal;

use App\Models\Holiday;
use Carbon\CarbonImmutable;

/**
 * Genera los días de descanso obligatorio nacionales (LFT art. 74) a partir de
 * reglas: fecha fija, n-ésimo día de la semana del mes y cada seis años.
 * Las jornadas electorales (`manual`) se capturan desde configuración.
 */
final class HolidayGenerator
{
    /**
     * @param  list<array<string, mixed>>  $rules
     */
    public function generate(array $rules, int $fromYear, int $toYear): int
    {
        $count = 0;
        for ($year = $fromYear; $year <= $toYear; $year++) {
            foreach ($rules as $rule) {
                $date = $this->resolve($rule, $year);
                if ($date === null) {
                    continue;
                }
                Holiday::query()->updateOrCreate(
                    ['organization_id' => null, 'work_center_id' => null, 'date' => $date->toDateString(), 'code' => $rule['code']],
                    ['name' => $rule['name'], 'kind' => 'official', 'is_mandatory_rest' => true, 'legal_basis' => $rule['article'] ?? 'LFT art. 74'],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function resolve(array $rule, int $year): ?CarbonImmutable
    {
        return match ($rule['rule']) {
            'fixed' => CarbonImmutable::create($year, (int) $rule['month'], (int) $rule['day']),
            'nth_weekday' => $this->nthWeekday($year, (int) $rule['month'], (int) $rule['weekday'], (int) $rule['nth']),
            'every_six_years' => (($year - (int) $rule['base_year']) % 6 === 0 && $year >= (int) $rule['base_year'])
                ? CarbonImmutable::create($year, (int) $rule['month'], (int) $rule['day'])
                : null,
            default => null,
        };
    }

    private function nthWeekday(int $year, int $month, int $isoWeekday, int $nth): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $offset = ($isoWeekday - $first->dayOfWeekIso + 7) % 7;

        return $first->addDays($offset + 7 * ($nth - 1));
    }
}
