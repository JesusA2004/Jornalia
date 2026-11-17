<?php

namespace App\Http\Controllers\Web\Operacion\Support;

use App\Domain\Attendance\Anomaly;
use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\PolicyResolver;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\AdjustmentAction;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Serializa marcajes, ajustes y jornadas para Inertia. Sin reglas laborales:
 * sólo expone lo que el dominio ya calculó.
 */
final class AttendancePresenter
{
    public function __construct(
        private readonly EffectiveTimeline $timeline,
        private readonly ScheduleResolver $schedules,
        private readonly PolicyResolver $policies,
    ) {}

    /**
     * @param  list<string>|null  $codes
     * @return list<array{code:string,label:string}>
     */
    public static function anomalies(?array $codes): array
    {
        return array_values(array_map(fn ($c) => ['code' => (string) $c, 'label' => Anomaly::labelFor((string) $c)], $codes ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    public static function punch(Punch $p, bool $detailed = false): array
    {
        $base = [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'employee_id' => $p->employee_id,
            'type' => $p->type->value,
            'type_label' => $p->type->label(),
            'server_time' => OperacionScope::iso($p->server_time),
            'device_time' => OperacionScope::iso($p->device_time),
            'local_date' => $p->local_date?->toDateString(),
            'timezone' => $p->timezone,
            'source' => $p->source->value,
            'source_label' => $p->source->label(),
            'auth_method' => $p->auth_method->value,
            'auth_method_label' => $p->auth_method->label(),
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'captured_offline' => (bool) $p->captured_offline,
            'signature_valid' => (bool) $p->signature_valid,
            'anomalies' => self::anomalies($p->anomalies),
            'has_location' => $p->latitude !== null && $p->longitude !== null,
            'sequence' => (int) $p->sequence,
            // Salida automática por política: registrada por el sistema; la hora
            // reconocida es la hora límite (device_time), no la de registro.
            'is_system' => $p->source === PunchSource::System,
            'recognized_time' => OperacionScope::iso(EffectiveTimeline::punchTime($p)),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'clock_skew_seconds' => $p->clock_skew_seconds,
            'latitude' => $p->latitude !== null ? (string) $p->latitude : null,
            'longitude' => $p->longitude !== null ? (string) $p->longitude : null,
            'accuracy_meters' => $p->accuracy_meters,
            'location_captured_at' => OperacionScope::iso($p->location_captured_at),
            'location_mocked' => $p->location_mocked,
            'geofence' => $p->geofence ? ['id' => $p->geofence->id, 'name' => $p->geofence->name, 'radius_meters' => $p->geofence->radius_meters] : null,
            'distance_meters' => $p->distance_meters,
            'face_verified' => $p->face_verification_id !== null,
            'device' => $p->device ? ['id' => $p->device->id, 'name' => $p->device->name, 'platform' => $p->device->platform, 'model' => $p->device->model] : null,
            'recorded_by' => $p->recordedBy?->name,
            'ip_address' => $p->ip_address,
            'hash' => $p->hash,
            'previous_hash' => $p->previous_hash,
            'created_at' => OperacionScope::iso($p->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adjustment(AttendanceAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'uuid' => $a->uuid,
            'action' => $a->action->value,
            'action_label' => $a->action->label(),
            'type' => $a->type?->value,
            'type_label' => $a->type?->label(),
            'target_punch_uuid' => $a->target_punch_uuid,
            'recognized_time' => OperacionScope::iso($a->recognized_time),
            'local_date' => $a->local_date?->toDateString(),
            'reason' => $a->reason,
            'approved_by' => $a->approvedBy?->name,
            'rectification_request_id' => $a->rectification_request_id,
            'sequence' => (int) $a->sequence,
            'hash' => $a->hash,
            'created_at' => OperacionScope::iso($a->created_at),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $cards
     * @return array<string, mixed>
     */
    public static function workday(WorkdaySummary $w, ?array $card = null): array
    {
        return [
            'id' => $w->id,
            'employee_id' => $w->employee_id,
            'employee' => $card,
            'date' => $w->date?->toDateString(),
            'status' => $w->status->value,
            'status_label' => $w->status->label(),
            'shift_kind' => $w->shift_kind?->value,
            'shift_kind_label' => $w->shift_kind?->label(),
            'schedule_name' => $w->relationLoaded('schedule') ? $w->schedule?->name : null,
            'branch' => $w->relationLoaded('branch') ? $w->branch?->name : null,
            'department' => $w->relationLoaded('department') ? $w->department?->name : null,
            'scheduled_start' => OperacionScope::iso($w->scheduled_start),
            'scheduled_end' => OperacionScope::iso($w->scheduled_end),
            'scheduled_minutes' => (int) $w->scheduled_minutes,
            'first_in' => OperacionScope::iso($w->first_in),
            'last_out' => OperacionScope::iso($w->last_out),
            'worked_minutes' => (int) $w->worked_minutes,
            'break_minutes' => (int) $w->break_minutes,
            'late_minutes' => (int) $w->late_minutes,
            'early_leave_minutes' => (int) $w->early_leave_minutes,
            'excess_minutes' => (int) $w->excess_minutes,
            'auto_clock_out_at' => OperacionScope::iso($w->auto_clock_out_at),
            'after_cutoff_minutes' => (int) ($w->after_cutoff_minutes ?? 0),
            'night_minutes' => (int) $w->night_minutes,
            'has_rectifications' => (bool) $w->has_rectifications,
            'anomalies' => self::anomalies($w->anomalies),
            'legal_version_code' => $w->legal_version_code,
            'computed_at' => OperacionScope::iso($w->computed_at),
        ];
    }

    /**
     * Marcajes originales, rectificaciones y horario reconocido de un día civil.
     *
     * @return array<string, mixed>
     */
    public function day(Employee $employee, string $date): array
    {
        $tz = $this->schedules->timezoneFor($employee);
        $startLocal = CarbonImmutable::parse($date, $tz)->startOfDay();
        $fromUtc = $startLocal->utc();
        $toUtc = $startLocal->addDay()->utc();

        $punches = Punch::query()->with(['device', 'geofence', 'recordedBy:id,name'])
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('local_date', $date)->orWhereBetween('server_time', [$fromUtc, $toUtc]))
            ->orderBy('server_time')->get();

        $adjustments = AttendanceAdjustment::query()->with('approvedBy:id,name')
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('local_date', $date)->orWhereIn('target_punch_uuid', $punches->pluck('uuid')))
            ->orderBy('sequence')->get();

        $events = $this->timeline->between($employee->id, $fromUtc, $toUtc);
        $shift = $this->schedules->shiftFor($employee, $date);
        $holiday = $this->schedules->holidayOn($employee, $date);

        $recognized = array_map(fn ($e) => [
            'type' => $e->type->value,
            'type_label' => $e->type->label(),
            'time' => OperacionScope::iso($e->time),
            'origin' => $e->origin,
            'origin_label' => match ($e->origin) {
                'original' => 'Marcaje original',
                'rectified' => 'Rectificado',
                'added' => 'Agregado por rectificación',
                default => $e->origin,
            },
            'punch_uuid' => $e->punch?->uuid,
            'adjustment_uuid' => $e->adjustment?->uuid,
        ], $events);

        $voided = $adjustments->filter(fn (AttendanceAdjustment $a) => $a->action === AdjustmentAction::Void)->pluck('target_punch_uuid')->filter()->values()->all();

        return [
            'date' => $date,
            'timezone' => $tz,
            'shift' => $shift ? [
                'starts_at' => OperacionScope::iso($shift->startsAt),
                'ends_at' => OperacionScope::iso($shift->endsAt),
                'kind' => $shift->kind->value,
                'kind_label' => $shift->kind->label(),
                'break_minutes' => $shift->breakMinutes,
                'tolerance_minutes' => $shift->toleranceMinutes,
                'scheduled_minutes' => $shift->scheduledMinutes(),
                'schedule_name' => $shift->scheduleName,
                'from_exception' => $shift->fromException,
            ] : null,
            'holiday' => $holiday ? ['name' => $holiday->name, 'legal_basis' => $holiday->legal_basis, 'kind' => $holiday->kind] : null,
            'punches' => $punches->map(fn (Punch $p) => self::punch($p, true) + ['voided' => in_array($p->uuid, $voided, true)])->values()->all(),
            'adjustments' => $adjustments->map(fn (AttendanceAdjustment $a) => self::adjustment($a))->values()->all(),
            'recognized' => $recognized,
            'auto_clock_out' => $this->autoClockOut($punches),
        ];
    }

    /**
     * Salida automática del día y, si existe, la salida real posterior al límite
     * (tiempo conservado pero no reconocido como jornada ni como extra).
     *
     * @param  Collection<int, Punch>  $punches
     * @return array{punch_uuid:string, cutoff_at:?string, registered_at:?string, real_out_at:?string, real_out_uuid:?string, after_cutoff_minutes:int}|null
     */
    private function autoClockOut(Collection $punches): ?array
    {
        $system = $punches->first(fn (Punch $p) => $p->source === PunchSource::System && $p->type === PunchType::ClockOut);
        if ($system === null) {
            return null;
        }
        $cutoff = EffectiveTimeline::punchTime($system);
        $real = $punches->filter(fn (Punch $p) => $p->source !== PunchSource::System && $p->type === PunchType::ClockOut
            && EffectiveTimeline::punchTime($p)->gt($cutoff))->first();

        return [
            'punch_uuid' => $system->uuid,
            'cutoff_at' => OperacionScope::iso($cutoff),
            'registered_at' => OperacionScope::iso($system->server_time),
            'real_out_at' => $real ? OperacionScope::iso(EffectiveTimeline::punchTime($real)) : null,
            'real_out_uuid' => $real?->uuid,
            'after_cutoff_minutes' => $real ? (int) $cutoff->diffInMinutes(EffectiveTimeline::punchTime($real)) : 0,
        ];
    }

    /**
     * Estado de marcaje web del colaborador (lo decide la política, no la UI).
     *
     * @return array{allowed:bool, next_type:string, next_label:string, policy:string, can_break:bool}
     */
    public function webPunch(Employee $employee): array
    {
        $policy = $this->policies->for($employee);
        $next = $this->timeline->nextExpected($employee->id, CarbonImmutable::now('UTC'));

        return [
            'allowed' => (bool) $policy->web_punch_allowed && $employee->employmentOn(now()) !== null,
            'next_type' => $next->value,
            'next_label' => $next->label(),
            'policy' => (string) $policy->name,
            'can_break' => $next === PunchType::ClockOut,
        ];
    }
}
