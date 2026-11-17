<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Models\NotificationDispatch;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Events\PunchRecorded;
use App\Models\Employee;
use App\Notifications\AutoClockOutRecorded;

/**
 * Avisa al colaborador cuando el sistema cerró su jornada (AutoClockOut).
 * Idempotente por marcaje (`notification_dispatches.reference` = uuid).
 */
final class NotifyAutoClockOut
{
    public const TYPE = 'attendance.auto_clock_out';

    public function handle(PunchRecorded $event): void
    {
        $punch = $event->punch;
        if ($punch->source !== PunchSource::System || $punch->type !== PunchType::ClockOut) {
            return;
        }

        $employee = Employee::query()->withoutGlobalScope('organization')->with('user')->find($punch->employee_id);
        if ($employee?->user === null) {
            return;
        }

        $tz = $punch->timezone ?: 'UTC';
        $at = ($punch->device_time ?? $punch->server_time)->setTimezone($tz);

        $claimed = NotificationDispatch::query()->insertOrIgnore([
            'organization_id' => $punch->organization_id,
            'employee_id' => $employee->id,
            'type' => self::TYPE,
            'local_date' => $at->toDateString(),
            'reference' => (string) $punch->uuid,
            'created_at' => now(),
        ]) === 1;

        if ($claimed) {
            $employee->user->notify((new AutoClockOutRecorded((string) $punch->uuid, $at->format('H:i'), $at->toDateString()))
                ->forOrganization((int) $punch->organization_id));
        }
    }
}
