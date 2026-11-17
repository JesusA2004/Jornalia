<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Location\LocationService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\LocationBatchRequest;
use App\Http\Resources\V1\LocationPointResource;
use App\Models\LocationPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ubicación de trabajo en tiempo real (sólo dentro de la ventana de jornada).
 */
final class LocationController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly LocationService $locations,
    ) {}

    /**
     * POST /location/points → LocationBatchResponse (200)
     */
    public function store(LocationBatchRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);
        $device = $this->context->approvedCurrentDevice($request);

        return $this->data($this->locations->ingest($user, $employee, $device, $request->points()));
    }

    /**
     * GET /location/state → LocationState
     */
    public function state(Request $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));

        $last = LocationPoint::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        return $this->data([
            'last' => $last ? (new LocationPointResource($last))->resolve($request) : null,
            'settings' => $this->locations->settings($employee),
        ]);
    }
}
