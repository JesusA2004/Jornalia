<?php

namespace App\Domain\Legal;

/**
 * Clasificación semanal del tiempo excedente (LFT arts. 66–68 y transitorios
 * aplicables según la versión legal vigente).
 *
 * Reglas (parametrizadas por LegalParameters):
 *  - Hasta `overtimeDailyMax` minutos por día y `overtimeMaxDaysPerWeek` días,
 *    con tope semanal `overtimeWeeklyMax`: se pagan con `overtimeDoubleMultiplier`.
 *  - Lo que exceda el tope semanal se paga con `overtimeExcessMultiplier`;
 *    es infracción si rebasa `overtimeExcessWeeklyMax` (0 = cualquier excedente).
 *  - Todo minuto que rebase cualquier límite (diario, días, semanal) se marca en
 *    `over_limit` para generar alerta. NUNCA se elimina tiempo registrado.
 */
final class OvertimeCalculator
{
    /**
     * @param  array<string, int>  $dailyExcess  fecha (Y-m-d, en orden) => minutos excedentes registrados
     * @return array<string, array{excess:int, double:int, triple:int, over_limit:int}>
     */
    public function classifyWeek(array $dailyExcess, LegalParameters $law): array
    {
        ksort($dailyExcess);
        $result = [];
        $weeklyDouble = 0;
        $weeklyTriple = 0;
        $daysWithOvertime = 0;

        foreach ($dailyExcess as $date => $minutes) {
            $minutes = max(0, (int) $minutes);
            $double = 0;
            $triple = 0;
            $overLimit = 0;

            if ($minutes > 0) {
                $daysWithOvertime++;

                $dailyOver = max(0, $minutes - $law->overtimeDailyMax);
                $daysOver = $daysWithOvertime > $law->overtimeMaxDaysPerWeek;

                $doubleCapacity = max(0, $law->overtimeWeeklyMax - $weeklyDouble);
                $double = min($minutes, $doubleCapacity);
                $triple = $minutes - $double;
                $weeklyDouble += $double;

                // Excedente pagado al triple: tolerado hasta `overtimeExcessWeeklyMax`
                // (LFT art. 68 reformado); lo que lo rebase es infracción.
                $tripleAllowed = max(0, $law->overtimeExcessWeeklyMax - $weeklyTriple);
                $tripleOver = max(0, $triple - $tripleAllowed);
                $weeklyTriple += $triple;

                $overLimit = $daysOver ? $minutes : max($dailyOver, $tripleOver);
            }

            $result[$date] = [
                'excess' => $minutes,
                'double' => $double,
                'triple' => $triple,
                'over_limit' => $overLimit,
            ];
        }

        return $result;
    }
}
