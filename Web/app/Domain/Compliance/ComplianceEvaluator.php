<?php

namespace App\Domain\Compliance;

use App\Domain\Legal\LegalFramework;
use App\Domain\Legal\LegalParameters;
use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Enums\AlertSeverity;
use App\Enums\ApprovalStatus;
use App\Enums\WorkdayStatus;
use App\Models\ComplianceAlert;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;

/**
 * Evalúa reglas legales sobre las proyecciones diarias y semanales.
 * Una infracción genera alerta; nunca altera ni elimina tiempo registrado.
 */
final class ComplianceEvaluator
{
    public function __construct(
        private readonly WeeklyOvertimeCalculator $overtime,
        private readonly LegalFramework $law,
    ) {}

    public function evaluateDay(Employee $employee, WorkdaySummary $day, LegalParameters $law): void
    {
        $date = $day->date->toDateString();
        $anomalies = (array) $day->anomalies;

        $this->toggle($employee, $date, 'missing_clock_out', in_array('missing_clock_out', $anomalies, true), AlertSeverity::Warning,
            'Omisión de checada de salida: requiere rectificación firmada.', 'LFT art. 132 fr. XXXIV (registro de inicio y fin)');

        $this->toggle($employee, $date, 'missing_clock_in', in_array('missing_clock_in', $anomalies, true), AlertSeverity::Warning,
            'Omisión de checada de entrada: requiere rectificación firmada.', 'LFT art. 132 fr. XXXIV (registro de inicio y fin)');

        $this->toggle($employee, $date, 'daily_total_exceeded', $law->dailyTotalMax !== null && $day->worked_minutes > $law->dailyTotalMax, AlertSeverity::Critical,
            'La jornada total (ordinaria + extraordinaria) rebasa el máximo diario legal.', 'LFT art. 68 (reforma DOF 01-05-2026)', ['worked_minutes' => $day->worked_minutes, 'limit' => $law->dailyTotalMax]);

        $this->toggle($employee, $date, 'rest_day_worked', $day->status === WorkdayStatus::RestDayWorked, AlertSeverity::Info,
            'Se trabajó en día de descanso: corresponde pago adicional.', 'LFT art. 73');

        $this->toggle($employee, $date, 'holiday_worked', $day->status === WorkdayStatus::HolidayWorked, AlertSeverity::Info,
            'Se trabajó en día de descanso obligatorio: corresponde pago adicional.', 'LFT art. 75');

        $this->toggle($employee, $date, 'sunday_worked', $day->worked_minutes > 0 && $day->date->isSunday(), AlertSeverity::Info,
            'Trabajo en domingo: aplica prima dominical mínima de '.$law->sundayPremiumMinPercent.'%.', 'LFT art. 71');

        $age = $employee->birth_date?->diffInYears($day->date);
        $isMinor = $age !== null && $age < 18;
        $this->toggle($employee, $date, 'minor_night_work', $isMinor && ! $law->minorsNightWorkAllowed && $day->night_minutes > 0, AlertSeverity::Critical,
            'Trabajo nocturno de persona menor de 18 años.', 'LFT art. 175');
        $this->toggle($employee, $date, 'minor_overtime', $isMinor && ! $law->minorsOvertimeAllowed && $day->excess_minutes > 0, AlertSeverity::Critical,
            'Tiempo extraordinario de persona menor de 18 años.', 'LFT art. 178; CPEUM art. 123 A fr. XI');
        $this->toggle($employee, $date, 'minor_daily_limit', $age !== null && $age < 16 && $day->worked_minutes > $law->minorsMaxDailyMinutesUnder16, AlertSeverity::Critical,
            'Jornada superior al máximo permitido para menores de 16 años.', 'LFT art. 177');

        $this->evaluateWeek($employee, CarbonImmutable::parse($date));
    }

    /**
     * Recalcula las horas extraordinarias de la semana (lunes a domingo) y alertas semanales.
     */
    public function evaluateWeek(Employee $employee, CarbonImmutable $anyDay): void
    {
        $weekStart = $anyDay->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);
        $law = $this->law->for($weekStart);

        $days = WorkdaySummary::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')
            ->get();

        // Mismo cálculo semanal por colaborador que Panel, Horas extra y NOI.
        $input = [];
        foreach ($days as $d) {
            $input[$d->date->toDateString()] = [
                'worked' => (int) $d->worked_minutes,
                'excess' => (int) $d->excess_minutes,
                'minor' => WeeklyOvertimeCalculator::isMinorOn($employee, $d->date) === true,
            ];
        }
        $classified = $this->overtime->classify($input, $law);

        foreach ($classified as $date => $c) {
            $record = OvertimeRecord::query()->firstOrNew(['employee_id' => $employee->id, 'date' => $date]);
            if ($c['excess'] === 0 && ! $record->exists) {
                continue;
            }
            $changed = $record->exists && (int) $record->recorded_excess_minutes !== $c['excess'];
            $record->fill([
                'organization_id' => $employee->organization_id,
                'week_start' => $weekStart->toDateString(),
                'recorded_excess_minutes' => $c['excess'],
                'double_minutes' => $c['double'],
                'triple_minutes' => $c['triple'],
                'over_limit_minutes' => $c['over_limit'],
                'legal_version_code' => $law->versionCode,
            ]);
            if (! $record->exists || $changed) {
                // Todo tiempo extra registrado queda «pendiente» para autorizar o
                // justificar; si cambia, la decisión previa debe revisarse.
                $record->status = ApprovalStatus::Pending;
                $record->authorized_minutes = max(0, min((int) $record->authorized_minutes, $c['excess']));
            }
            $record->save();

            $this->toggle($employee, $date, 'overtime_over_limit', $c['over_limit'] > 0, AlertSeverity::Critical,
                'Tiempo extraordinario que rebasa los límites legales.', 'LFT arts. 66 y 68', ['over_limit_minutes' => $c['over_limit']]);
        }

        // Tiempo extra cerca del tope semanal (≥ 80 %), sin haberlo rebasado: alerta preventiva.
        $weekExcess = (int) collect($classified)->sum('excess');
        $weekOver = (int) collect($classified)->sum('over_limit');
        $nearThreshold = (int) ceil($law->overtimeWeeklyMax * 0.8);
        $this->toggle($employee, $weekStart->toDateString(), 'overtime_near_limit', $law->overtimeWeeklyMax > 0 && $weekOver === 0 && $weekExcess >= $nearThreshold, AlertSeverity::Warning,
            'Tiempo extra de la semana cerca del tope legal ('.intdiv($law->overtimeWeeklyMax, 60).' h).', 'LFT art. 66',
            ['overtime_minutes' => $weekExcess, 'limit' => $law->overtimeWeeklyMax]);

        $ordinary = $days->sum(fn (WorkdaySummary $d) => max(0, (int) $d->worked_minutes - (int) $d->excess_minutes));
        $this->toggle($employee, $weekStart->toDateString(), 'weekly_ordinary_exceeded', $ordinary > $law->weeklyOrdinaryMaxMinutes, AlertSeverity::Warning,
            'La jornada ordinaria semanal rebasa el máximo legal vigente ('.intdiv($law->weeklyOrdinaryMaxMinutes, 60).' h).',
            'CPEUM art. 123 A fr. IV; LFT art. 61', ['ordinary_minutes' => $ordinary, 'limit' => $law->weeklyOrdinaryMaxMinutes]);

        $worked = $days->filter(fn (WorkdaySummary $d) => $d->worked_minutes > 0)->count();
        $this->toggle($employee, $weekStart->toDateString(), 'no_weekly_rest', $days->count() === 7 && $worked === 7, AlertSeverity::Warning,
            'Siete días trabajados sin día de descanso en la semana.', 'LFT art. 69');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toggle(Employee $employee, string $date, string $rule, bool $active, AlertSeverity $severity, string $message, string $basis, array $data = []): void
    {
        $alert = ComplianceAlert::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('rule_code', $rule)
            ->first();

        if ($active) {
            if ($alert === null) {
                ComplianceAlert::query()->create([
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'rule_code' => $rule,
                    'severity' => $severity,
                    'message' => $message,
                    'legal_basis' => $basis,
                    'data' => $data ?: null,
                    'status' => 'open',
                ]);
            } elseif ($alert->status === 'resolved' && $alert->resolved_by === null) {
                $alert->update(['status' => 'open', 'resolved_at' => null, 'resolution_note' => null, 'data' => $data ?: null]);
            }

            return;
        }

        if ($alert !== null && $alert->status === 'open') {
            $alert->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => 'La condición dejó de presentarse tras el recálculo.']);
        }
    }
}
