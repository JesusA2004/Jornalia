<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Incidences\IncidenceService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreIncidenceRequest;
use App\Http\Resources\V1\IncidenceResource;
use App\Http\Resources\V1\IncidenceTypeResource;
use App\Models\Incidence;
use App\Models\IncidenceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidenceController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly IncidenceService $service,
    ) {}

    /**
     * GET /incidence-types → IncidenceType[] (sólo los que el colaborador puede solicitar).
     */
    public function types(Request $request): JsonResponse
    {
        $types = IncidenceType::query()
            ->where('active', true)
            ->where('employee_requestable', true)
            ->orderBy('name')
            ->get();

        return $this->data(array_values(IncidenceTypeResource::collection($types)->resolve($request)));
    }

    /**
     * GET /incidences → Incidence[] + PaginationMeta
     */
    public function index(ListRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));

        $page = Incidence::query()
            ->with(['type', 'reviewedBy:id,name'])
            ->withExists('attachments')
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, IncidenceResource::class);
    }

    /**
     * POST /incidences → Incidence (201)
     */
    public function store(StoreIncidenceRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $incidence = $this->service->submit($user, $employee, [
            'incidence_type_id' => (int) $request->validated('incidence_type_id'),
            'starts_on' => (string) $request->validated('starts_on'),
            'ends_on' => (string) $request->validated('ends_on'),
            'reason' => (string) $request->validated('reason'),
        ], $request->file('evidence'));

        $incidence->load(['type', 'reviewedBy:id,name'])->loadExists('attachments');

        return IncidenceResource::make($incidence)->response()->setStatusCode(201);
    }
}
