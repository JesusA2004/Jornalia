<?php

namespace App\Domain\Workforce;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\DeviceService;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\EmploymentAssignment;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida laboral del personal.
 *
 *  - Alta: expediente + relación laboral + asignación inicial.
 *  - Baja: cierra la relación laboral y la asignación vigente (nunca se borra).
 *  - Reingreso: nueva relación laboral (el historial anterior se conserva).
 *  - Movimiento: cierra la asignación vigente el día anterior a la nueva
 *    vigencia y crea una nueva. Nunca modifica vigencias pasadas.
 */
final class WorkforceService
{
    /** Campos de asignación con vigencia. */
    public const ASSIGNMENT_FIELDS = ['work_center_id', 'branch_id', 'department_id', 'position_id', 'supervisor_employee_id', 'schedule_id', 'attendance_policy_id'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ScheduleLegalValidator $validator,
        private readonly CurrentOrganization $current,
        private readonly DeviceService $devices,
    ) {}

    /**
     * @param  array<string, mixed>  $person  first_name, last_name, second_last_name, employee_number, email, phone, birth_date, curp, rfc, nss, external_source, external_id
     * @param  array<string, mixed>  $employment  starts_on, seniority_starts_on, contract_type, work_modality, legal_entity_id
     * @param  array<string, mixed>  $assignment  ASSIGNMENT_FIELDS
     */
    public function hire(User $by, array $person, array $employment, array $assignment): Employee
    {
        $startsOn = CarbonImmutable::parse($employment['starts_on'])->toDateString();
        $this->assertScheduleLegal($assignment['schedule_id'] ?? null, $startsOn);

        return DB::transaction(function () use ($by, $person, $employment, $assignment, $startsOn) {
            $employee = Employee::query()->create(Arr::only($person, [
                'employee_number', 'first_name', 'last_name', 'second_last_name', 'email', 'phone',
                'birth_date', 'curp', 'rfc', 'nss', 'external_source', 'external_id', 'external_synced_at',
            ]) + ['status' => EmployeeStatus::Active]);

            $this->openEmployment($by, $employee, $employment + ['starts_on' => $startsOn], $assignment, 'Alta');

            $this->audit->log('employee.hired', $employee, 'Alta de colaborador: '.$employee->fullName(), [
                'employee_number' => $employee->employee_number,
                'starts_on' => $startsOn,
            ]);

            return $employee->refresh();
        });
    }

    public function terminate(User $by, Employee $employee, string $endsOn, string $reason): Employment
    {
        $date = CarbonImmutable::parse($endsOn)->toDateString();
        $employment = $this->currentEmployment($employee)
            ?? throw ValidationException::withMessages(['ends_on' => 'El colaborador no tiene una relación laboral activa.']);

        if (CarbonImmutable::parse($date)->lt($employment->starts_on)) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha de baja no puede ser anterior al inicio de la relación laboral ('.$employment->starts_on->format('d/m/Y').').']);
        }

        $future = $employment->assignments()->whereDate('effective_from', '>', $date)->exists();
        if ($future) {
            throw ValidationException::withMessages(['ends_on' => 'Existen movimientos programados después de la fecha de baja. Revisa el historial antes de continuar.']);
        }

        return DB::transaction(function () use ($by, $employee, $employment, $date, $reason) {
            $employment->forceFill(['ends_on' => $date, 'status' => 'terminated', 'termination_reason' => $reason])->save();

            $employment->assignments()
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
                ->get()
                ->each(fn (EmploymentAssignment $a) => $a->forceFill(['effective_to' => $date])->save());

            $employee->forceFill(['status' => EmployeeStatus::Terminated])->save();

            $this->audit->log('employee.terminated', $employee, 'Baja de colaborador: '.$employee->fullName(), [
                'employment_id' => $employment->id,
                'ends_on' => $date,
                'reason' => $reason,
                'by' => $by->id,
            ]);

            // PDF RH pantalla 09/11: el acceso a la app y el dispositivo se revocan el mismo
            // día de la baja. Si la fecha es futura, lo hace `asistencias:revocar-bajas`.
            if ($date <= $this->todayFor($employee)) {
                $this->revokeAccess($by, $employee, 'Baja del colaborador');
            }

            return $employment->refresh();
        });
    }

    /**
     * Revoca el acceso de un colaborador dado de baja: membresía de la organización
     * inactiva, dispositivos revocados y sesiones de API cerradas. No borra nada:
     * el historial, los marcajes y la bitácora se conservan. Idempotente.
     *
     * @return int dispositivos revocados
     */
    public function revokeAccess(User $by, Employee $employee, string $reason): int
    {
        if ($employee->user_id !== null) {
            DB::table('organization_user')
                ->where('organization_id', $employee->organization_id)
                ->where('user_id', $employee->user_id)
                ->update(['status' => 'inactive', 'updated_at' => now()]);
        }
        $devices = $this->devices->revokeAllForEmployee($by, $employee, $reason);

        $this->audit->log('employee.access_revoked', $employee, 'Acceso a la app revocado por baja: '.$employee->fullName(), [
            'devices_revoked' => $devices, 'reason' => $reason,
        ], $employee->organization_id);

        return $devices;
    }

    /**
     * Fecha civil de hoy en la zona de la organización del colaborador.
     */
    public function todayFor(Employee $employee): string
    {
        $timezone = $employee->organization?->timezone ?? config('app.timezone', 'UTC');

        return CarbonImmutable::now($timezone)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $employment
     * @param  array<string, mixed>  $assignment
     */
    public function rehire(User $by, Employee $employee, array $employment, array $assignment): Employment
    {
        if ($this->currentEmployment($employee) !== null) {
            throw ValidationException::withMessages(['starts_on' => 'El colaborador ya tiene una relación laboral activa.']);
        }

        $startsOn = CarbonImmutable::parse($employment['starts_on'])->toDateString();
        $last = $employee->employments()->orderByDesc('starts_on')->first();
        if ($last !== null && $last->ends_on !== null && CarbonImmutable::parse($startsOn)->lte($last->ends_on)) {
            throw ValidationException::withMessages(['starts_on' => 'El reingreso debe ser posterior a la última baja ('.$last->ends_on->format('d/m/Y').').']);
        }

        $this->assertScheduleLegal($assignment['schedule_id'] ?? null, $startsOn);

        return DB::transaction(function () use ($by, $employee, $employment, $assignment, $startsOn) {
            $new = $this->openEmployment($by, $employee, $employment + ['starts_on' => $startsOn], $assignment, 'Reingreso');
            $employee->forceFill(['status' => EmployeeStatus::Active])->save();

            if ($employee->user_id !== null) {
                DB::table('organization_user')
                    ->where('organization_id', $employee->organization_id)
                    ->where('user_id', $employee->user_id)
                    ->update(['status' => 'active', 'updated_at' => now()]);
            }

            $this->audit->log('employee.rehired', $employee, 'Reingreso de colaborador: '.$employee->fullName(), [
                'employment_id' => $new->id,
                'starts_on' => $startsOn,
            ]);

            return $new;
        });
    }

    /**
     * Movimiento de personal: cambio de sucursal, puesto, horario, supervisor o política.
     *
     * @param  array<string, mixed>  $changes  subconjunto de ASSIGNMENT_FIELDS
     */
    public function move(User $by, Employee $employee, string $effectiveFrom, array $changes, ?string $reason): EmploymentAssignment
    {
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
        $date = $from->toDateString();
        $employment = $this->currentEmployment($employee)
            ?? throw ValidationException::withMessages(['effective_from' => 'El colaborador no tiene una relación laboral activa.']);

        if ($from->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['effective_from' => 'Los movimientos no pueden aplicarse retroactivamente: elige hoy o una fecha posterior. Las vigencias pasadas se conservan sin cambios.']);
        }
        if ($employment->ends_on !== null && $from->gt($employment->ends_on)) {
            throw ValidationException::withMessages(['effective_from' => 'La vigencia es posterior al fin de la relación laboral.']);
        }

        $changes = Arr::only($changes, self::ASSIGNMENT_FIELDS);
        if (array_key_exists('supervisor_employee_id', $changes) && (int) $changes['supervisor_employee_id'] === $employee->id) {
            throw ValidationException::withMessages(['supervisor_employee_id' => 'Un colaborador no puede ser su propio supervisor.']);
        }
        if (array_key_exists('schedule_id', $changes)) {
            $this->assertScheduleLegal($changes['schedule_id'], $date);
        }

        $later = $employment->assignments()->whereDate('effective_from', '>', $date)->orderBy('effective_from')->first();
        if ($later !== null) {
            throw ValidationException::withMessages(['effective_from' => 'Ya existe un movimiento programado a partir del '.$later->effective_from->format('d/m/Y').'. Los movimientos deben registrarse en orden cronológico.']);
        }

        return DB::transaction(function () use ($by, $employee, $employment, $from, $date, $changes, $reason) {
            /** @var EmploymentAssignment|null $currentAssignment */
            $currentAssignment = $employment->assignmentOn($date);
            $base = $currentAssignment ? Arr::only($currentAssignment->getAttributes(), self::ASSIGNMENT_FIELDS) : [];
            $data = array_merge($base, $changes);

            if ($currentAssignment !== null && $currentAssignment->effective_from->toDateString() === $date) {
                // Misma fecha de inicio: no existe aún una vigencia pasada que proteger.
                if ($currentAssignment->effective_from->lt(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['effective_from' => 'No se puede modificar una vigencia pasada.']);
                }
                $before = Arr::only($currentAssignment->getAttributes(), self::ASSIGNMENT_FIELDS);
                $currentAssignment->forceFill($data + ['reason' => $reason ?? $currentAssignment->reason])->save();
                $assignment = $currentAssignment;
            } else {
                $before = $base;
                if ($currentAssignment !== null) {
                    $currentAssignment->forceFill(['effective_to' => $from->subDay()->toDateString()])->save();
                }
                $assignment = $employment->assignments()->create($data + [
                    'organization_id' => $employee->organization_id,
                    'effective_from' => $date,
                    'effective_to' => $employment->ends_on?->toDateString(),
                    'reason' => $reason,
                    'created_by' => $by->id,
                ]);
            }

            $this->audit->log('employee.moved', $employee, 'Movimiento de personal: '.$employee->fullName(), [
                'assignment_id' => $assignment->id,
                'effective_from' => $date,
                'before' => $before,
                'after' => Arr::only($assignment->getAttributes(), self::ASSIGNMENT_FIELDS),
                'reason' => $reason,
            ]);

            return $assignment;
        });
    }

    public function currentEmployment(Employee $employee): ?Employment
    {
        return $employee->employments()
            ->where('status', '!=', 'terminated')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', CarbonImmutable::today()))
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Comprueba que el horario (si existe) cumpla la jornada legal en la fecha indicada.
     */
    public function assertScheduleLegal(mixed $scheduleId, string $date, string $field = 'schedule_id'): void
    {
        if ($scheduleId === null || $scheduleId === '') {
            return;
        }
        $schedule = Schedule::query()->with('segments')->find((int) $scheduleId)
            ?? throw ValidationException::withMessages([$field => 'El horario seleccionado no existe.']);
        $this->validator->assertScheduleValidOn($schedule, $date, $field);
    }

    /**
     * @param  array<string, mixed>  $employment
     * @param  array<string, mixed>  $assignment
     */
    private function openEmployment(User $by, Employee $employee, array $employment, array $assignment, string $reason): Employment
    {
        $organizationId = $employee->organization_id ?? $this->current->require()->id;

        /** @var Employment $new */
        $new = $employee->employments()->create([
            'organization_id' => $organizationId,
            'legal_entity_id' => $employment['legal_entity_id'] ?? null,
            'starts_on' => $employment['starts_on'],
            'seniority_starts_on' => $employment['seniority_starts_on'] ?? $employment['starts_on'],
            'contract_type' => $employment['contract_type'] ?? 'indefinite',
            'work_modality' => $employment['work_modality'] ?? 'onsite',
            'status' => 'active',
        ]);

        $new->assignments()->create(Arr::only($assignment, self::ASSIGNMENT_FIELDS) + [
            'organization_id' => $organizationId,
            'effective_from' => $employment['starts_on'],
            'reason' => $reason,
            'created_by' => $by->id,
        ]);

        return $new;
    }
}
