<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\PunchRecorder;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Controllers\Api\V1\Support\PunchPayload;
use App\Http\Controllers\Api\V1\Support\PunchQuery;
use App\Http\Controllers\Api\V1\Support\WorkdayPresenter;
use App\Http\Requests\Api\V1\StorePunchRequest;
use App\Http\Requests\Api\V1\SyncPunchesRequest;
use App\Http\Resources\V1\PunchResource;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Registro de marcajes (append-only). No existen rutas de modificación ni borrado.
 */
final class PunchController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly PunchRecorder $recorder,
        private readonly WorkdayPresenter $presenter,
    ) {}

    /**
     * POST /attendance/punches → PunchReceipt (201; 200 si duplicado idempotente).
     */
    public function store(StorePunchRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $result = $this->record($user, $employee, $request->validated(), $request);

        return $this->data($this->receipt($employee, $result['punch'], $request), $result['duplicate'] ? 200 : 201);
    }

    /**
     * GET /attendance/punches/{uuid} → PunchReceipt
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $punch = PunchQuery::forEmployee($employee->id)->where('attendance_punches.uuid', $uuid)->firstOrFail();

        return $this->data($this->receipt($employee, $punch, $request));
    }

    /**
     * POST /sync/punches → SyncResponse. Cada elemento se procesa por separado:
     * un rechazo no aborta el lote.
     */
    public function sync(SyncPunchesRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $results = [];
        foreach ((array) $request->validated('punches') as $item) {
            $item = (array) $item;
            $clientUuid = is_string($item['client_uuid'] ?? null) ? $item['client_uuid'] : '';

            try {
                $data = PunchPayload::validate($item);
                $result = $this->record($user, $employee, $data, $request);
                $results[] = [
                    'client_uuid' => $clientUuid,
                    'result' => $result['duplicate'] ? 'duplicate' : 'created',
                    'punch' => (new PunchResource(PunchQuery::fresh($result['punch'])))->resolve($request),
                ];
            } catch (AttendanceException $e) {
                $results[] = ['client_uuid' => $clientUuid, 'result' => 'rejected', 'error' => ['message' => $e->getMessage(), 'code' => $e->errorCode]];
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $clientUuid, 'result' => 'rejected', 'error' => ['message' => $e->getMessage(), 'code' => 'validation_failed', 'errors' => $e->errors()]];
            }
        }

        return $this->data(['results' => $results]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{punch: Punch, duplicate: bool}
     */
    private function record(User $user, Employee $employee, array $data, Request $request): array
    {
        $input = PunchPayload::toInput($data, $request);
        $device = $this->context->deviceByUuid($user, $input->deviceUuid);

        return $this->recorder->record($user, $employee, $input, $device);
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(Employee $employee, Punch $punch, Request $request): array
    {
        $punch = $punch->getAttribute('adjustments_count') === null ? PunchQuery::fresh($punch) : $punch;

        return [
            'punch' => (new PunchResource($punch))->resolve($request),
            'receipt_code' => PunchResource::receiptCode($punch),
            'workday' => $this->presenter->workday($employee, $punch->local_date->toDateString()),
        ];
    }
}
