<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\RectificationService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\SignRequest;
use App\Http\Requests\Api\V1\StoreRectificationRequest;
use App\Http\Resources\V1\RectificationResource;
use App\Models\Attachment;
use App\Models\RectificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rectificaciones del colaborador: solicitar (con evidencia), firmar o no aceptar.
 */
final class RectificationController extends ApiController
{
    private const RELATIONS = ['reviewedBy:id,name', 'attachments', 'signature'];

    private const STAGES = ['por_autorizar', 'por_firmar', 'por_validar', 'aplicada', 'rechazada'];

    public function __construct(
        private readonly ApiContext $context,
        private readonly RectificationService $service,
    ) {}

    /**
     * GET /rectifications → Rectification[] + PaginationMeta (filtro opcional `stage`).
     */
    public function index(ListRequest $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));
        $stage = (string) $request->query('stage', '');

        $page = RectificationRequest::query()
            ->with(self::RELATIONS)
            ->where('employee_id', $employee->id)
            ->when(in_array($stage, self::STAGES, true), fn ($q) => $q->where('stage', $stage))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, RectificationResource::class);
    }

    /**
     * GET /rectifications/{id} → Rectification (sólo las propias).
     */
    public function show(Request $request, int $rectification): JsonResponse
    {
        return $this->present($this->own($request, $rectification));
    }

    /**
     * GET /files/evidence/{attachment} (URL firmada temporal, sin Bearer) → archivo de evidencia.
     * Sólo se emite dentro de la respuesta de las rectificaciones propias.
     */
    public function evidence(int $attachment): StreamedResponse
    {
        $file = Attachment::query()->withoutGlobalScopes()
            ->where('attachable_type', (new RectificationRequest)->getMorphClass())
            ->findOrFail($attachment);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    /**
     * POST /rectifications (multipart, evidence[] obligatoria) → Rectification (201)
     */
    public function store(StoreRectificationRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $data = $request->safe()->except('evidence') + ['punch_uuid' => null, 'proposed_type' => null, 'proposed_time' => null];
        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->file('evidence', []), fn ($f) => $f instanceof UploadedFile));
        $rectification = $this->service->submit($user, $employee, $data, $files, 'employee');

        return $this->present($rectification, 201);
    }

    /**
     * POST /rectifications/{id}/sign {method, pin?, device_signature?} → Rectification
     */
    public function sign(SignRequest $request, int $rectification): JsonResponse
    {
        $user = $this->user($request);
        $r = $this->own($request, $rectification);
        $device = $this->context->deviceByUuid($user, ApiContext::tokenDeviceUuid($request));

        $signed = $this->service->sign(
            $user, $r, (string) $request->validated('method'), $request->validated('pin'),
            $device, $request, $request->validated('device_signature'),
        );

        return $this->present($signed);
    }

    /**
     * POST /rectifications/{id}/decline {reason} → Rectification
     */
    public function decline(Request $request, int $rectification): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $r = $this->own($request, $rectification);

        return $this->present($this->service->decline($this->user($request), $r, $data['reason']));
    }

    /**
     * IDOR: sólo las solicitudes del propio colaborador (404 en cualquier otro caso).
     */
    private function own(Request $request, int $id): RectificationRequest
    {
        $employee = $this->context->employee($this->user($request));

        return RectificationRequest::query()->where('employee_id', $employee->id)->findOrFail($id);
    }

    private function present(RectificationRequest $r, int $status = 200): JsonResponse
    {
        $r->refresh()->load(self::RELATIONS);

        return RectificationResource::make($r)->response()->setStatusCode($status);
    }
}
