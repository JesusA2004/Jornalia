<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Vacations\VacationService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreVacationRequest;
use App\Http\Resources\V1\VacationRequestResource;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VacationController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly VacationService $service,
    ) {}

    /**
     * GET /vacations/balance → VacationBalance
     */
    public function balance(Request $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        if ($employee->employmentOn(CarbonImmutable::today()) === null) {
            throw AttendanceException::noEmployment();
        }

        return $this->data($this->service->balance($employee));
    }

    /**
     * GET /vacations → VacationRequest[] + PaginationMeta
     */
    public function index(ListRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));

        $page = VacationRequest::query()
            ->with('reviewedBy:id,name')
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, VacationRequestResource::class);
    }

    /**
     * POST /vacations → VacationRequest (201)
     */
    public function store(StoreVacationRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);
        if ($employee->employmentOn(CarbonImmutable::today()) === null) {
            throw AttendanceException::noEmployment();
        }

        $vacation = $this->service->request(
            $user,
            $employee,
            (string) $request->validated('starts_on'),
            (string) $request->validated('ends_on'),
            $request->validated('comment'),
        );
        $vacation->load('reviewedBy:id,name');

        return VacationRequestResource::make($vacation)->response()->setStatusCode(201);
    }

    /**
     * POST /vacations/{id}/cancel → VacationRequest
     */
    public function cancel(Request $request, int $vacation): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $model = VacationRequest::query()->where('employee_id', $employee->id)->findOrFail($vacation);
        $model = $this->service->cancel($user, $model);
        $model->load('reviewedBy:id,name');

        return VacationRequestResource::make($model)->response();
    }
}
