<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Workforce\ScheduleLegalValidator;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\User;
use App\Notifications\ScheduleChangeNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambios de horario programados (PDF RH pantalla 10).
 *
 * Candados:
 *  1. Sólo fechas futuras: a partir del día siguiente en la zona del centro de trabajo.
 *     Nunca retroactivos (se revalida al autorizar).
 *  2. Motivo de catálogo (`ScheduleException::REASONS`); «Otro» exige detalle.
 *  3. Autorización de RH (`schedules.approve_changes`); quien solicita no autoriza su
 *     propia solicitud. Sólo un cambio autorizado surte efecto en ScheduleResolver.
 *  4. Notificación al colaborador en la app y registro en bitácora.
 *  5. No modifica marcajes ni recalcula retardos u horas extra ya generados: como la
 *     fecha siempre es futura, no existe jornada consolidada que pueda alterarse.
 */
final class ScheduleChangeService
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly ScheduleLegalValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Primera fecha válida (mañana) en la zona horaria del colaborador.
     */
    public function earliestDate(Employee $employee): CarbonImmutable
    {
        return CarbonImmutable::now($this->resolver->timezoneFor($employee))->startOfDay()->addDay();
    }

    /**
     * @param  array{date:string, is_rest_day:bool, starts_at?:string|null, ends_at?:string|null, break_minutes?:int|null, reason_code:string, reason?:string|null}  $data
     */
    public function request(User $by, Employee $employee, array $data): ScheduleException
    {
        $date = CarbonImmutable::parse($data['date'])->toDateString();
        $this->assertFuture($employee, $date);

        if (! array_key_exists($data['reason_code'], ScheduleException::REASONS)) {
            throw ValidationException::withMessages(['reason_code' => 'Selecciona un motivo del catálogo.']);
        }
        $detail = trim((string) ($data['reason'] ?? ''));
        if ($data['reason_code'] === 'other' && mb_strlen($detail) < 5) {
            throw ValidationException::withMessages(['reason' => 'Describe el motivo (mínimo 5 caracteres).']);
        }
        if ($employee->employmentOn($date) === null) {
            throw ValidationException::withMessages(['date' => 'El colaborador no tiene relación laboral vigente en esa fecha.']);
        }

        $isRest = (bool) $data['is_rest_day'];
        $startsAt = $isRest ? null : ($data['starts_at'] ?? null);
        $endsAt = $isRest ? null : ($data['ends_at'] ?? null);
        if (! $isRest) {
            // Un día laborable exige entrada y salida; se validan contra los topes legales.
            if ($startsAt === null || $endsAt === null) {
                throw ValidationException::withMessages(['starts_at' => 'Indica la hora de entrada y de salida.']);
            }
            $this->validator->assertSingleShift($date, $startsAt, $endsAt, (int) ($data['break_minutes'] ?? 0));
        }

        return DB::transaction(function () use ($by, $employee, $date, $isRest, $startsAt, $endsAt, $data, $detail) {
            $open = ScheduleException::query()->where('employee_id', $employee->id)->whereDate('date', $date)
                ->whereIn('status', [ScheduleException::STATUS_PENDING, ScheduleException::STATUS_APPROVED])
                ->lockForUpdate()->exists();
            if ($open) {
                throw ValidationException::withMessages(['date' => 'Ya existe un cambio pendiente o autorizado para esa fecha. Anúlalo antes de registrar otro.']);
            }

            $change = ScheduleException::query()->create([
                'employee_id' => $employee->id,
                'date' => $date,
                'is_rest_day' => $isRest,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'break_minutes' => $isRest ? 0 : (int) ($data['break_minutes'] ?? 0),
                'reason_code' => $data['reason_code'],
                'reason' => $detail !== '' ? $detail : ScheduleException::REASONS[$data['reason_code']],
                'status' => ScheduleException::STATUS_PENDING,
                'created_by' => $by->id,
            ]);

            $this->audit->log('schedule_change.requested', $change, 'Cambio de horario solicitado para '.$employee->fullName().' el '.CarbonImmutable::parse($date)->format('d/m/Y'), [
                'employee_id' => $employee->id, 'date' => $date, 'reason_code' => $change->reason_code, 'is_rest_day' => $isRest,
                'starts_at' => $change->starts_at, 'ends_at' => $change->ends_at,
            ]);

            return $change;
        });
    }

    public function approve(User $by, ScheduleException $change): ScheduleException
    {
        $this->assertPending($change);
        if ($change->created_by !== null && $change->created_by === $by->id) {
            throw ValidationException::withMessages(['change' => 'No puedes autorizar un cambio que tú solicitaste.']);
        }
        $employee = $this->employee($change);
        // Al autorizar se revalida: si la fecha ya llegó, el cambio sería retroactivo.
        $this->assertFuture($employee, $change->date->toDateString());

        $change->forceFill(['status' => ScheduleException::STATUS_APPROVED, 'approved_by' => $by->id, 'approved_at' => now()])->save();
        $this->audit->log('schedule_change.approved', $change, 'Cambio de horario autorizado para '.$employee->fullName().' el '.$change->date->format('d/m/Y'), [
            'employee_id' => $employee->id, 'date' => $change->date->toDateString(), 'requested_by' => $change->created_by,
        ]);
        $this->notify($employee, $change, false);

        return $change;
    }

    public function reject(User $by, ScheduleException $change, string $reason): ScheduleException
    {
        $this->assertPending($change);
        $employee = $this->employee($change);
        $change->forceFill(['status' => ScheduleException::STATUS_REJECTED, 'rejected_by' => $by->id, 'rejected_at' => now(), 'rejection_reason' => $reason])->save();
        $this->audit->log('schedule_change.rejected', $change, 'Cambio de horario rechazado para '.$employee->fullName(), [
            'employee_id' => $employee->id, 'date' => $change->date->toDateString(), 'reason' => $reason,
        ]);

        return $change;
    }

    /**
     * Anula (baja lógica) un cambio pendiente o autorizado de fecha futura.
     */
    public function cancel(User $by, ScheduleException $change): void
    {
        $employee = $this->employee($change);
        if ($change->date->toDateString() < $this->earliestDate($employee)->toDateString()) {
            throw ValidationException::withMessages(['date' => 'No se pueden anular cambios de hoy o de días pasados.']);
        }
        $wasApproved = $change->status === ScheduleException::STATUS_APPROVED;

        $this->audit->log('schedule_change.cancelled', $change, 'Cambio de horario anulado para '.$employee->fullName(), [
            'employee_id' => $employee->id, 'date' => $change->date->toDateString(), 'status' => $change->status,
        ]);
        $change->delete();

        if ($wasApproved) {
            $this->notify($employee, $change, true);
        }
    }

    private function notify(Employee $employee, ScheduleException $change, bool $cancelled): void
    {
        $user = $employee->user;
        if ($user === null) {
            return;
        }
        $user->notify(ScheduleChangeNotice::for($change, $cancelled)->forOrganization($employee->organization_id));
        $change->forceFill(['notified_at' => now()])->saveQuietly();
    }

    private function assertFuture(Employee $employee, string $date): void
    {
        $earliest = $this->earliestDate($employee);
        if ($date < $earliest->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Sólo se permiten cambios a partir de mañana ('.$earliest->format('d/m/Y').'). Para días pasados usa una rectificación.']);
        }
    }

    private function assertPending(ScheduleException $change): void
    {
        if ($change->status !== ScheduleException::STATUS_PENDING) {
            throw ValidationException::withMessages(['change' => 'El cambio ya fue resuelto.']);
        }
    }

    private function employee(ScheduleException $change): Employee
    {
        return Employee::query()->findOrFail($change->employee_id);
    }
}
