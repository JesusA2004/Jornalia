<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Scheduling\ScheduleResolver;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\CalendarRequest;
use App\Models\Incidence;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;

final class CalendarController extends ApiController
{
    private const INCIDENCE_KINDS = ['leave' => 'leave', 'disability' => 'disability', 'vacation' => 'vacation'];

    public function __construct(
        private readonly ApiContext $context,
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * GET /calendar?month=YYYY-MM → CalendarResponse
     */
    public function __invoke(CalendarRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $tz = $this->schedules->timezoneFor($employee);
        $month = (string) $request->validated('month', CarbonImmutable::now($tz)->format('Y-m'));

        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $end = $start->endOfMonth()->startOfDay();

        $vacations = VacationRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
            ->get();

        $incidences = Incidence::query()->with('type')
            ->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
            ->get();

        $entries = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $date = $day->toDateString();
            $assignment = $employee->assignmentOn($date);

            $holiday = $this->schedules->holidayOn($employee, $date, $assignment);
            if ($holiday !== null) {
                $entries[] = $this->entry($date, 'holiday', $holiday->name);
            }

            foreach ($vacations as $v) {
                if ($v->starts_on->toDateString() <= $date && $v->ends_on->toDateString() >= $date) {
                    $entries[] = $this->entry($date, 'vacation', $v->status === ApprovalStatus::Pending ? 'Vacaciones (pendiente)' : 'Vacaciones');
                }
            }

            foreach ($incidences as $i) {
                if ($i->starts_on->toDateString() <= $date && $i->ends_on->toDateString() >= $date) {
                    $label = $i->type->name.($i->status === ApprovalStatus::Pending ? ' (pendiente)' : '');
                    $entries[] = $this->entry($date, self::INCIDENCE_KINDS[$i->type->category] ?? 'incidence', $label);
                }
            }

            if ($assignment === null) {
                continue;
            }

            $shift = $this->schedules->shiftFor($employee, $date, $assignment);
            if ($shift !== null) {
                $entries[] = $this->entry($date, 'shift', $shift->scheduleName, $shift->toArray()['starts_at'], $shift->toArray()['ends_at']);
            } elseif ($assignment->schedule_id !== null && $holiday === null) {
                $entries[] = $this->entry($date, 'rest_day', 'Descanso');
            }
        }

        return $this->data(['month' => $month, 'entries' => $entries]);
    }

    /**
     * @return array{date:string, kind:string, label:string, starts_at:?string, ends_at:?string}
     */
    private function entry(string $date, string $kind, string $label, ?string $startsAt = null, ?string $endsAt = null): array
    {
        return ['date' => $date, 'kind' => $kind, 'label' => $label, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
    }
}
