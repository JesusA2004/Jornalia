<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Resources\V1\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class NotificationController extends ApiController
{
    /**
     * GET /notifications → AppNotification[] + PaginationMeta
     */
    public function index(ListRequest $request): JsonResponse
    {
        $page = $this->user($request)->notifications()->paginate($this->perPage($request));

        return $this->paginated($page, NotificationResource::class);
    }

    /**
     * POST /notifications/{id}/read → 204
     */
    public function read(Request $request, string $notification): Response
    {
        $this->user($request)->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    /**
     * POST /notifications/read-all → 204
     */
    public function readAll(Request $request): Response
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
