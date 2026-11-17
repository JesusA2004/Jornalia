<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\RectificationService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreRectificationRequest;
use App\Http\Resources\V1\RectificationResource;
use App\Models\RectificationRequest;
use Illuminate\Http\JsonResponse;

final class RectificationController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly RectificationService $service,
    ) {}

    /**
     * GET /rectifications → Rectification[] + PaginationMeta
     */
    public function index(ListRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));

        $page = RectificationRequest::query()
            ->with('reviewedBy:id,name')
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, RectificationResource::class);
    }

    /**
     * POST /rectifications → Rectification (201)
     */
    public function store(StoreRectificationRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $data = $request->safe()->except('evidence') + ['punch_uuid' => null, 'proposed_type' => null, 'proposed_time' => null];
        $rectification = $this->service->submit($user, $employee, $data, $request->file('evidence'));
        $rectification->load('reviewedBy:id,name');

        return RectificationResource::make($rectification)->response()->setStatusCode(201);
    }
}
