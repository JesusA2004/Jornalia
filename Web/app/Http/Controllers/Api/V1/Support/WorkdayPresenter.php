<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\PolicyResolver;
use App\Domain\Attendance\WorkdayCalculator;
use App\Domain\Legal\LegalFramework;
use App\Domain\Location\LocationTracking;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\WorkdayStatus;
use App\Http\Resources\V1\GeofenceResource;
use App\Http\Resources\V1\IncidenceResource;
use App\Http\Resources\V1\PunchResource;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Presenta la jornada, la semana y la política en las formas del contrato API v1.
 * Toda la lógica de cálculo vive en el dominio (WorkdayCalculator, ScheduleResolver…);
 * aquí sólo se traduce el estado interno al vocabulario del contrato.
 */
final class WorkdayPresenter
{
    public function __construct(
        private readonly WorkdayCalculator $calculator,
        private readonly ScheduleResolver $schedules,
        private readonly EffectiveTimeline $timeline,
        private readonly PolicyResolver $policies,
        private readonly LegalFramework $law,
    ) {}

    public function timezone(Employee $employee): string
    {
        return $this->schedules->timezoneFor($employee);
    }

    public function today(Employee $employee): string
    {
        return CarbonImmutable::now($this->timezone($employee))->toDateString();
    }

    public function nextExpected(Employee $employee): PunchType
    {
        return $this->timeline->nextExpected($employee->id, CarbonImmutable::now('UTC'));
    }

    /**
     * Traduce el estado interno de la proyección al `WorkdayStatus` del contrato.
     */
    public static function contractStatus(WorkdayStatus $status, bool $isToday, ?PunchType $nextExpected): string
    {
        return match ($status) {
            WorkdayStatus::Incomplete => $isToday ? ($nextExpected === PunchType::BreakEnd ? 'on_break' : 'working') : 'incomplete',
            WorkdayStatus::Present, WorkdayStatus::Late, WorkdayStatus::RestDayWorked, WorkdayStatus::HolidayWorked => 'finished',
            WorkdayStatus::RestDay => 'rest_day',
            WorkdayStatus::Holiday => 'holiday',
            WorkdayStatus::Leave, WorkdayStatus::Vacation => 'on_leave',
            WorkdayStatus::Absent => $isToday ? 'not_started' : 'absent',
            WorkdayStatus::Unscheduled, WorkdayStatus::Future => 'not_started',
        };
    }

    /**
     * Contrato `Workday` calculado en vivo (sin persistir) para una fecha civil.
     *
     * @return array<string, mixed>
     */
    public function workday(Employee $employee, string $date): array
    {
        $summary = $this->calculator->compute($employee, $date, persist: false);
        $shift = $this->schedules->shiftFor($employee, $date);
        $isToday = $date === $this->today($employee);
        $next = $isToday ? $this->nextExpected($employee) : null;

        $punches = PunchQuery::forEmployee($employee->id)
            ->whereDate('local_date', $date)
            ->orderBy('server_time')
            ->get();

        $incidences = Incidence::query()->with('type')
            ->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->orderBy('id')
            ->get();

        return [
            'date' => $date,
            'status' => self::contractStatus($summary->status, $isToday, $next),
            'shift' => $shift?->toArray(),
            'punches' => $this->punches($punches),
            'next_expected' => $next?->value,
            'worked_minutes' => (int) $summary->worked_minutes,
            'break_minutes' => (int) $summary->break_minutes,
            'late_minutes' => (int) $summary->late_minutes,
            'excess_minutes' => (int) $summary->excess_minutes,
            // Deprecados (salida automática eliminada): se conservan para apps instaladas.
            'auto_clock_out_at' => null,
            'after_cutoff_minutes' => 0,
            'incidences' => $incidences->map(fn (Incidence $i) => IncidenceResource::summary($i))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Punch>  $punches
     * @return list<array<string, mixed>>
     */
    public function punches(Collection $punches): array
    {
        return array_values(PunchResource::collection($punches)->resolve());
    }

    /**
     * Proyecciones por fecha: persistidas cuando existen; en vivo para hoy y
     * para fechas sin proyección.
     *
     * @param  list<string>  $dates
     * @return array<string, WorkdaySummary>
     */
    public function summaries(Employee $employee, array $dates, string $today): array
    {
        if ($dates === []) {
            return [];
        }

        $stored = WorkdaySummary::query()
            ->where('employee_id', $employee->id)
            ->whereIn('date', $dates)
            ->get()
            ->keyBy(fn (WorkdaySummary $s) => $s->date->toDateString());

        $result = [];
        foreach ($dates as $date) {
            $result[$date] = ($date !== $today && $stored->has($date))
                ? $stored->get($date)
                : $this->calculator->compute($employee, $date, persist: false);
        }

        return $result;
    }

    /**
     * Contrato `WeekSummary` (semana lunes–domingo que contiene `$today`).
     *
     * @return array<string, mixed>
     */
    public function week(Employee $employee, string $today): array
    {
        $start = CarbonImmutable::parse($today)->startOfWeek(CarbonInterface::MONDAY);
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $start->addDays($i)->toDateString();
        }

        $next = $this->nextExpected($employee);
        $summaries = $this->summaries($employee, $dates, $today);

        $worked = 0;
        $scheduled = 0;
        $days = [];
        foreach ($summaries as $date => $s) {
            $worked += (int) $s->worked_minutes;
            $scheduled += (int) $s->scheduled_minutes;
            $days[] = [
                'date' => $date,
                'status' => self::contractStatus($s->status, $date === $today, $next),
                'worked_minutes' => (int) $s->worked_minutes,
            ];
        }

        $overtime = (int) OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('week_start', $start->toDateString())
            ->sum('authorized_minutes');

        return [
            'week_start' => $start->toDateString(),
            'worked_minutes' => $worked,
            'scheduled_minutes' => $scheduled,
            'legal_max_minutes' => $this->law->for($start)->weeklyOrdinaryMaxMinutes,
            'overtime_minutes' => $overtime,
            'days' => $days,
        ];
    }

    /**
     * Contrato `AttendancePolicy`.
     *
     * @return array<string, mixed>
     */
    public function policy(Employee $employee): array
    {
        $assignment = $employee->assignmentOn(now());
        $policy = $this->policies->for($employee, $assignment);
        $fences = $this->policies->geofences($policy, $assignment);

        return [
            'location_mode' => $policy->location_mode->value,
            'allowed_auth_methods' => array_values((array) $policy->allowed_auth_methods),
            'require_strong_biometric' => (bool) $policy->require_strong_biometric,
            'allow_offline' => (bool) $policy->allow_offline,
            'offline_max_hours' => (int) $policy->offline_max_hours,
            'geofences' => array_values(GeofenceResource::collection($fences)->resolve()),
            'face_verification_enabled' => (bool) $policy->face_verification_required,
            // Parámetro por puesto (B2): sólo al marcar o seguimiento durante la jornada.
            'location_tracking' => app(LocationTracking::class)->modeFor($employee),
            'tracking_paused_states' => LocationTracking::PAUSED_STATES,
            'location_contingency_minutes' => app(LocationTracking::class)->settings()['contingency_minutes'],
        ];
    }
}
