<?php

namespace App\Domain\Legal;

use App\Enums\ShiftKind;
use Carbon\CarbonImmutable;

/**
 * Clasifica una jornada (LFT art. 60):
 *  - Diurna: entre 06:00 y 20:00.
 *  - Nocturna: entre 20:00 y 06:00.
 *  - Mixta: comprende periodos de ambas, siempre que el nocturno sea menor de
 *    3 h 30 min (`mixedMaxNightMinutes` = 209 = máximo nocturno de una mixta);
 *    si lo excede, se reputa nocturna.
 */
final class ShiftClassifier
{
    /**
     * @param  CarbonImmutable  $start  hora local de inicio
     * @param  CarbonImmutable  $end  hora local de fin (> start)
     */
    public function classify(CarbonImmutable $start, CarbonImmutable $end, LegalParameters $law): ShiftKind
    {
        $total = (int) $start->diffInMinutes($end);
        $night = $this->nightMinutes($start, $end, $law);

        if ($night === 0) {
            return ShiftKind::Day;
        }
        if ($night >= $total || $night > $law->mixedMaxNightMinutes) {
            return ShiftKind::Night;
        }

        return ShiftKind::Mixed;
    }

    /**
     * Minutos del intervalo [start, end) que caen en la ventana nocturna legal.
     */
    public function nightMinutes(CarbonImmutable $start, CarbonImmutable $end, LegalParameters $law): int
    {
        if ($end->lte($start)) {
            return 0;
        }

        [$nh, $nm] = array_map('intval', explode(':', $law->nightStart));
        [$eh, $em] = array_map('intval', explode(':', $law->nightEnd));

        $minutes = 0;
        // Recorre ventanas nocturnas que inician el día anterior al inicio hasta el día del fin.
        for ($day = $start->startOfDay()->subDay(); $day->lte($end); $day = $day->addDay()) {
            $windowStart = $day->setTime($nh, $nm);
            $windowEnd = $day->addDay()->setTime($eh, $em);
            $from = $start->max($windowStart);
            $to = $end->min($windowEnd);
            if ($to->gt($from)) {
                $minutes += (int) $from->diffInMinutes($to);
            }
        }

        return $minutes;
    }
}
