<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Scheduling\ScheduleResolver;
use App\Events\PunchRecorded;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\PunchReceipt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acuse obligatorio de cada marcaje al colaborador (bandeja de la app y push si
 * está configurado). No existe ajuste para desactivarlo. Una falla al avisar
 * nunca afecta el marcaje ya registrado.
 */
final class SendPunchReceipt
{
    public function __construct(private readonly ScheduleResolver $schedules) {}

    public function handle(PunchRecorded $event): void
    {
        $punch = $event->punch;
        try {
            $employee = Employee::query()->withoutGlobalScopes()->find($punch->employee_id);
            $user = $employee?->user_id ? User::query()->find($employee->user_id) : null;
            if ($employee === null || $user === null) {
                return;
            }

            $user->notify(PunchReceipt::for($punch, $this->schedules->timezoneFor($employee)));
        } catch (Throwable $e) {
            Log::error('punch_receipt_failed', ['punch_id' => $punch->id, 'error' => $e->getMessage()]);
        }
    }
}
