<?php

namespace App\Domain\Vacations;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Audit\AuditLogger;
use App\Domain\Legal\LegalFramework;
use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Jobs\RecomputeWorkday;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\User;
use App\Models\VacationAdjustment;
use App\Models\VacationPolicy;
use App\Models\VacationRequest;
use App\Notifications\RequestStatusChanged;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Vacaciones (LFT arts. 76–81).
 *
 *  - El derecho del año de servicio N nace al cumplir N años (art. 76) y debe
 *    disfrutarse dentro de los seis meses siguientes (art. 81).
 *  - Días = mínimo legal vigente + prestación superior de la política (nunca menor).
 *  - Prima vacacional ≥ mínimo legal (art. 80).
 *  - Los días solicitados se cuentan sobre días laborables programados del
 *    colaborador (excluye descansos y festivos obligatorios).
 */
final class VacationService
{
    public function __construct(
        private readonly LegalFramework $law,
        private readonly ScheduleResolver $schedules,
        private readonly ApprovalWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{service_year:int, period_starts_on:string, period_ends_on:string, legal_days:int, extra_days:int, entitled_days:int, used_days:int, pending_days:int, available_days:int, vacation_premium_percent:string, rule_source:string}
     */
    public function balance(Employee $employee, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today();
        $employment = $employee->employmentOn($on) ?? throw ValidationException::withMessages(['employee' => 'Sin relación laboral vigente.']);
        $seniority = CarbonImmutable::parse($employment->seniority_starts_on);
        $years = (int) $seniority->diffInYears($on);

        $periodStart = $seniority->addYears($years);
        $periodEnd = $periodStart->addYear()->subDay();

        $legal = $this->law->legalVacationDays($years, $periodStart);
        $policy = $this->policy($periodStart);
        $extra = $years > 0 ? $this->extraDays($policy, $years) : 0;
        $premium = $policy ? max((float) $policy->premium_percent, (float) $this->law->for($on)->vacationPremiumMinPercent) : (float) $this->law->for($on)->vacationPremiumMinPercent;

        $base = VacationRequest::query()->where('employment_id', $employment->id)->where('service_year', $years);
        $used = (int) (clone $base)->where('status', ApprovalStatus::Approved)->sum('days');
        $pending = (int) (clone $base)->where('status', ApprovalStatus::Pending)->sum('days');
        $adjust = (int) VacationAdjustment::query()->where('employment_id', $employment->id)->where('service_year', $years)->sum('days');

        $entitled = $legal + $extra + $adjust;

        return [
            'service_year' => $years,
            'period_starts_on' => $periodStart->toDateString(),
            'period_ends_on' => $periodEnd->toDateString(),
            'legal_days' => $legal,
            'extra_days' => $extra + $adjust,
            'entitled_days' => $entitled,
            'used_days' => $used,
            'pending_days' => $pending,
            'available_days' => max(0, $entitled - $used - $pending),
            'vacation_premium_percent' => number_format($premium, 2, '.', ''),
            'rule_source' => 'LFT art. 76 (DOF 27-12-2022)'.($policy && $extra > 0 ? ' + política «'.$policy->name.'»' : ''),
        ];
    }

    /**
     * Días laborables programados entre dos fechas (inclusive).
     */
    public function countWorkingDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $assignment = $employee->assignmentOn($date);
            if ($assignment?->schedule_id === null) {
                // Sin horario asignado: se cuentan días de lunes a sábado (descanso semanal en domingo).
                $working = ! $day->isSunday();
            } else {
                $working = $this->schedules->shiftFor($employee, $date, $assignment) !== null;
            }
            if ($working && $this->schedules->holidayOn($employee, $date, $assignment) === null) {
                $days++;
            }
        }

        return $days;
    }

    public function request(User $requester, Employee $employee, string $startsOn, string $endsOn, ?string $comment): VacationRequest
    {
        $from = CarbonImmutable::parse($startsOn);
        $to = CarbonImmutable::parse($endsOn);
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha final debe ser posterior o igual a la inicial.']);
        }

        $balance = $this->balance($employee);
        if ($balance['service_year'] < 1) {
            throw ValidationException::withMessages(['starts_on' => 'El derecho a vacaciones inicia al cumplir el primer año de servicio (LFT art. 76).']);
        }

        $days = $this->countWorkingDays($employee, $from, $to);
        if ($days === 0) {
            throw ValidationException::withMessages(['starts_on' => 'El periodo no contiene días laborables.']);
        }
        if ($days > $balance['available_days']) {
            throw ValidationException::withMessages(['ends_on' => "Solicitas {$days} días y tienes {$balance['available_days']} disponibles."]);
        }

        $overlap = VacationRequest::query()->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Ya existe una solicitud en esas fechas.']);
        }

        /** @var Employment $employment */
        $employment = $employee->employmentOn(CarbonImmutable::today());
        $req = VacationRequest::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'employment_id' => $employment->id,
            'service_year' => $balance['service_year'],
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'days' => $days,
            'comment' => $comment,
            'status' => ApprovalStatus::Pending,
            'requested_by' => $requester->id,
        ]);
        $this->audit->log('vacation.requested', $req, "Solicitud de vacaciones por {$days} días");

        return $req;
    }

    public function decide(User $reviewer, VacationRequest $request, bool $approve, ?string $comment): VacationRequest
    {
        $final = $this->workflow->decide($reviewer, $request, 'vacation', $approve, $comment);
        $request->refresh();
        if ($final) {
            if ($request->status === ApprovalStatus::Approved) {
                foreach (CarbonPeriod::create($request->starts_on, $request->ends_on) as $day) {
                    RecomputeWorkday::dispatch($request->organization_id, $request->employee_id, $day->toDateString())->afterCommit();
                }
            }
            $request->employee?->user?->notify(new RequestStatusChanged('vacation', $request->id, $request->status, $request->review_comment));
        }

        return $request;
    }

    public function cancel(User $user, VacationRequest $request): VacationRequest
    {
        $own = $request->employee?->user_id === $user->id;
        if ($request->status !== ApprovalStatus::Pending && ! ($request->status === ApprovalStatus::Approved && $request->starts_on->isFuture())) {
            throw ValidationException::withMessages(['status' => 'Sólo se pueden cancelar solicitudes pendientes o aprobadas que aún no inician.']);
        }
        if (! $own && ! $user->can('vacations.approve')) {
            throw ValidationException::withMessages(['status' => 'No puedes cancelar esta solicitud.']);
        }
        $request->update(['status' => ApprovalStatus::Cancelled]);
        $this->audit->log('vacation.cancelled', $request, 'Solicitud de vacaciones cancelada');

        return $request;
    }

    /**
     * Valida que una política no establezca prestaciones inferiores al mínimo legal.
     *
     * @param  list<array{from_year:int, to_year:int|null, days:int}>  $extraDays
     */
    public function assertPolicyValid(array $extraDays, string $premiumPercent): void
    {
        foreach ($extraDays as $row) {
            if ((int) $row['days'] < 0) {
                throw ValidationException::withMessages(['extra_days' => 'Las prestaciones adicionales no pueden ser negativas: la ley fija el mínimo.']);
            }
        }
        if ((float) $premiumPercent < (float) $this->law->for(CarbonImmutable::today())->vacationPremiumMinPercent) {
            throw ValidationException::withMessages(['premium_percent' => 'La prima vacacional no puede ser inferior al mínimo legal (25 %).']);
        }
    }

    private function policy(CarbonImmutable $on): ?VacationPolicy
    {
        return VacationPolicy::query()
            ->whereDate('valid_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on))
            ->orderByDesc('is_default')->orderByDesc('valid_from')
            ->first();
    }

    private function extraDays(?VacationPolicy $policy, int $year): int
    {
        foreach ((array) ($policy->extra_days ?? []) as $row) {
            if ($year >= (int) $row['from_year'] && ($row['to_year'] === null || $year <= (int) $row['to_year'])) {
                return max(0, (int) $row['days']);
            }
        }

        return 0;
    }
}
