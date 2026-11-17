<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\ApiFormat;
use Illuminate\Http\JsonResponse;

final class HealthController extends ApiController
{
    /**
     * GET /health (público) → { status: 'ok', time }
     */
    public function __invoke(): JsonResponse
    {
        return $this->data(['status' => 'ok', 'time' => ApiFormat::dateTime(now())]);
    }
}
