<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base de la API v1: envoltorio `{ data, meta? }` del contrato.
 */
abstract class ApiController extends Controller
{
    protected function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'data' => array_values($resource::collection($paginator->getCollection())->resolve()),
            'meta' => self::meta($paginator->currentPage(), $paginator->perPage(), $paginator->total()),
        ]);
    }

    /**
     * @return array{current_page:int, per_page:int, total:int, last_page:int}
     */
    protected static function meta(int $page, int $perPage, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
