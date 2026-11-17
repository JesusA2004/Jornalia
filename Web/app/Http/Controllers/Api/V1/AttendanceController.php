<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Security\DeviceService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Controllers\Api\V1\Support\PunchQuery;
use App\Http\Controllers\Api\V1\Support\WorkdayPresenter;
use App\Http\Requests\Api\V1\HistoryRequest;
use App\Http\Resources\V1\ApiFormat;
use App\Http\Resources\V1\RectificationResource;
use App\Models\Punch;
use App\Models\RectificationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AttendanceController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly WorkdayPresenter $presenter,
        private readonly DeviceService $devices,
    ) {}

    /**
     * GET /attendance/today → TodayResponse
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $today = $this->presenter->today($employee);

        return $this->data([
            'workday' => $this->presenter->workday($employee, $today),
            'policy' => $this->presenter->policy($employee),
            'week' => $this->presenter->week($employee, $today),
            'server_time' => ApiFormat::dateTime(now()),
        ]);
    }

    /**
     * GET /attendance/policy → AttendancePolicy
     */
    public function policy(Request $request): JsonResponse
    {
        return $this->data($this->presenter->policy($this->context->employee($this->user($request))));
    }

    /**
     * POST /attendance/challenge → ChallengeResponse (nonce de un solo uso).
     */
    public function challenge(Request $request): JsonResponse
    {
        $this->context->employee($this->user($request));
        $challenge = $this->devices->challenge($this->context->approvedCurrentDevice($request));

        return $this->data([
            'nonce' => $challenge->nonce,
            'expires_at' => ApiFormat::dateTime($challenge->expires_at),
        ]);
    }

    /**
     * GET /attendance/history → HistoryDay[] + PaginationMeta (días en orden descendente).
     */
    public function history(HistoryRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $today = $this->presenter->today($employee);

        $to = CarbonImmutable::parse($request->validated('to', $today));
        $from = CarbonImmutable::parse($request->validated('from', $to->subDays(29)->toDateString()));
        if ($from->diffInDays($to) > HistoryRequest::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => 'El rango máximo es de un año.']);
        }

        $perPage = max(1, min(31, (int) $request->validated('per_page', 15)));
        $page = max(1, (int) $request->validated('page', 1));
        $total = (int) $from->diffInDays($to) + 1;

        $dates = [];
        for ($i = ($page - 1) * $perPage; $i < min($total, $page * $perPage); $i++) {
            $dates[] = $to->subDays($i)->toDateString();
        }

        $summaries = $this->presenter->summaries($employee, $dates, $today);

        $punches = $dates === [] ? collect() : PunchQuery::forEmployee($employee->id)
            ->whereIn('local_date', $dates)
            ->orderBy('server_time')
            ->get()
            ->groupBy(fn (Punch $p) => $p->local_date->toDateString());

        $rectifications = $dates === [] ? collect() : RectificationRequest::query()
            ->with('reviewedBy:id,name')
            ->where('employee_id', $employee->id)
            ->whereIn('date', $dates)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (RectificationRequest $r) => $r->date->toDateString());

        $next = in_array($today, $dates, true) ? $this->presenter->nextExpected($employee) : null;

        $days = [];
        foreach ($dates as $date) {
            $s = $summaries[$date];
            $days[] = [
                'date' => $date,
                'status' => WorkdayPresenter::contractStatus($s->status, $date === $today, $next),
                'worked_minutes' => (int) $s->worked_minutes,
                'late_minutes' => (int) $s->late_minutes,
                'punches' => $this->presenter->punches($punches->get($date, collect())),
                'rectifications' => array_values(RectificationResource::collection($rectifications->get($date, collect()))->resolve($request)),
            ];
        }

        return response()->json([
            'data' => $days,
            'meta' => self::meta($page, $perPage, $total),
        ]);
    }
}
