<?php

namespace App\Domain\Workforce;

use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\LegalParameters;
use App\Domain\Legal\ShiftClassifier;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ShiftKind;
use App\Models\Schedule;
use App\Models\ScheduleSegment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Valida un horario contra la jornada máxima legal vigente (LFT arts. 60–61 y
 * reforma de reducción gradual de la jornada ordinaria semanal).
 *
 *  - Minutos semanales programados ≤ máximo ordinario semanal de la versión legal
 *    vigente en cada fecha evaluada (hoy y la vigencia indicada).
 *  - Jornada diaria (suma de segmentos del día) ≤ máximo diario de su tipo
 *    (diurna, nocturna o mixta). Se toma el tipo más restrictivo entre el
 *    declarado en el horario y el que resulta de las horas reales.
 */
final class ScheduleLegalValidator
{
    public function __construct(
        private readonly LegalFramework $law,
        private readonly ScheduleResolver $resolver,
        private readonly ShiftClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  cycle_length_days, shift_kind
     * @param  list<array{day_index:int, ordinal?:int, starts_at:string, ends_at:string, break_minutes?:int}>  $segments
     * @param  list<string>  $dates  fechas civiles a evaluar
     * @return array{weekly_minutes:int, checks:list<array{date:string, version:string, weekly_max:int, ok:bool}>, violations:array<string, list<string>>}
     */
    public function evaluate(array $attributes, array $segments, array $dates): array
    {
        $schedule = $this->makeSchedule($attributes, $segments);
        $weekly = $this->resolver->weeklyScheduledMinutes($schedule);
        $declared = $schedule->shift_kind instanceof ShiftKind ? $schedule->shift_kind : ShiftKind::from((string) ($schedule->shift_kind ?: 'day'));

        $violations = [];
        $checks = [];

        foreach (array_values(array_unique($dates)) as $date) {
            $params = $this->law->for($date);
            $ok = $weekly <= $params->weeklyOrdinaryMaxMinutes;
            $checks[] = ['date' => $date, 'version' => $params->versionCode, 'weekly_max' => $params->weeklyOrdinaryMaxMinutes, 'ok' => $ok];

            if (! $ok) {
                $violations['segments'][] = sprintf(
                    'El horario programa %s semanales y la jornada ordinaria máxima vigente al %s es de %s (%s).',
                    self::hours($weekly), CarbonImmutable::parse($date)->format('d/m/Y'), self::hours($params->weeklyOrdinaryMaxMinutes), $params->versionCode,
                );
            }

            foreach ($this->dailyTotals($segments, $params) as $day => [$minutes, $kind]) {
                $kind = $this->stricter($kind, $declared, $params);
                $max = $params->dailyMaxFor($kind);
                if ($minutes > $max) {
                    $violations['segments'][] = sprintf(
                        '%s: %s de jornada %s; el máximo legal es %s (%s).',
                        $this->dayLabel((int) $day, (int) $schedule->cycle_length_days), self::hours($minutes), self::kindLabel($kind), self::hours($max), $params->versionCode,
                    );
                }
            }
        }

        foreach ($segments as $i => $s) {
            $span = $this->span($s['starts_at'], $s['ends_at']);
            if ((int) ($s['break_minutes'] ?? 0) >= $span) {
                $violations["segments.{$i}.break_minutes"][] = 'El descanso no puede ser igual o mayor que la duración del segmento.';
            }
        }

        if (isset($violations['segments'])) {
            $violations['segments'] = array_values(array_unique($violations['segments']));
        }

        return ['weekly_minutes' => $weekly, 'checks' => $checks, 'violations' => $violations];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{day_index:int, ordinal?:int, starts_at:string, ends_at:string, break_minutes?:int}>  $segments
     * @param  list<string>  $dates
     *
     * @throws ValidationException
     */
    public function assertValid(array $attributes, array $segments, array $dates): int
    {
        $result = $this->evaluate($attributes, $segments, $dates);
        if ($result['violations'] !== []) {
            throw ValidationException::withMessages($result['violations']);
        }

        return $result['weekly_minutes'];
    }

    /**
     * Valida un horario ya guardado para una fecha de vigencia (asignaciones).
     */
    public function assertScheduleValidOn(Schedule $schedule, string $date, string $field = 'schedule_id'): void
    {
        $segments = $schedule->segments->map(fn (ScheduleSegment $s) => [
            'day_index' => (int) $s->day_index,
            'ordinal' => (int) $s->ordinal,
            'starts_at' => substr((string) $s->starts_at, 0, 5),
            'ends_at' => substr((string) $s->ends_at, 0, 5),
            'break_minutes' => (int) $s->break_minutes,
        ])->values()->all();

        $result = $this->evaluate(['cycle_length_days' => $schedule->cycle_length_days, 'shift_kind' => $schedule->shift_kind?->value], $segments, [$date]);
        if ($result['violations'] !== []) {
            throw ValidationException::withMessages([$field => array_merge(
                ["El horario «{$schedule->name}» no puede asignarse con vigencia a partir del ".CarbonImmutable::parse($date)->format('d/m/Y').'.'],
                $result['violations']['segments'] ?? [],
            )]);
        }
    }

    /**
     * Jornada diaria única (excepciones de horario).
     */
    public function assertSingleShift(string $date, string $start, string $end, int $break, string $field = 'starts_at'): void
    {
        $params = $this->law->for($date);
        $minutes = $this->span($start, $end) - $break;
        $kind = $this->classify($start, $end, $params);
        $max = $params->dailyMaxFor($kind);
        if ($minutes <= 0) {
            throw ValidationException::withMessages([$field => 'La jornada debe tener duración efectiva mayor a cero.']);
        }
        if ($minutes > $max) {
            throw ValidationException::withMessages([$field => sprintf('La jornada %s de %s excede el máximo legal de %s (%s).', self::kindLabel($kind), self::hours($minutes), self::hours($max), $params->versionCode)]);
        }
    }

    public function span(string $start, string $end): int
    {
        $a = CarbonImmutable::parse('2026-01-05 '.substr($start, 0, 5));
        $b = CarbonImmutable::parse('2026-01-05 '.substr($end, 0, 5));
        if ($b->lte($a)) {
            $b = $b->addDay();
        }

        return (int) $a->diffInMinutes($b);
    }

    public static function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h} h" : "{$h} h {$m} min";
    }

    public static function kindLabel(ShiftKind $kind): string
    {
        return match ($kind) {
            ShiftKind::Day => 'diurna',
            ShiftKind::Night => 'nocturna',
            ShiftKind::Mixed => 'mixta',
        };
    }

    /**
     * @param  list<array{day_index:int, starts_at:string, ends_at:string, break_minutes?:int}>  $segments
     * @return array<int, array{0:int, 1:ShiftKind}>
     */
    private function dailyTotals(array $segments, LegalParameters $params): array
    {
        $days = [];
        foreach ($segments as $s) {
            $day = (int) $s['day_index'];
            $minutes = $this->span($s['starts_at'], $s['ends_at']) - (int) ($s['break_minutes'] ?? 0);
            $kind = $this->classify($s['starts_at'], $s['ends_at'], $params);
            if (! isset($days[$day])) {
                $days[$day] = [0, $kind];
            }
            $days[$day][0] += $minutes;
            $days[$day][1] = $this->stricter($days[$day][1], $kind, $params);
        }

        return $days;
    }

    private function classify(string $start, string $end, LegalParameters $params): ShiftKind
    {
        $a = CarbonImmutable::parse('2026-01-05 '.substr($start, 0, 5));
        $b = CarbonImmutable::parse('2026-01-05 '.substr($end, 0, 5));
        if ($b->lte($a)) {
            $b = $b->addDay();
        }

        return $this->classifier->classify($a, $b, $params);
    }

    private function stricter(ShiftKind $a, ShiftKind $b, LegalParameters $params): ShiftKind
    {
        return $params->dailyMaxFor($a) <= $params->dailyMaxFor($b) ? $a : $b;
    }

    private function dayLabel(int $index, int $cycle): string
    {
        if ($cycle === 7) {
            return ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$index] ?? 'Día '.($index + 1);
        }

        return 'Día '.($index + 1).' del ciclo';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $segments
     */
    private function makeSchedule(array $attributes, array $segments): Schedule
    {
        $schedule = new Schedule;
        $schedule->forceFill([
            'cycle_length_days' => max(1, (int) ($attributes['cycle_length_days'] ?? 7)),
            'shift_kind' => $attributes['shift_kind'] ?? 'day',
        ]);
        $schedule->setRelation('segments', new Collection(array_map(function (array $s) {
            $segment = new ScheduleSegment;
            $segment->forceFill([
                'day_index' => (int) $s['day_index'],
                'ordinal' => (int) ($s['ordinal'] ?? 1),
                'starts_at' => substr((string) $s['starts_at'], 0, 5),
                'ends_at' => substr((string) $s['ends_at'], 0, 5),
                'break_minutes' => (int) ($s['break_minutes'] ?? 0),
            ]);

            return $segment;
        }, $segments)));

        return $schedule;
    }
}
