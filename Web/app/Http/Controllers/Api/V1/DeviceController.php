<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController extends ApiController
{
    /**
     * GET /devices → Device[] (sólo los del usuario en la organización activa).
     */
    public function index(Request $request): JsonResponse
    {
        $devices = Device::query()
            ->where('user_id', $this->user($request)->id)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        return $this->data(array_values(DeviceResource::collection($devices)->resolve($request)));
    }
}
